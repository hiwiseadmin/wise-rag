<?php
declare(strict_types=1);

namespace wise\rag\eval;

use wise\rag\dto\RetrievalQuery;
use wise\rag\dto\ScoredChunk;
use wise\rag\exception\RagException;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;

/**
 * 检索评测器
 *
 * 用「查询 + 期望关键词」构成的评测集跑检索，输出可回归的指标：
 *   - `hit_rate`：命中率（top-k 内至少命中一个期望关键词）
 *   - `recall`：期望关键词召回率
 *   - `mrr`：首个命中的倒数排名
 *   - `avg_latency_ms`：平均耗时
 *
 * 评测集格式（JSON，支持数组或 `{"cases":[...]}`）：
 * ```json
 * [{"query": "A100 功率是多少", "expect": ["250W"], "kb": ["product_docs"], "top_k": 5}]
 * ```
 */
class Evaluator
{
    public function __construct(
        protected RetrievalService $retrieval,
        protected KnowledgeBaseService $kbService
    ) {
    }

    /**
     * 读取评测集文件
     *
     * @return array<int, array<string, mixed>>
     * @throws RagException
     */
    public static function loadCases(string $file): array
    {
        if (!is_file($file)) {
            throw new RagException("评测集文件不存在：{$file}");
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RagException("评测集读取失败：{$file}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RagException("评测集不是合法 JSON：{$file}");
        }

        $cases = isset($decoded['cases']) && is_array($decoded['cases']) ? $decoded['cases'] : $decoded;

        return array_values(array_filter($cases, static fn ($c): bool => is_array($c)));
    }

    /**
     * 执行评测
     *
     * @param array<int, array<string, mixed>> $cases
     * @return array{summary: array<string, mixed>, cases: array<int, array<string, mixed>>}
     */
    public function run(array $cases, int $defaultTopK = 5): array
    {
        $rows        = [];
        $sumHit      = 0.0;
        $sumRecall   = 0.0;
        $sumMrr      = 0.0;
        $sumLatency  = 0;
        $evaluated   = 0;

        foreach ($cases as $case) {
            $query = trim((string) ($case['query'] ?? ''));
            if ($query === '') {
                continue;
            }

            $expect = $this->expectOf($case);
            $topK   = max(1, (int) ($case['top_k'] ?? $defaultTopK));
            $kbIds  = $this->resolveKbIds($case);

            $started = microtime(true);
            $error   = '';
            try {
                $hits = $this->retrieval->retrieve(new RetrievalQuery($query, null, $kbIds, $topK));
            } catch (\Exception $e) {
                $hits  = [];
                $error = $e->getMessage();
            }
            $latency = (int) round((microtime(true) - $started) * 1000);

            $ranks = [];
            foreach ($expect as $keyword) {
                foreach ($hits as $index => $hit) {
                    if (mb_stripos($hit->content, $keyword) !== false) {
                        $ranks[$keyword] = $index + 1;
                        break;
                    }
                }
            }

            $matched = count($ranks);
            $hit     = $expect === [] ? ($hits !== [] ? 1 : 0) : ($matched > 0 ? 1 : 0);
            $recall  = $expect === [] ? (float) $hit : $matched / max(1, count($expect));
            $mrr     = $ranks === [] ? 0.0 : 1.0 / min($ranks);

            $sumHit     += $hit;
            $sumRecall  += $recall;
            $sumMrr     += $mrr;
            $sumLatency += $latency;
            $evaluated++;

            $rows[] = [
                'query'      => $query,
                'expect'     => $expect,
                'matched'    => $matched,
                'hit'        => $hit,
                'recall'     => round($recall, 4),
                'mrr'        => round($mrr, 4),
                'hit_count'  => count($hits),
                'latency_ms' => $latency,
                'top1'       => $this->snippet($hits[0] ?? null),
                'error'      => $error,
            ];
        }

        $divisor = max(1, $evaluated);

        return [
            'summary' => [
                'cases'          => $evaluated,
                'hit_rate'       => round($sumHit / $divisor, 4),
                'avg_recall'     => round($sumRecall / $divisor, 4),
                'mrr'            => round($sumMrr / $divisor, 4),
                'avg_latency_ms' => (int) round($sumLatency / $divisor),
            ],
            'cases' => $rows,
        ];
    }

    /**
     * 期望关键词
     *
     * @param array<string, mixed> $case
     * @return string[]
     */
    protected function expectOf(array $case): array
    {
        $expect = $case['expect'] ?? $case['expect_any'] ?? [];
        if (is_string($expect)) {
            $expect = [$expect];
        }

        $out = [];
        foreach ((array) $expect as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     * @return int[]
     */
    protected function resolveKbIds(array $case): array
    {
        $keys = $case['kb'] ?? [];
        if (is_string($keys)) {
            $keys = [$keys];
        }

        $ids = [];
        foreach ((array) $keys as $key) {
            $kb = $this->kbService->findByKey((string) $key);
            if ($kb !== null) {
                $ids[] = (int) $kb->id;
            }
        }

        return $ids;
    }

    protected function snippet(?ScoredChunk $hit): string
    {
        if ($hit === null) {
            return '';
        }

        return mb_substr($hit->content, 0, 80);
    }
}
