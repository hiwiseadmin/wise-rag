<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 评测：评测集读取（合法 / 非法 / 缺失）、hit_rate、page_hit_rate、MRR、引用覆盖率、耗时
 *   2. 腐烂度：新页面≈0、源变更后上升、文档被删后缺失率生效、refresh 不改 update_time、
 *      超阈值自动从影子库撤下并标脏、分布统计
 *   3. 观测：overview 计数 / 任务成功率 / token 与预算占比 / bySpace 成本
 *   4. 体检：stale_published 计数
 *   5. 预算闸：on_exceed=pause 暂停并报错；warn 不暂停
 *   6. 命令与示例评测集存在、代码纪律
 *
 * 用法：php src/packages/wiseadmin/wise-llm-wiki/tests/verify-p5.php
 * 退出码：0 全部通过；1 存在失败
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use think\facade\Config;
use think\facade\Db;
use wise\agent\contract\AiClientInterface;
use wise\rag\client\EmbeddingFactory;
use wise\rag\parser\ParserRegistry;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\source\SourceFactory;
use wise\rag\splitter\SplitterFactory;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;
use wise\wiki\bridge\AgentBridge;
use wise\wiki\bridge\RagBridge;
use wise\wiki\compile\EvidenceCollector;
use wise\wiki\compile\OutputValidator;
use wise\wiki\compile\PageWriter;
use wise\wiki\compile\PromptBuilder;
use wise\wiki\compile\ShadowPublisher;
use wise\wiki\compile\WikiCompiler;
use wise\wiki\dto\CompileInput;
use wise\wiki\eval\Evaluator;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;
use wise\wiki\service\CompileService;
use wise\wiki\service\DoctorService;
use wise\wiki\service\InsightService;
use wise\wiki\service\InvalidationService;
use wise\wiki\service\PageService;
use wise\wiki\service\RetentionService;
use wise\wiki\service\SpaceService;
use wise\wiki\service\StalenessService;
use wise\wiki\service\WikiSearchService;

class WikiP5Embedding implements \wise\rag\contract\EmbeddingInterface
{
    public function embed(array $texts, array $options = []): array
    {
        return array_map(static function (string $text): array {
            $v = [0.0, 0.0, 0.0, 0.0];
            if (str_contains($text, 'A100')) {
                $v[0] = 1.0;
            }
            if (str_contains($text, '散热')) {
                $v[1] = 1.0;
            }
            $v[3] = 0.05;

            return $v;
        }, $texts);
    }

    public function getDimension(): int
    {
        return 4;
    }

    public function getModel(): string
    {
        return 'verify';
    }

    public function getName(): string
    {
        return 'verify';
    }
}

class WikiP5Llm implements AiClientInterface
{
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $context = (string) ($messages[1]['content'] ?? '');
        preg_match_all('/^\[(\d{1,3})\]/m', $context, $m);
        $nos = array_values(array_unique(array_map('intval', $m[1] ?? []))) ?: [1];

        $payload = [
            'title'    => 'A100 功率与散热',
            'slug'     => 'a100-power-cooling',
            'kind'     => 'topic',
            'summary'  => 'A100 功率 250W，被动散热。',
            'markdown' => sprintf(
                "# A100 功率与散热\n\nA100 的额定功率为 250W，支持 PCIe 4.0 与 NVLink 互联。[%d]\n\n"
                . "## 散热方案\n\n采用被动散热设计，满载温度控制在 75 度以内，适合高密度机柜部署。[%d]\n",
                $nos[0],
                $nos[1] ?? $nos[0]
            ),
            'citations'  => array_values(array_unique([$nos[0], $nos[1] ?? $nos[0]])),
            'links'      => [],
            'confidence' => 0.9,
        ];

        return [
            'content'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'tool_calls' => [],
            'usage'      => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ];
    }

    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator
    {
        yield ['type' => 'finish', 'reason' => 'stop', 'tool_calls' => [], 'usage' => []];
    }

    public function getProviderName(): string
    {
        return 'verify';
    }

    public function getModelName(): string
    {
        return 'verify-1';
    }

    public function supports(string $feature): bool
    {
        return false;
    }
}

$app = new \think\App();
$app->initialize();

$failures = [];
$checks   = 0;
$created  = ['space_id' => 0, 'src_kb_id' => 0, 'shadow_kb_id' => 0, 'page_id' => 0];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  [ok]   {$label}\n";
        return;
    }
    $failures[] = $label . ($detail !== '' ? " ({$detail})" : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function setWikiCfg(string $section, array $values): void
{
    $cfg = (array) config('wise-rag.wiki.' . $section, []);
    foreach ($values as $k => $v) {
        $cfg[$k] = $v;
    }
    $__rag = (array) config('wise-rag');
    $__rag['wiki'][$section] = $cfg;
    Config::set($__rag, 'wise-rag');
}

$pkgRoot   = dirname(__DIR__);
$embedding = new EmbeddingFactory([
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WikiP5Embedding', 'model' => 'verify', 'dimension' => 4]],
]);
$vector    = new VectorFactory(['default' => 'database', 'stores' => ['database' => ['class' => DatabaseVectorStore::class]]]);
$kbService = new KnowledgeBaseService($embedding, $vector);
$ingestion = new IngestionService(new ParserRegistry([]), new SplitterFactory('structure'), $embedding, $vector, $kbService, new SourceFactory([]));
$retrieval = new RetrievalService($embedding, $vector, $kbService);
$bridge    = new RagBridge($kbService, $retrieval, $ingestion);
$agent     = new AgentBridge(new WikiP5Llm());

$search    = new WikiSearchService($bridge);
$validator = new OutputValidator();
$writer    = new PageWriter();
$publisher = new ShadowPublisher($bridge);
$pages     = new PageService($publisher, $writer);
$spaces    = new SpaceService($bridge);
$collector = new EvidenceCollector($bridge);
$compiler  = new WikiCompiler($agent, new PromptBuilder());
$compile   = new CompileService($spaces, $pages, $collector, $compiler, $validator, $writer, $publisher);
$staleness = new StalenessService($bridge, $publisher);
$insight   = new InsightService($staleness);
$evaluator = new Evaluator($search, $validator);
$invalidation = new InvalidationService($bridge, $pages);
$doctor    = new DoctorService($bridge, $spaces, $publisher, $invalidation, new RetentionService(), $staleness);

setWikiCfg('queue', ['enabled' => false]);
setWikiCfg('compile', ['auto_publish' => true]);

$tmpEval = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiki_eval_' . substr(md5((string) microtime(true)), 0, 6) . '.json';

try {
    echo "== 0. 准备 ==\n";

    $srcKb = $kbService->create(['kb_key' => 'p5_src_' . substr(md5((string) microtime(true)), 0, 6), 'name' => 'P5 源库', 'embedding' => 'verify']);
    $created['src_kb_id'] = (int) $srcKb->id;
    $docId = $ingestion->ingestText($srcKb, "# A100 手册\n\nA100 功率 250W，支持 PCIe 4.0 与 NVLink 互联。\n\n## 散热\n\n采用被动散热设计，满载温度控制在 75 度以内。", [
        'external_id' => 'p5-a100',
        'title'       => 'A100 手册',
    ]);

    $space = $spaces->create([
        'space_key'    => 'p5_verify_' . substr(md5($srcKb->id . microtime(true)), 0, 6),
        'name'         => 'P5 验证空间',
        'source_kb_id' => (int) $srcKb->id,
    ]);
    $created['space_id']     = (int) $space->id;
    $created['shadow_kb_id'] = (int) $space->shadow_kb_id;
    $spaceKey                = (string) $space->space_key;

    $first  = $compile->compile(new CompileInput((int) $space->id, 'A100 功率'));
    $pageId = (int) ($first['page_id'] ?? 0);
    $created['page_id'] = $pageId;

    check('初始页面已发布并进入影子库', $pageId > 0 && (int) ($first['shadow_doc_id'] ?? 0) > 0, json_encode($first['errors'] ?? []));
    check('影子库已有可检索分块', (int) Db::name('ai_kb_chunks')->where('kb_id', (int) $space->shadow_kb_id)->count() >= 1);

    echo "== 1. 评测（wiki:eval 内核）==\n";

    file_put_contents($tmpEval, json_encode(['cases' => [
        ['query' => 'A100 功率', 'expect' => ['250W'], 'top_k' => 5],
        ['query' => 'zzzqqq 无关查询', 'expect' => ['绝不存在的词'], 'top_k' => 5],
    ]], JSON_UNESCAPED_UNICODE));

    $cases = $evaluator->loadFile($tmpEval);
    check('评测集可读取', count($cases) === 2, (string) count($cases));

    $okJson = '';
    try {
        $invalid = sys_get_temp_dir() . '/wiki_eval_bad_' . getmypid() . '.json';
        file_put_contents($invalid, '{not json');
        $evaluator->loadFile($invalid);
        @unlink($invalid);
    } catch (WikiException $e) {
        $okJson = $e->getMessage();
    }
    check('非法 JSON 报可读错误', str_contains($okJson, '不是合法 JSON'), $okJson);

    $notFound = '';
    try {
        $evaluator->loadFile(sys_get_temp_dir() . '/no_such_eval_file.json');
    } catch (WikiException $e) {
        $notFound = $e->getMessage();
    }
    check('评测集缺失报可读错误', str_contains($notFound, '不存在'), $notFound);

    $metrics = $evaluator->run($cases);
    check('hit_rate = 0.5', abs($metrics['hit_rate'] - 0.5) < 0.001, (string) $metrics['hit_rate']);
    check('page_hit_rate = 0.5', abs($metrics['page_hit_rate'] - 0.5) < 0.001, (string) $metrics['page_hit_rate']);
    check('MRR = 0.5（首个 case 排名 1）', abs($metrics['mrr'] - 0.5) < 0.001, (string) $metrics['mrr']);
    check('引用覆盖率 > 0', (float) $metrics['citation_coverage'] > 0, (string) $metrics['citation_coverage']);
    check('平均耗时为非负整数', (int) $metrics['avg_latency_ms'] >= 0);
    check('明细含 fallback / coverage 字段', isset($metrics['details'][0]['fallback']) && array_key_exists('coverage', $metrics['details'][0]));

    $emptyMetrics = $evaluator->run([]);
    check('空评测集不报错且指标为 0', $emptyMetrics['cases'] === 0 && $emptyMetrics['hit_rate'] === 0.0);

    echo "== 2. 腐烂度（staleness）==\n";

    $refresh = $staleness->refresh((int) $space->id);
    $value   = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('staleness');
    check('新编译页面腐烂度≈0', $value <= 5, (string) $value);
    check('refresh 返回统计', (int) $refresh['pages'] >= 1);

    $beforeUpdate = (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('update_time');
    $staleness->refresh((int) $space->id);
    check('refresh 不改动 update_time', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('update_time') === $beforeUpdate);

    $ingestion->ingestText($srcKb, "# A100 手册\n\nA100 功率改为 300W，接口升级为 PCIe 5.0 与 NVLink 互联。\n\n## 散热\n\n改为主动风冷，满载温度控制在 70 度以内。", [
        'external_id' => 'p5-a100',
        'title'       => 'A100 手册',
    ]);
    $staleness->refresh((int) $space->id);
    $changedValue = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('staleness');
    check('源变更后腐烂度上升到 50 左右', $changedValue >= 45 && $changedValue <= 55, (string) $changedValue);

    Db::name('ai_kb_documents')->where('id', $docId)->delete();
    Db::name('ai_kb_chunks')->where('doc_id', $docId)->delete();
    $staleness->refresh((int) $space->id);
    $missingValue = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('staleness');
    check('引用文档被删后腐烂度≈70（含缺失率）', $missingValue >= 65 && $missingValue <= 75, (string) $missingValue);

    $noDeps = new StalenessService($bridge, $publisher);
    check('无依赖页面给中间值 50', $noDeps->computeValue([], [], date('Y-m-d H:i:s')) === 50);

    $dist = $staleness->distribution((int) $space->id);
    check('分布统计可读', isset($dist['buckets']['61-80']) && (float) $dist['avg'] > 0, json_encode($dist['buckets']));
    check('超阈值页面被计数', (int) $dist['over_threshold'] >= 1, (string) $dist['over_threshold']);

    $withdrawn = $staleness->withdrawStale((int) $space->id, 40);
    check('超阈值页面已从影子库撤下', (int) $withdrawn['withdrawn'] === 1, json_encode($withdrawn));
    check('撤下后 shadow_doc_id 归零', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id') === 0);
    check('撤下后标记为质量脏', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('dirty_reason') === WikiPage::DIRTY_QUALITY);

    echo "== 3. 观测（wiki:stats 内核）==\n";

    $doctorReport = $doctor->inspect((int) $space->id);
    check('体检报告含过期已发布页面计数', array_key_exists('stale_published', $doctorReport));

    $overview = $insight->overview((int) $space->id);
    check('概览含空间/页面/任务/预算/新鲜度', isset($overview['spaces'], $overview['pages'], $overview['jobs'], $overview['tokens'], $overview['staleness']));
    check('页面总数 ≥ 1', (int) $overview['pages']['total'] >= 1);
    check('任务总数 ≥ 1 且成功率可计算', (int) $overview['jobs']['total'] >= 1 && $overview['jobs']['success_rate'] >= 0);
    check('token 用量已累计', (int) $overview['tokens']['used'] > 0, (string) $overview['tokens']['used']);
    check('预算占比在 [0,1]', (float) $overview['tokens']['ratio'] >= 0 && (float) $overview['tokens']['ratio'] <= 1);

    $bySpace = $insight->bySpace();
    check('按空间报表含成本字段', $bySpace !== [] && array_key_exists('cost', $bySpace[0]) && array_key_exists('ratio', $bySpace[0]));

    echo "== 4. 预算闸 ==\n";

    Db::name('ai_wiki_spaces')->where('id', (int) $space->id)->update([
        'budget_tokens_month' => 100,
        'tokens_used_month'   => 100,
        'status'              => WikiSpace::STATUS_ACTIVE,
    ]);
    setWikiCfg('budget', ['on_exceed' => 'pause']);

    $paused = '';
    try {
        $compile->compile(new CompileInput((int) $space->id, 'A100 功率'));
    } catch (WikiException $e) {
        $paused = $e->getMessage();
    }
    check('超预算（pause）抛可读错误', str_contains($paused, '预算已用尽'), $paused);
    check('超预算时空间被暂停', (string) Db::name('ai_wiki_spaces')->where('id', (int) $space->id)->value('status') === WikiSpace::STATUS_PAUSED);

    Db::name('ai_wiki_spaces')->where('id', (int) $space->id)->update(['status' => WikiSpace::STATUS_ACTIVE]);
    setWikiCfg('budget', ['on_exceed' => 'warn']);

    $warnPassed = false;
    try {
        $result = $compile->compile(new CompileInput((int) $space->id, 'A100 功率'));
        $warnPassed = ($result['ok'] ?? false) === true;
    } catch (\Exception $e) {
        $warnPassed = false;
    }
    check('超预算（warn）不阻断编译', $warnPassed);
    check('warn 模式下空间保持 active', (string) Db::name('ai_wiki_spaces')->where('id', (int) $space->id)->value('status') === WikiSpace::STATUS_ACTIVE);

    echo "== 5. 命令与纪律 ==\n";

    $providerSrc = (string) file_get_contents($pkgRoot . '/src/wiki/WikiServiceProvider.php');
    check('wiki:stats 已注册', str_contains($providerSrc, 'command\Stats'));
    check('wiki:eval 已注册', str_contains($providerSrc, 'command\Evaluate'));
    check('示例评测集存在', is_file($pkgRoot . '/tests/fixtures/eval-cases.json'));

    $srcDir = $pkgRoot . '/src';
    $bad    = [];
    $it     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = (string) $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'bridge' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $code = (string) file_get_contents($path);
        if (str_contains($code, "Db::name('ai_kb_") || preg_match('/use\s+wise\\\\rag\\\\/', $code) === 1) {
            $bad[] = basename($path);
        }
    }
    check('src/ 除 bridge 外无 ai_kb_ 直连与 wise\\rag 引用', $bad === [], implode(',', $bad));
} catch (\Throwable $e) {
    check('P5 端到端无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    @unlink($tmpEval);

    try {
        if ($created['space_id'] > 0) {
            $model = WikiSpace::query()->where('id', $created['space_id'])->find();
            if ($model instanceof WikiSpace) {
                $spaces->delete($model, true);
            }
        }
        if ($created['src_kb_id'] > 0) {
            $kbService->delete((int) $created['src_kb_id'], true);
        }
        check('清理验证数据', (int) Db::name('ai_wiki_spaces')->where('id', $created['space_id'])->count() === 0);
    } catch (\Throwable $e) {
        check('清理验证数据', false, $e->getMessage());
    }
}

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P5 验证通过 ✅\n" : "P5 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
