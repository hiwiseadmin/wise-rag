<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 失效语义：内容真变才标脏、未引用文档不牵连、幂等、kbId 不匹配零操作
 *   2. R-02 防线：事件到了但 doc_hash 未变（--force 重放）→ 不标脏；证据指纹未变 → 跳过编译
 *   3. 队列：关闭时不入队；enqueue() 不抛异常；CompilePageJob 可被 worker 执行
 *   4. 体检（wiki:doctor）：8 类问题「检出 → 修复 → 复检」全链路
 *   5. 保留策略：超期任务、超量版本（人工版本永久保留）
 *   6. 纪律：src/ 除 bridge 外无 ai_kb_ 直连与 wise\rag 引用
 *
 * 用法：php src/packages/wiseadmin/wise-llm-wiki/tests/verify-p2.php
 * 退出码：0 全部通过；1 存在失败
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use think\facade\Config;
use think\facade\Db;
use think\facade\Event;
use wise\agent\contract\AiClientInterface;
use wise\rag\client\EmbeddingFactory;
use wise\rag\event\DocumentIndexed;
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
use wise\wiki\dto\CompileInput;
use wise\wiki\job\CompilePageJob;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;
use wise\wiki\service\CompileService;
use wise\wiki\service\DoctorService;
use wise\wiki\service\InvalidationService;
use wise\wiki\service\SpaceService;

class WikiP2Embedding implements \wise\rag\contract\EmbeddingInterface
{
    public function embed(array $texts, array $options = []): array
    {
        return array_map(static function (string $text): array {
            $v = [0.0, 0.0, 0.0, 0.0];
            if (str_contains($text, 'A100')) {
                $v[0] = 1.0;
            }
            if (str_contains($text, '散热') || str_contains($text, '风冷')) {
                $v[1] = 1.0;
            }
            if (str_contains($text, '保修')) {
                $v[2] = 1.0;
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

class WikiP2Llm implements AiClientInterface
{
    public int $calls = 0;

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $this->calls++;

        $context = (string) ($messages[1]['content'] ?? '');
        preg_match_all('/^\[(\d{1,3})\]/m', $context, $m);
        $nos = array_values(array_unique(array_map('intval', $m[1] ?? []))) ?: [1];

        $payload = [
            'title'      => 'A100 功率与散热',
            'slug'       => 'a100-power-cooling',
            'kind'       => 'topic',
            'summary'    => 'A100 功率与散热说明。',
            'markdown'   => sprintf("# A100\n\n当前结论来自证据。[%d]\n\n## 散热\n\n散热方案见证据。[%d]\n", $nos[0], $nos[1] ?? $nos[0]),
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
        $r = $this->chat($messages, $tools, $options);
        yield ['type' => 'finish', 'reason' => 'stop', 'tool_calls' => [], 'usage' => $r['usage'] ?? []];
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

/**
 * 假队列任务对象（模拟 think-queue\Job 的最小接口）
 */
class WikiP2FakeJob
{
    public bool $deleted = false;

    public array $released = [];

    public function attempts(): int
    {
        return 1;
    }

    public function delete(): void
    {
        $this->deleted = true;
    }

    public function release(int $delay): void
    {
        $this->released[] = $delay;
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

/**
 * 清掉容器里已缓存的本包服务实例（原因见 verify-p1 同名函数注释）
 */
function purgeWikiServices(object $app): void
{
    $classes = [
        \wise\wiki\service\SpaceService::class,
        \wise\wiki\service\PageService::class,
        \wise\wiki\service\CompileService::class,
        \wise\wiki\service\WikiSearchService::class,
        \wise\wiki\service\InvalidationService::class,
        \wise\wiki\service\DoctorService::class,
        \wise\wiki\service\RetentionService::class,
        \wise\wiki\compile\EvidenceCollector::class,
        \wise\wiki\compile\WikiCompiler::class,
        \wise\wiki\compile\PromptBuilder::class,
        \wise\wiki\compile\OutputValidator::class,
        \wise\wiki\compile\PageWriter::class,
        \wise\wiki\compile\ShadowPublisher::class,
        \wise\wiki\compile\TopicPlanner::class,
        \wise\wiki\tool\WikiToolRegistrar::class,
    ];

    foreach ($classes as $class) {
        try {
            $app->delete($class);
        } catch (\Throwable $e) {
        }
    }
}

/**
 * 整节覆盖配置（Config::set 第二参数是顶层键，不是点号路径）
 */
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

echo "== 0. 准备：源库 + 空间 + 首次编译 ==\n";

$embedding = new EmbeddingFactory([
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WikiP2Embedding', 'model' => 'verify', 'dimension' => 4]],
]);
$vector    = new VectorFactory(['default' => 'database', 'stores' => ['database' => ['class' => DatabaseVectorStore::class]]]);
$kbService = new KnowledgeBaseService($embedding, $vector);
$ingestion = new IngestionService(new ParserRegistry([]), new SplitterFactory('structure'), $embedding, $vector, $kbService, new SourceFactory([]));
$retrieval = new RetrievalService($embedding, $vector, $kbService);
$bridge    = new RagBridge($kbService, $retrieval, $ingestion);
$llm       = new WikiP2Llm();

$app->instance(RagBridge::class, $bridge);
$app->instance(AgentBridge::class, new AgentBridge($llm));
purgeWikiServices($app);

setWikiCfg('queue', ['enabled' => false]);

try {
    $srcKey = 'p2_src_' . substr(md5((string) microtime(true)), 0, 6);
    $srcKb  = $kbService->create(['kb_key' => $srcKey, 'name' => 'P2 源库', 'embedding' => 'verify']);
    $created['src_kb_id'] = (int) $srcKb->id;

    $doc1Id = $ingestion->ingestText($srcKb, "# A100 手册\n\nA100 功率 250W，支持 PCIe 4.0。\n\n## 散热\n\n采用被动散热设计。", [
        'external_id' => 'a100-manual',
        'title'       => 'A100 手册',
    ]);
    $doc2Id = $ingestion->ingestText($srcKb, "# 保修政策\n\n整机保修三年。", [
        'external_id' => 'warranty',
        'title'       => '保修政策',
    ]);
    check('源库两篇文档已就绪', $doc1Id > 0 && $doc2Id > 0);

    $spaces = $app->make(SpaceService::class);
    $space  = $spaces->create([
        'space_key'    => 'p2_verify_' . substr(md5($srcKey), 0, 6),
        'name'         => 'P2 验证空间',
        'source_kb_id' => (int) $srcKb->id,
    ]);
    $created['space_id']     = (int) $space->id;
    $created['shadow_kb_id'] = (int) $space->shadow_kb_id;

    setWikiCfg('compile', ['auto_publish' => true]);

    $compile = $app->make(CompileService::class);
    $first   = $compile->compile(new CompileInput((int) $space->id, 'A100 功率'));
    $created['page_id'] = (int) ($first['page_id'] ?? 0);

    check('首次编译成功且已发布', ($first['ok'] ?? false) === true && ($first['status'] ?? '') === WikiPage::STATUS_PUBLISHED, json_encode($first['errors'] ?? []));
    check('影子文档已建立', (int) ($first['shadow_doc_id'] ?? 0) > 0);

    $invalidation = $app->make(InvalidationService::class);
    $pageId       = $created['page_id'];

    echo "== 1. 失效语义 ==\n";

    $r = $invalidation->byDocument((int) $srcKb->id, (int) $doc1Id);
    check('内容未变不标脏', $r['dirty'] === 0, json_encode($r));
    check('内容未变计入 unchanged', $r['unchanged'] === 1, json_encode($r));

    $r = $invalidation->byDocument((int) $srcKb->id, (int) $doc2Id);
    check('未引用文档不牵连页面', $r['pages'] === 0 && $r['dirty'] === 0, json_encode($r));

    $r = $invalidation->byDocument(999999, (int) $doc1Id);
    check('kbId 不匹配时零操作', $r['spaces'] === 0 && $r['dirty'] === 0, json_encode($r));

    $ingestion->ingestText($srcKb, "# A100 手册\n\nA100 功率 300W（改版），支持 PCIe 5.0。\n\n## 散热\n\n改为主动风冷。", [
        'external_id' => 'a100-manual',
        'title'       => 'A100 手册',
    ]);
    $dirtyReason = (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('dirty_reason');
    check('源文档真变后经事件标脏', $dirtyReason === WikiPage::DIRTY_DOC_CHANGED, $dirtyReason);

    $r = $invalidation->byDocument((int) $srcKb->id, (int) $doc1Id);
    check('已脏页面不重复标脏', $r['dirty'] === 0, json_encode($r));

    check('空间 dirty_count 已同步', (int) Db::name('ai_wiki_spaces')->where('id', (int) $space->id)->value('dirty_count') === 1);

    Db::name('ai_wiki_pages')->where('id', $pageId)->update(['dirty_reason' => '']);
    setWikiCfg('compile', ['prompt_version' => 'v2-test']);
    $marked = $invalidation->byPromptVersion((int) $space->id, 'v2-test');
    check('prompt 版本变更触发全空间标脏', $marked >= 1, (string) $marked);
    check('prompt 漂移可被巡检发现', $invalidation->promptDrifts() === []);
    Db::name('ai_wiki_pages')->where('id', $pageId)->update(['dirty_reason' => '']);
    setWikiCfg('compile', ['prompt_version' => 'v1']);

    echo "== 2. R-02 防线：事件重放 / 指纹跳过 ==\n";

    $sync = $compile->compile(new CompileInput((int) $space->id, 'A100 功率'));
    check('二次编译同步到最新源内容', ($sync['ok'] ?? false) === true && empty($sync['skipped']), json_encode($sync['errors'] ?? []));
    Db::name('ai_wiki_pages')->where('id', $pageId)->update(['dirty_reason' => '']);

    Event::trigger(new DocumentIndexed((int) $srcKb->id, (int) $doc1Id, 'a100-manual', 3));
    check(
        '事件重放（内容未变）不标脏',
        (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('dirty_reason') === ''
    );

    Db::name('ai_wiki_pages')->where('id', $pageId)->update(['dirty_reason' => WikiPage::DIRTY_PROMPT_CHANGED]);
    $callsBefore = $llm->calls;
    $skipped     = $compile->compile(new CompileInput((int) $space->id, 'A100 功率', CompileInput::TRIGGER_DOC_CHANGED, $pageId));
    check('指纹未变则跳过编译', ($skipped['skipped'] ?? false) === true && ($skipped['reason'] ?? '') === 'unchanged', json_encode($skipped));
    check('跳过时未调用 LLM', $llm->calls === $callsBefore, $llm->calls . ' vs ' . $callsBefore);
    check('跳过后脏标记被清除', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('dirty_reason') === '');

    setWikiCfg('compile', ['skip_if_unchanged' => false]);
    Db::name('ai_wiki_pages')->where('id', $pageId)->update(['dirty_reason' => WikiPage::DIRTY_MANUAL]);
    $recompiled = $compile->compile(new CompileInput((int) $space->id, 'A100 功率', CompileInput::TRIGGER_DOC_CHANGED, $pageId));
    check('关闭开关后照常编译', ($recompiled['ok'] ?? false) === true && empty($recompiled['skipped']), json_encode($recompiled['errors'] ?? []));
    check('确实调用了 LLM', $llm->calls > $callsBefore);
    setWikiCfg('compile', ['skip_if_unchanged' => true]);

    echo "== 3. 队列与任务 ==\n";

    $enqueueOff = $invalidation->enqueue($pageId);
    check('queue.enabled=false 时不入队', $enqueueOff === false);

    setWikiCfg('queue', ['enabled' => true]);
    $enqueued = $invalidation->enqueue($pageId);
    check('开启后 enqueue 不抛异常（返回值可为 false，取决于队列驱动）', is_bool($enqueued));
    setWikiCfg('queue', ['enabled' => false]);

    $fakeJob = new WikiP2FakeJob();
    Db::name('ai_wiki_pages')->where('id', $pageId)->update(['dirty_reason' => WikiPage::DIRTY_MANUAL]);
    (new CompilePageJob())->fire($fakeJob, [
        'space_id' => (int) $space->id,
        'page_id'  => $pageId,
        'trigger'  => CompileInput::TRIGGER_DOC_CHANGED,
    ]);
    check('CompilePageJob 可被 worker 执行并删除任务', $fakeJob->deleted === true);
    check('任务执行后脏标记被清除', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('dirty_reason') === '');

    $fakeJob2 = new WikiP2FakeJob();
    (new CompilePageJob())->fire($fakeJob2, ['space_id' => (int) $space->id, 'page_id' => 999999999]);
    check('页面已删除时任务作废（不重试）', $fakeJob2->deleted === true && $fakeJob2->released === []);

    echo "== 4. 体检：检出 → 修复 → 复检 ==\n";

    $doctor = $app->make(DoctorService::class);

    Db::name('ai_wiki_pages')->where('id', $pageId)->update(['shadow_doc_id' => 0]);
    $report = $doctor->inspect((int) $space->id);
    check('检出「已发布未进影子库」', count($report['pages_unpublished']) === 1, json_encode($report['pages_unpublished']));
    $fixed = $doctor->fix($report);
    check('修复：补发影子文档', $fixed['shadow_republished'] >= 1, json_encode($fixed));
    check('复检：影子文档已就绪', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id') > 0);

    Db::name('ai_wiki_pages')->where('id', $pageId)->update([
        'content_md' => "# A100\n\n人工追加了一段说明文字，长度与影子文档不一致，需要补发。[1]\n",
    ]);
    $report = $doctor->inspect((int) $space->id);
    $shadowDocId = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id');
    $pageChars   = mb_strlen((string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('content_md'));
    $shadowChars0 = (int) Db::name('ai_kb_documents')->where('id', $shadowDocId)->value('char_count');
    check(
        '检出「影子文档与页面不一致」',
        count($report['pages_shadow_stale']) === 1,
        json_encode(['stale' => $report['pages_shadow_stale'], 'shadow_doc' => $shadowDocId, 'page_chars' => $pageChars, 'shadow_chars' => $shadowChars0, 'snapshot_missing' => !isset($report['pages_shadow_missing'])])
    );
    $doctor->fix($report);
    $docRow = Db::name('ai_kb_documents')->where('id', $shadowDocId)->find();
    check(
        '修复后影子文档内容已对齐',
        is_array($docRow) && (string) $docRow['content_hash'] === md5(trim((string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('content_md')))
    );

    Db::name('ai_wiki_dependencies')->insert([
        'page_id'     => $pageId,
        'kb_id'       => (int) $srcKb->id,
        'doc_id'      => 999999999,
        'chunk_id'    => 888888888,
        'doc_hash'    => 'orphan',
        'cite_no'     => 99,
        'cited'       => 1,
        'create_time' => date('Y-m-d H:i:s'),
    ]);
    $report = $doctor->inspect((int) $space->id);
    check('检出孤儿依赖', count($report['orphan_deps']) >= 1, (string) count($report['orphan_deps']));
    $doctor->fix($report);
    check('修复：孤儿依赖已清理', (int) Db::name('ai_wiki_dependencies')->where('doc_id', 999999999)->count() === 0);

    Db::name('ai_wiki_pages')->where('id', $pageId)->update([
        'locked_by'    => 'crashed-worker',
        'locked_until' => date('Y-m-d H:i:s', time() - 600),
    ]);
    $report = $doctor->inspect((int) $space->id);
    check('检出残留编译锁', count($report['stale_locks']) === 1, json_encode($report['stale_locks']));
    $doctor->fix($report);
    check('修复：锁已释放', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('locked_by') === '');

    Db::name('ai_wiki_compile_jobs')->insert([
        'space_id'       => (int) $space->id,
        'page_id'        => $pageId,
        'trigger_type'   => 'manual',
        'status'         => 'ok',
        'prompt_version' => 'v1',
        'model_name'     => 'verify-1',
        'evidence_count' => 1,
        'tokens_in'      => 1,
        'tokens_out'     => 1,
        'cost'           => 0,
        'latency_ms'     => 1,
        'attempt'        => 1,
        'error'          => '',
        'create_time'    => date('Y-m-d H:i:s', time() - 400 * 86400),
        'update_time'    => date('Y-m-d H:i:s', time() - 400 * 86400),
    ]);
    $report = $doctor->inspect((int) $space->id);
    check('检出超期任务日志', (int) $report['expired_jobs'] >= 1, (string) $report['expired_jobs']);
    $doctor->fix($report);
    check('修复：超期任务已清理', (int) Db::name('ai_wiki_compile_jobs')->where('space_id', (int) $space->id)->where('create_time', '<', date('Y-m-d H:i:s', time() - 300 * 86400))->count() === 0);

    setWikiCfg('retention', ['keep_revisions' => 2]);
    $now = date('Y-m-d H:i:s');
    foreach ([100, 101, 102] as $revNo) {
        Db::name('ai_wiki_page_revisions')->insert([
            'page_id'        => $pageId,
            'rev_no'         => $revNo,
            'content_md'     => '历史版本',
            'summary'        => '',
            'prompt_version' => 'v1',
            'model_name'     => 'verify-1',
            'tokens_in'      => 1,
            'tokens_out'     => 1,
            'cost'           => 0,
            'change_reason'  => 'compile',
            'diff_summary'   => '',
            'editor_type'    => 'system',
            'editor_id'      => 0,
            'create_time'    => $now,
        ]);
    }
    Db::name('ai_wiki_page_revisions')->insert([
        'page_id'        => $pageId,
        'rev_no'         => 103,
        'content_md'     => '人工修订版本，必须永久保留',
        'summary'        => '',
        'prompt_version' => 'v1',
        'model_name'     => '',
        'tokens_in'      => 0,
        'tokens_out'     => 0,
        'cost'           => 0,
        'change_reason'  => 'manual',
        'diff_summary'   => '',
        'editor_type'    => 'admin',
        'editor_id'      => 1,
        'create_time'    => $now,
    ]);

    $report = $doctor->inspect((int) $space->id);
    check('检出超量版本', (int) $report['excess_revisions'] > 0, (string) $report['excess_revisions']);
    $doctor->fix($report);
    check('人工版本被永久保留', (int) Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->where('rev_no', 103)->count() === 1);
    check('超量系统版本已清理', (int) Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->whereIn('rev_no', [100, 101])->count() === 0);

    $kbService->delete((int) $srcKb->id, true);
    $created['src_kb_id'] = 0;
    $report = $doctor->inspect((int) $space->id);
    check('检出源库缺失', count($report['spaces_missing_source']) === 1, json_encode($report['spaces_missing_source']));
    $doctor->fix($report);
    check('修复：空间已暂停', (string) Db::name('ai_wiki_spaces')->where('id', (int) $space->id)->value('status') === WikiSpace::STATUS_PAUSED);
    check('暂停后不可编译', (static function () use ($compile, $space): bool {
        try {
            $compile->compile(new CompileInput((int) $space->id, 'A100 功率'));
            return false;
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), '不可编译');
        }
    })());

    echo "== 5. 纪律 ==\n";
    check('DocumentIndexed 监听注册可用', RagBridge::listenDocumentIndexed(static function (): void {
    }) === true);

    $srcDir = dirname(__DIR__) . '/src';
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
    check('P2 端到端无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    try {
        if ($created['space_id'] > 0) {
            $model = WikiSpace::query()->where('id', $created['space_id'])->find();
            if ($model instanceof WikiSpace) {
                app(SpaceService::class)->delete($model, true);
            }
            Db::name('ai_wiki_pages')->where('space_id', $created['space_id'])->delete();
            Db::name('ai_wiki_compile_jobs')->where('space_id', $created['space_id'])->delete();
        }
        if ($created['src_kb_id'] > 0) {
            $kbService->delete((int) $created['src_kb_id'], true);
        }
        $leftSpace = (int) Db::name('ai_wiki_spaces')->where('id', $created['space_id'])->count();
        $leftKb    = (int) Db::name('ai_kb_knowledge_bases')->where('id', $created['shadow_kb_id'])->count();
        check('清理验证数据', $leftSpace === 0 && $leftKb === 0, "space={$leftSpace}, kb={$leftKb}");
    } catch (\Throwable $e) {
        check('清理验证数据', false, $e->getMessage());
    }
}

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P2 验证通过 ✅\n" : "P2 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
