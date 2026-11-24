<?php
declare(strict_types=1);

namespace wise\wiki\eval;

use wise\wiki\compile\OutputValidator;
use wise\wiki\exception\WikiException;
use wise\wiki\service\WikiSearchService;

/**
 * 检索评测（`wiki:eval`）
 *
 * 评测对象是**检索链路**（不是 LLM 生成质量）：
 *   - `hit_rate`：期望词出现在返回结果里（页面或碎片）的比例；
 *   - `page_hit_rate`：命中来自「页面结论」而非「源碎片」的比例 —— 这是 wiki 的核心价值指标；
 *   - `mrr`：首个命中的倒数排名（衡量排序质量）；
 *   - `citation_coverage`：命中页面正文的段落引用覆盖率均值（衡量结论可追溯性）；
 *   - `avg_latency_ms`：平均检索耗时。
 *
 * 评测集格式（JSON）：
 * ```json
 * {"cases":[{"query":"A100 功率","expect":["250W"],"space":["product_docs"],"top_k":5}]}
 * ```
 * 也接受裸数组。`expect` 为空表示只测延迟（恒记为命中）。
 *
 * 纪律：评测集与 prompt 模板必须同版本演进 —— 改模板要升 `compile.prompt_version` 并重跑本命令，
 * 否则「这版结论变差了」永远无据可查。
 */
class Evaluator
{
    public function __construct(
        protected WikiSearchService $search,
        protected OutputValidator $validator
    ) {
    }

    /**
     * 默认评测集路径
     */
    public static function defaultPath(): string
    {
        $custom = (string) (function_exists('config') ? config('wise-rag.wiki.eval.default_file', '') : '');
        if ($custom !== '') {
            return $custom;
        }

        $runtime = function_exists('app') ? app()->getRuntimePath() : sys_get_temp_dir() . DIRECTORY_SEPARATOR;

        return rtrim($runtime, '\\/') . DIRECTORY_SEPARATOR . 'rag.wiki' . DIRECTORY_SEPARATOR . 'eval.json';
    }

    /**
     * 读取评测集文件
     *
     * @return array<int, array<string, mixed>>
     * @throws WikiException
     */
    public function loadFile(string $path): array
    {
        if (!is_file($path)) {
            throw WikiException::invalidArgument('评测集文件不存在：' . $path);
        }

        $raw = (string) file_get_contents($path);
        if (trim($raw) === '') {
            throw WikiException::invalidArgument('评测集文件为空：' . $path);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw WikiException::invalidArgument('评测集不是合法 JSON：' . $path);
        }

        $cases = $decoded['cases'] ?? $decoded;
        if (!is_array($cases)) {
            throw WikiException::invalidArgument('评测集缺少 cases 数组：' . $path);
        }

        return array_values(array_filter($cases, static fn ($c): bool => is_array($c)));
    }

    /**
     * 执行评测
     *
     * @param array<int, array<string, mixed>> $cases
     * @param array<string, mixed>             $options top_k / strategy / min_citation_coverage
     * @return array<string, mixed>
     */
    public function run(array $cases, array $options = []): array
    {
        $defaultTopK = (int) ($options['top_k'] ?? config('wise-rag.wiki.eval.top_k', 5));
        $strategy    = isset($options['strategy']) && (string) $options['strategy'] !== ''
            ? (string) $options['strategy']
            : null;
        $minCoverage = (float) ($options['min_citation_coverage']
            ?? config('wise-rag.wiki.eval.min_citation_coverage', 0));

        $details   = [];
        $total     = 0;
        $hits      = 0;
        $pageHits  = 0;
        $reciprocal = 0.0;
        $coverages = [];
        $latencies = [];

        foreach ($cases as $case) {
            $query = trim((string) ($case['query'] ?? ''));
            if ($query === '') {
                continue;
            }

            $total++;
            $expect = array_values(array_filter(
                array_map(static fn ($e): string => trim((string) $e), (array) ($case['expect'] ?? [])),
                static fn (string $e): bool => $e !== ''
            ));
            $spaces = (array) ($case['space'] ?? []);
            $topK   = (int) ($case['top_k'] ?? $defaultTopK);
            $topK   = min(20, max(1, $topK));

            $started = microtime(true);

            try {
                $result = $this->search->search($query, $spaces, $topK, $strategy);
            } catch (\Exception $e) {
                $details[] = [
                    'query'  => $query,
                    'hit'    => false,
                    'rank'   => 0,
                    'source' => 'error',
                    'error'  => mb_substr($e->getMessage(), 0, 200),
                ];

                continue;
            }

            $latency      = (int) round((microtime(true) - $started) * 1000);
            $latencies[]  = $latency;

            $rank       = 0;
            $hitRank    = 0;
            $hitSource  = 'none';
            $coverage   = null;
            $pageIdHit  = 0;

            $bodies = $this->search->bodies(array_column((array) $result['pages'], 'page_id'));

            foreach ((array) $result['pages'] as $page) {
                $rank++;
                $pageId = (int) ($page['page_id'] ?? 0);
                $body   = (string) ($bodies[$pageId] ?? '');

                if ($hitRank === 0 && $this->matches(
                    implode(' ', [(string) ($page['title'] ?? ''), (string) ($page['slug'] ?? ''), (string) ($page['summary'] ?? ''), $body]),
                    $expect
                )) {
                    $hitRank   = $rank;
                    $hitSource = 'page';
                    $pageIdHit = $pageId;
                    $coverage  = $body !== '' ? $this->validator->citationCoverage($body) : null;
                }
            }

            foreach ((array) $result['chunks'] as $chunk) {
                $rank++;
                if ($hitRank === 0 && $this->matches((string) ($chunk['content'] ?? ''), $expect)) {
                    $hitRank   = $rank;
                    $hitSource = 'chunk';
                }
            }

            $qualified = $hitRank > 0;

            if ($qualified && $minCoverage > 0 && $hitSource === 'page') {
                if ($coverage === null || $coverage < $minCoverage) {
                    $qualified = false;
                }
            }

            if ($qualified) {
                $hits++;
                $reciprocal += 1 / max(1, $hitRank);
                if ($hitSource === 'page') {
                    $pageHits++;
                }
            }

            if ($coverage !== null) {
                $coverages[] = $coverage;
            }

            $details[] = [
                'query'     => $query,
                'hit'       => $qualified,
                'rank'      => $hitRank,
                'source'    => $hitSource,
                'page_id'   => $pageIdHit,
                'coverage'  => $coverage === null ? null : round($coverage, 4),
                'fallback'  => (bool) ($result['fallback'] ?? false),
                'count'     => (int) ($result['count'] ?? 0),
                'latency_ms'=> $latency,
                'expect'    => $expect,
            ];
        }

        return [
            'cases'             => $total,
            'hit'               => $hits,
            'hit_rate'          => $total > 0 ? round($hits / $total, 4) : 0.0,
            'page_hit_rate'     => $total > 0 ? round($pageHits / $total, 4) : 0.0,
            'mrr'               => $total > 0 ? round($reciprocal / $total, 4) : 0.0,
            'citation_coverage' => $coverages === [] ? 0.0 : round(array_sum($coverages) / count($coverages), 4),
            'avg_latency_ms'    => $latencies === [] ? 0 : (int) round(array_sum($latencies) / count($latencies)),
            'strategy'          => $strategy ?? (string) config('wise-rag.wiki.search.strategy', 'page_first'),
            'min_citation_coverage' => $minCoverage,
            'details'           => $details,
        ];
    }

    /**
     * @param string[] $expect
     */
    protected function matches(string $haystack, array $expect): bool
    {
        if ($expect === []) {
            return true;
        }

        $haystack = mb_strtolower($haystack);
        foreach ($expect as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
