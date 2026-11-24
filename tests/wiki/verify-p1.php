<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 纯单元：JsonRepair / Slugger / OutputValidator 覆盖率 / PageWriter::extractOutline /
 *      PageWriter::fingerprint / PromptBuilder 模板分流 / EvidenceCollector::subQueries
 *   2. 端到端（离线假 Embedding + 假 LLM）：建空间 → 取料 → 编译 → 落库 → 发布影子库 → 影子库可检索
 *   3. 防幻觉：幻觉引用 / 正文未知编号 / 非 JSON 重试
 *   4. 人工优先：已发布 + 人工编辑过的页面重编译只生成候选版本
 *   5. 自循环隔离：取料 kbIds 永不含影子库；RagBridge 拒绝空 kbIds
 *   6. 清理：删除页面 → 撤回影子文档；最后删空间（含影子库）与源库
 *
 * 用法：php src/packages/wiseadmin/wise-llm-wiki/tests/verify-p1.php
 * 退出码：0 全部通过；1 存在失败
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use think\facade\Db;
use wise\agent\contract\AiClientInterface;
use wise\rag\client\EmbeddingFactory;
use wise\rag\dto\RetrievalQuery;
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
use wise\wiki\dto\CompileInput;
use wise\wiki\dto\Evidence;
use wise\wiki\model\WikiPage;
use wise\wiki\service\CompileService;
use wise\wiki\service\PageService;
use wise\wiki\service\SpaceService;
use wise\wiki\support\JsonRepair;
use wise\wiki\support\Slugger;

/**
 * 离线假 Embedding：按关键词分桶，避免验证脚本依赖真实 API
 */
class WikiVerifyEmbedding implements \wise\rag\contract\EmbeddingInterface
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

/**
 * 假 LLM：从 user 消息里解析证据编号，自适应生成合法/非法产物
 */
class WikiVerifyLlm implements AiClientInterface
{
    public int $calls = 0;

    /** 下一次 chat 返回不可解析内容（用于验证重试路径；命中后自动复位） */
    public bool $serveBadJson = false;

    /** ok | wrong-citation | unknown-inline | similar-slug */
    public string $mode = 'ok';

    /** @var array<int, array<string, mixed>> */
    public array $lastMessages = [];

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $this->calls++;
        $this->lastMessages = $messages;

        $context = (string) ($messages[1]['content'] ?? '');
        preg_match_all('/^\[(\d{1,3})\]/m', $context, $m);
        $nos = array_values(array_unique(array_map('intval', $m[1] ?? [])));
        if ($nos === []) {
            $nos = [1];
        }

        if ($this->serveBadJson) {
            $this->serveBadJson = false;

            return ['content' => '抱歉，我先说明一下思路：这不是 JSON。', 'tool_calls' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
        }

        $cite1 = $nos[0];
        $cite2 = $nos[1] ?? $nos[0];

        $citations = array_values(array_unique([$cite1, $cite2]));
        if ($this->mode === 'wrong-citation') {
            $citations[] = 99;
        }

        $markdown = sprintf(
            "# A100\n\nA100 功率为 250W，支持 PCIe 4.0。[%d]\n\n## 散热\n\n采用被动散热设计。[%d]\n",
            $cite1,
            $cite2
        );

        if ($this->mode === 'unknown-inline') {
            $markdown .= "\n\n## 未覆盖\n\n该型号支持水冷。[99]\n";
        }

        $slug = $this->mode === 'similar-slug' ? 'a100-power-coolin' : 'a100-power-cooling';

        $payload = [
            'title'      => 'A100 功率与散热',
            'slug'       => $slug,
            'kind'       => 'topic',
            'summary'    => 'A100 功率 250W，采用被动散热。',
            'markdown'   => $markdown,
            'citations'  => $citations,
            'links'      => [],
            'confidence' => 0.9,
        ];

        return [
            'content'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'tool_calls' => [],
            'usage'      => ['prompt_tokens' => 120, 'completion_tokens' => 60],
        ];
    }

    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $r = $this->chat($messages, $tools, $options);
        yield ['type' => 'token', 'content' => (string) ($r['content'] ?? '')];
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
        return $feature === 'json_mode';
    }
}

/**
 * 探针桥：记录取料传入的 kbIds（用于断言自循环隔离）
 */
class WikiSpyBridge extends RagBridge
{
    /** @var array<int, int[]> */
    public array $retrieveKbIds = [];

    public function __construct()
    {
    }

    public function retrieve(string $text, array $kbIds, int $topK): array
    {
        $this->retrieveKbIds[] = $kbIds;

        return [];
    }
}

$app = new \think\App();
$app->initialize();

$failures = [];
$checks   = 0;
$created  = ['space_id' => 0, 'src_kb_id' => 0, 'shadow_kb_id' => 0];

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
 * 清掉容器里已缓存的本包服务实例
 *
 * think-container 的 `make()` 默认把实例写进 instances 缓存（Container.php:260）。
 * 本包 ServiceProvider::boot() 会注册 Agent 工具，从而在 boot 期就构造过整条服务链
 * （持有一套**真实** RagBridge）。替换 RagBridge 后必须清缓存，否则 make 仍返回旧实例，
 * 假 Embedding 不生效（表现为「未配置的 Embedding provider：verify」）。
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

echo "== 1. 单元：JsonRepair / Slugger ==\n";
check('JsonRepair 解析纯 JSON', is_array(JsonRepair::parse('{"a":1}')));
check('JsonRepair 解析 ```json 围栏', (JsonRepair::parse("```json\n{\"a\":2}\n```")['a'] ?? 0) === 2);
check('JsonRepair 解析带前后解释', (JsonRepair::parse("这是结果：\n{\"a\":3}\n希望有帮助")['a'] ?? 0) === 3);
check('JsonRepair 修尾逗号', (JsonRepair::parse('{"a":4,}')['a'] ?? 0) === 4);
check('JsonRepair 非法返回 null', JsonRepair::parse('完全不是 JSON') === null);
check('Slugger 合法值保持不变', Slugger::make('a100-power') === 'a100-power');
check('Slugger 大写转小写', Slugger::make('A100 Power') === 'a100-power');
check('Slugger 中文兜底为 page-hash', str_starts_with(Slugger::make('中文标题'), 'page-'));
check('Slugger 超长截断到 100', mb_strlen(Slugger::make(str_repeat('abcde-', 40))) <= 100);
check('Slugger::isValid 拒绝下划线', Slugger::isValid('a_b') === false);

echo "== 2. 单元：校验器 / 大纲 / 指纹 / Prompt ==\n";
$validator = new OutputValidator();
$md        = "# 标题\n\n第一段有引用标记，内容也足够长。[1]\n\n第二段没有任何引用标注，内容同样足够长。\n\n第三段有引用标记，内容同样足够长。[2]\n";
check('引用覆盖率 = 2/3', abs($validator->citationCoverage($md) - 0.6667) < 0.01, (string) $validator->citationCoverage($md));
check('标题行不计入段落', $validator->citationCoverage("# 只有标题\n") === 0.0);

$writer  = new PageWriter();
$outline = $writer->extractOutline("# A\n\n内容[1]\n\n## B\n\n内容[2][3]\n");
check('大纲提取两个标题', count($outline) === 2, (string) count($outline));
check('大纲记录引用编号', ($outline[1]['cite_ids'] ?? []) === [2, 3], json_encode($outline[1]['cite_ids'] ?? []));

$e1 = new Evidence(1, 'x', 10, 100, 'hash-a');
$e2 = new Evidence(2, 'y', 10, 101, 'hash-b');
$f1 = $writer->fingerprint([$e1, $e2]);
$f2 = $writer->fingerprint([$e2, $e1]);
check('指纹与证据顺序无关', $f1 === $f2 && strlen($f1) === 40);
$e3 = new Evidence(1, 'x', 10, 100, 'hash-changed');
check('内容变化则指纹变化', $writer->fingerprint([$e3, $e2]) !== $f1);

$prompts = new PromptBuilder();
check('faq 模板含「## 问题」约定', str_contains($prompts->template('faq'), '## 问题'));
check('未知 kind 回落 topic 模板', $prompts->template('nope') === $prompts->template('topic'));
$spaceStub = new \wise\wiki\model\WikiSpace();
$spaceStub->name = '验证空间';
$ctx = $prompts->renderContext('A100', [$e1], [['slug' => 'a', 'title' => 'A', 'status' => 'published']]);
check('上下文含证据编号', str_contains($ctx, '[1]'));
check('上下文含现有页面 slug', str_contains($ctx, '- a | A | published'));

$collector = new EvidenceCollector(new WikiSpyBridge());
check('subQueries 主查询在首位', $collector->subQueries('A100 功率')[0] === 'A100 功率');
check('subQueries 数量不超过配置', count($collector->subQueries('A100/功率/散热/保修')) <= (int) config('wise-rag.wiki.compile.sub_queries', 3));

echo "== 3. 单元：自循环隔离 ==\n";
$spy = new WikiSpyBridge();
(new EvidenceCollector($spy))->collect($spaceStubWithKb = (static function (): \wise\wiki\model\WikiSpace {
    $s = new \wise\wiki\model\WikiSpace();
    $s->id = 1;
    $s->source_kb_id = 42;
    $s->shadow_kb_id = 99;

    return $s;
})(), 'A100');
$flatKbIds = array_merge(...($spy->retrieveKbIds ?: [[]]));
check('取料只传源库 kb_id', $flatKbIds === [42], json_encode($spy->retrieveKbIds));
check('取料从不传影子库', !in_array(99, $flatKbIds, true));

try {
    (new RagBridge(
        $app->make(KnowledgeBaseService::class),
        $app->make(RetrievalService::class),
        $app->make(IngestionService::class)
    ))->retrieve('x', [], 5);
    check('RagBridge 拒绝空 kbIds', false);
} catch (\Throwable $e) {
    check('RagBridge 拒绝空 kbIds', str_contains($e->getMessage(), '必须显式指定'));
}

echo "== 4. 端到端：建空间 → 编译 → 落库 → 发布 ==\n";
$embedding = new EmbeddingFactory([
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WikiVerifyEmbedding', 'model' => 'verify', 'dimension' => 4]],
]);
$vector    = new VectorFactory(['default' => 'database', 'stores' => ['database' => ['class' => DatabaseVectorStore::class]]]);
$kbService = new KnowledgeBaseService($embedding, $vector);
$ingestion = new IngestionService(new ParserRegistry([]), new SplitterFactory('structure'), $embedding, $vector, $kbService, new SourceFactory([]));
$retrieval = new RetrievalService($embedding, $vector, $kbService);
$bridge    = new RagBridge($kbService, $retrieval, $ingestion);

$llm = new WikiVerifyLlm();
$app->instance(RagBridge::class, $bridge);
$app->instance(AgentBridge::class, new AgentBridge($llm));
purgeWikiServices($app);

$srcKey = 'p1_src_' . substr(md5((string) microtime(true)), 0, 6);

try {
    $srcKb = $kbService->create(['kb_key' => $srcKey, 'name' => 'P1 源库', 'embedding' => 'verify']);
    $created['src_kb_id'] = (int) $srcKb->id;
    $ingestion->ingestText($srcKb, "# A100 手册\n\nA100 功率 250W，支持 PCIe 4.0。\n\n## 散热\n\n采用被动散热设计。", [
        'external_id' => 'a100-manual',
        'title'       => 'A100 手册',
    ]);
    $ingestion->ingestText($srcKb, "# 保修政策\n\n整机保修三年。", [
        'external_id' => 'warranty',
        'title'       => '保修政策',
    ]);
    check('源库摄取两篇文档', (int) Db::name('ai_kb_documents')->where('kb_id', (int) $srcKb->id)->count() === 2);

    $spaces = $app->make(SpaceService::class);
    $space  = $spaces->create([
        'space_key'    => 'p1_verify_' . substr(md5($srcKey), 0, 6),
        'name'         => 'P1 验证空间',
        'source_kb_id' => (int) $srcKb->id,
    ]);
    $created['space_id']    = (int) $space->id;
    $created['shadow_kb_id'] = (int) $space->shadow_kb_id;

    check('空间已建', (int) $space->id > 0);
    check('影子库已建', (int) $space->shadow_kb_id > 0);
    check('影子库 key 带 wiki_ 前缀', (string) $kbService->get((int) $space->shadow_kb_id)->kb_key === 'wiki_' . (string) $space->space_key);
    check('影子库继承源库 embedding', (string) $kbService->get((int) $space->shadow_kb_id)->embedding === 'verify');

    $service = $app->make(CompileService::class);
    $r       = $service->compile(new CompileInput((int) $space->id, 'A100 功率'));

    check('编译成功', ($r['ok'] ?? false) === true && empty($r['skipped']), json_encode($r['errors'] ?? $r));
    check('证据数 ≥ 1', (int) ($r['evidence'] ?? 0) >= 1, (string) ($r['evidence'] ?? 0));
    check('页面已落库', (int) ($r['page_id'] ?? 0) > 0);
    check('默认状态为 review（auto_publish=false）', ($r['status'] ?? '') === WikiPage::STATUS_REVIEW, (string) ($r['status'] ?? ''));
    check('引用覆盖率达标', (float) ($r['coverage'] ?? 0) >= 0.6, (string) ($r['coverage'] ?? 0));
    check('首次编译 rev_no=1', (int) ($r['rev_no'] ?? 0) === 1, (string) ($r['rev_no'] ?? 0));

    $pageId = (int) $r['page_id'];
    $page   = Db::name('ai_wiki_pages')->where('id', $pageId)->find();
    check('页面 slug 已规范化', (string) ($page['slug'] ?? '') === 'a100-power-cooling', (string) ($page['slug'] ?? ''));
    check('页面记录 source_fingerprint', strlen((string) ($page['source_fingerprint'] ?? '')) === 40);
    check('页面已清除脏标记', (string) ($page['dirty_reason'] ?? 'x') === '');
    check('页面记录了质量分', (int) ($page['quality_score'] ?? 0) > 0, (string) ($page['quality_score'] ?? 0));

    $rev = Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->where('rev_no', 1)->find();
    check('版本已写入且记录 prompt_version', is_array($rev) && (string) $rev['prompt_version'] === (string) config('wise-rag.wiki.compile.prompt_version'), (string) ($rev['prompt_version'] ?? ''));

    $deps = Db::name('ai_wiki_dependencies')->where('page_id', $pageId)->select()->toArray();
    check('依赖已写入', $deps !== [], 'count=' . count($deps));
    $hasHash = true;
    foreach ($deps as $d) {
        if (strlen((string) $d['doc_hash']) !== 32 && strlen((string) $d['doc_hash']) !== 40) {
            $hasHash = false;
        }
    }
    check('依赖记录了 doc_hash（增量判定）', $hasHash);

    check(
        'review 状态不进影子库（未审核内容不该被检索）',
        (int) ($r['shadow_doc_id'] ?? -1) === 0 && ($r['publish_reason'] ?? '') === 'not_published',
        (string) ($r['publish_reason'] ?? '')
    );
    check('review 时 shadow_doc_id 为 0', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id') === 0);

    $compileCfg                 = (array) config('wise-rag.wiki.compile', []);
    $compileCfg['auto_publish'] = true;
    $__rag = (array) config('wise-rag');
    $__rag['wiki']['compile'] = $compileCfg;
    \think\facade\Config::set($__rag, 'wise-rag');
    $pub = $service->compile(new CompileInput((int) $space->id, 'A100 功率'));
    check(
        'auto_publish 开启后自动发布',
        ($pub['status'] ?? '') === WikiPage::STATUS_PUBLISHED && (int) ($pub['shadow_doc_id'] ?? 0) > 0,
        json_encode(['status' => $pub['status'] ?? '', 'doc' => $pub['shadow_doc_id'] ?? 0])
    );
    check('页面回写 shadow_doc_id', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id') === (int) ($pub['shadow_doc_id'] ?? 0));

    $shadowHits = $retrieval->retrieve(new RetrievalQuery('A100 功率', null, [(int) $space->shadow_kb_id], 5));
    check('影子库可检索到页面', $shadowHits !== [], 'count=' . count($shadowHits));
    $citeUrl = (string) ($shadowHits[0]->meta['cite']['url'] ?? '');
    check('检索结果 cite.url 指回 wiki 页面', str_contains($citeUrl, '/admin/wiki/page?id='), $citeUrl);
    check('检索结果标题为页面标题', (string) ($shadowHits[0]->meta['cite']['title'] ?? '') === 'A100 功率与散热');

    $job = Db::name('ai_wiki_compile_jobs')->where('page_id', $pageId)->order('id', 'desc')->find();
    check('编译任务记为 ok', (string) ($job['status'] ?? '') === 'ok', (string) ($job['status'] ?? ''));
    check('任务记录 token 用量', (int) ($job['tokens_in'] ?? 0) > 0);
    check('任务记录证据数', (int) ($job['evidence_count'] ?? 0) >= 1);
    check('空间累计 token 已增加', (int) Db::name('ai_wiki_spaces')->where('id', (int) $space->id)->value('tokens_used_month') > 0);
    check('空间页面计数已同步', (int) Db::name('ai_wiki_spaces')->where('id', (int) $space->id)->value('page_count') === 1);

    echo "== 5. 防幻觉 / 重试 ==\n";
    $llm->mode = 'wrong-citation';
    $bad       = $service->compile(new CompileInput((int) $space->id, 'A100 散热'));
    check('幻觉引用被判失败', ($bad['ok'] ?? true) === false);
    $badText = implode('；', (array) ($bad['errors'] ?? []));
    check('失败原因指出不存在的证据编号', str_contains($badText, '99'), $badText);

    $llm->mode = 'unknown-inline';
    $bad2      = $service->compile(new CompileInput((int) $space->id, 'A100 散热'));
    check('正文未知编号被判失败', ($bad2['ok'] ?? true) === false && str_contains(implode('；', (array) ($bad2['errors'] ?? [])), '正文引用了不存在'));

    $llm->mode         = 'ok';
    $llm->serveBadJson = true;
    $before            = $llm->calls;
    $retried           = $service->compile(new CompileInput((int) $space->id, 'A100 功率'));
    check('非 JSON 首次失败后重试成功', ($retried['ok'] ?? false) === true, json_encode($retried['errors'] ?? []));
    check('确实重试了一次（共 2 次调用）', $llm->calls - $before === 2, (string) ($llm->calls - $before));
    check('重试时回灌了上次错误提示', str_contains((string) end($llm->lastMessages)['content'], 'JSON'));

    $llm->mode = 'ok';
    $noEvidence = $service->compile(new CompileInput((int) $space->id, 'zzzqqq 无资料主题'));
    check('无证据主题被跳过', ($noEvidence['skipped'] ?? false) === true && ($noEvidence['reason'] ?? '') === 'no_evidence');

    echo "== 6. 重复建页 / 人工优先 ==\n";
    $compileCfg                 = (array) config('wise-rag.wiki.compile', []);
    $compileCfg['auto_publish'] = false;
    $__rag = (array) config('wise-rag');
    $__rag['wiki']['compile'] = $compileCfg;
    \think\facade\Config::set($__rag, 'wise-rag');
    $llm->mode = 'similar-slug';
    $dup       = $service->compile(new CompileInput((int) $space->id, 'A100 功率'));
    check('相似 slug 被降级为 draft', ($dup['status'] ?? '') === WikiPage::STATUS_DRAFT, (string) ($dup['status'] ?? ''));
    $dupWarnings = implode('；', (array) ($dup['warnings'] ?? []));
    check('给出疑似重复页面提示', str_contains($dupWarnings, '疑似重复页面'), $dupWarnings);
    Db::name('ai_wiki_pages')->where('space_id', (int) $space->id)->where('slug', 'a100-power-coolin')->delete();

    Db::name('ai_wiki_pages')->where('id', $pageId)->update([
        'editor_type' => 'admin',
        'status'      => WikiPage::STATUS_PUBLISHED,
        'content_md'  => "# 人工修订版\n\n人工写的内容。[1]\n",
        'dirty_reason' => WikiPage::DIRTY_DOC_CHANGED,
    ]);
    $originalBody = (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('content_md');

    $llm->mode = 'ok';
    $deferred  = $service->compile(new CompileInput((int) $space->id, 'A100 功率', CompileInput::TRIGGER_MANUAL, $pageId));
    check('人工稿重编译标记 deferred', ($deferred['deferred'] ?? false) === true, json_encode($deferred));
    check('人工正文未被覆盖', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('content_md') === $originalBody);
    check('人工稿状态保持 published', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('status') === WikiPage::STATUS_PUBLISHED);
    $candidateRev = (int) ($deferred['candidate_rev'] ?? 0);
    check('候选版本已生成', $candidateRev > 1 && Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->where('rev_no', $candidateRev)->count() === 1, 'rev=' . $candidateRev);
    check('候选版本带 rebuild_from_sources 原因', (string) Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->where('rev_no', $candidateRev)->value('change_reason') === 'rebuild_from_sources');
    check('deferred 后脏标记已清除', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('dirty_reason') === '');
    check('deferred 后指纹已更新', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('source_fingerprint') !== '');

    echo "== 7. 删除与撤回 ==\n";
    $shadowDocId = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id');
    $pages       = $app->make(PageService::class);
    check('删除页面成功', $pages->delete($pageId) === true);
    check('页面已删除', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->count() === 0);
    check('版本已级联删除', (int) Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->count() === 0);
    check('依赖已级联删除', (int) Db::name('ai_wiki_dependencies')->where('page_id', $pageId)->count() === 0);
    check('影子库文档已撤回', (int) Db::name('ai_kb_documents')->where('id', $shadowDocId)->count() === 0);

    echo "== 8. 代码纪律 ==\n";
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
        if (str_contains($code, "Db::name('ai_kb_") || str_contains($code, 'Db::name("ai_kb_')) {
            $bad[] = basename($path);
        }
        if (preg_match('/use\s+wise\\\\rag\\\\/', $code) === 1) {
            $bad[] = basename($path) . '(wise\\rag)';
        }
    }
    check('src/ 内除 bridge 外无 ai_kb_ 直连与 wise\\rag 引用', $bad === [], implode(',', $bad));
    check('编译命令已注册到 Provider', str_contains((string) file_get_contents($srcDir . '/WikiServiceProvider.php'), 'command\\Compile'));
} catch (\Throwable $e) {
    check('端到端无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    try {
        if ($created['space_id'] > 0) {
            $spaceModel = \wise\wiki\model\WikiSpace::query()->where('id', $created['space_id'])->find();
            if ($spaceModel instanceof \wise\wiki\model\WikiSpace) {
                app(SpaceService::class)->delete($spaceModel, true);
            }
            Db::name('ai_wiki_pages')->where('space_id', $created['space_id'])->delete();
            Db::name('ai_wiki_compile_jobs')->where('space_id', $created['space_id'])->delete();
        }
        if ($created['src_kb_id'] > 0) {
            $kbService->delete((int) $created['src_kb_id'], true);
        }

        $leftSpace = (int) Db::name('ai_wiki_spaces')->where('id', $created['space_id'])->count();
        $leftKb    = (int) Db::name('ai_kb_knowledge_bases')->where('id', $created['shadow_kb_id'])->count();
        check('清理验证数据（空间 + 影子库）', $leftSpace === 0 && $leftKb === 0, "space={$leftSpace}, kb={$leftKb}");
    } catch (\Throwable $e) {
        check('清理验证数据（空间 + 影子库）', false, $e->getMessage());
    }
}

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P1 验证通过 ✅\n" : "P1 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
