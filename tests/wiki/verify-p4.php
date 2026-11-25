<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 工具注册：4 个 wiki.* 工具进 ToolRegistry，getName() === 注册键（可被 LLM invoke）
 *   2. 工具元数据：读=read/low/免确认；写=write|execute/medium/需确认；权限标识正确
 *   3. wiki.search：page_first 命中页面即不查源库；分不足回退；both_merged；参数校验
 *   4. wiki.get：slug / page_id 两种入口 + 不存在报错
 *   5. wiki.upsert：只能写 draft、已发布页降级并撤下影子文档、人工发布页拒绝覆盖
 *   6. wiki.compile：真实走编译管线（假 LLM）
 *   7. MCP：只读工具两个 + 资源 index/page 可读 + 关闭开关归零
 *   8. 扩展点注册与代码纪律
 *
 * 用法：php src/packages/wiseadmin/wise-llm-wiki/tests/verify-p4.php
 * 退出码：0 全部通过；1 存在失败
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use think\facade\Config;
use think\facade\Db;
use wise\agent\contract\AiClientInterface;
use wise\agent\tool\ToolRegistry;
use wise\mcp\contract\ResourceProviderInterface;
use wise\mcp\contract\ToolProviderInterface;
use wise\rag\client\EmbeddingFactory;
use wise\rag\parser\ParserRegistry;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\source\SourceFactory;
use wise\rag\splitter\SplitterFactory;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\wiki\bridge\AgentBridge;
use wise\wiki\bridge\RagBridge;
use wise\wiki\mcp\WikiResourceProvider;
use wise\wiki\mcp\WikiToolProvider;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;
use wise\wiki\security\WikiPermissionProvider;
use wise\wiki\service\CompileService;
use wise\wiki\service\PageService;
use wise\wiki\service\SpaceService;
use wise\wiki\service\WikiSearchService;
use wise\wiki\tool\WikiToolRegistrar;

class WikiP4Embedding implements \wise\rag\contract\EmbeddingInterface
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

class WikiP4Llm implements AiClientInterface
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
            'summary'  => 'A100 功率 250W，采用被动散热设计。',
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
            'usage'      => ['prompt_tokens' => 90, 'completion_tokens' => 45],
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
    'providers' => ['verify' => ['class' => 'WikiP4Embedding', 'model' => 'verify', 'dimension' => 4]],
]);
$vector    = new VectorFactory(['default' => 'database', 'stores' => ['database' => ['class' => DatabaseVectorStore::class]]]);
$kbService = new KnowledgeBaseService($embedding, $vector);
$ingestion = new IngestionService(new ParserRegistry([]), new SplitterFactory('structure'), $embedding, $vector, $kbService, new SourceFactory([]));
$retrieval = new RetrievalService($embedding, $vector, $kbService);
$bridge    = new RagBridge($kbService, $retrieval, $ingestion);

setWikiCfg('queue', ['enabled' => false]);
setWikiCfg('compile', ['auto_publish' => true]);

try {
    echo "== 0. 准备 ==\n";

    $srcKb = $kbService->create(['kb_key' => 'p4_src_' . substr(md5((string) microtime(true)), 0, 6), 'name' => 'P4 源库', 'embedding' => 'verify']);
    $created['src_kb_id'] = (int) $srcKb->id;
    $ingestion->ingestText($srcKb, "# A100 手册\n\nA100 功率 250W，支持 PCIe 4.0 与 NVLink 互联。\n\n## 散热\n\n采用被动散热设计，满载温度控制在 75 度以内。", [
        'external_id' => 'p4-a100',
        'title'       => 'A100 手册',
    ]);
    check('源库已索引', (int) Db::name('ai_kb_chunks')->where('kb_id', (int) $srcKb->id)->count() >= 1);

    $agentBridge = new AgentBridge(new WikiP4Llm());
    $app->instance(RagBridge::class, $bridge);
    $app->instance(AgentBridge::class, $agentBridge);

    $search    = new WikiSearchService($bridge);
    $publisher = new \wise\wiki\compile\ShadowPublisher($bridge);
    $writer    = new \wise\wiki\compile\PageWriter();
    $pages     = new PageService($publisher, $writer);
    $spaces    = new SpaceService($bridge);
    $validator = new \wise\wiki\compile\OutputValidator();
    $collector = new \wise\wiki\compile\EvidenceCollector($bridge);
    $compiler  = new \wise\wiki\compile\WikiCompiler($agentBridge, new \wise\wiki\compile\PromptBuilder());
    $compile   = new CompileService($spaces, $pages, $collector, $compiler, $validator, $writer, $publisher);

    $space  = $spaces->create([
        'space_key'    => 'p4_verify_' . substr(md5((string) $srcKb->id), 0, 6),
        'name'         => 'P4 验证空间',
        'source_kb_id' => (int) $srcKb->id,
    ]);
    $created['space_id']     = (int) $space->id;
    $created['shadow_kb_id'] = (int) $space->shadow_kb_id;
    $spaceKey                = (string) $space->space_key;

    $first = $compile->compile(new \wise\wiki\dto\CompileInput((int) $space->id, 'A100 功率'));
    $pageId  = (int) ($first['page_id'] ?? 0);
    $created['page_id'] = $pageId;

    $shadowChunks = (int) Db::name('ai_kb_chunks')->where('kb_id', (int) $space->shadow_kb_id)->count();
    check('初始页面已发布并进入影子库', $pageId > 0 && (int) ($first['shadow_doc_id'] ?? 0) > 0);
    check('影子库已有可检索分块', $shadowChunks >= 1, 'chunks=' . $shadowChunks);
    check(
        '影子库可直接检索到页面',
        $bridge->retrieve('A100 功率', [(int) $space->shadow_kb_id], 5) !== [],
        'shadow_kb=' . (int) $space->shadow_kb_id
    );

    echo "== 1. 工具注册 ==\n";

    $tools = new WikiToolRegistrar($search, $pages, $spaces, $compile);
    $count = $tools->registerAll();
    $registry = ToolRegistry::instance();

    check('wise-agent 工具链可用', WikiToolRegistrar::available());
    check('注册 4 个工具', $count === 4, (string) $count);

    $names = [
        WikiToolRegistrar::TOOL_SEARCH,
        WikiToolRegistrar::TOOL_GET,
        WikiToolRegistrar::TOOL_UPSERT,
        WikiToolRegistrar::TOOL_COMPILE,
    ];
    $missing = array_values(array_filter($names, static fn (string $n): bool => !$registry->has($n)));
    check('四个工具键都存在', $missing === [], implode(',', $missing));

    $schemaNames = array_map(static fn (array $s): string => (string) ($s['function']['name'] ?? ''), $registry->getToolSchemas());
    $notExposed  = array_values(array_filter($names, static fn (string $n): bool => !in_array($n, $schemaNames, true)));
    check('getName() === 注册键（可被 LLM invoke）', $notExposed === [], implode(',', $notExposed));

    $searchTool = $registry->get(WikiToolRegistrar::TOOL_SEARCH);
    check(
        'wiki.search 元数据（read/low/免确认/wiki_read）',
        $searchTool !== null
        && $searchTool->getAccess() === 'read'
        && $searchTool->getRiskLevel() === 'low'
        && $searchTool->requireConfirmation() === false
        && $searchTool->getPermission() === WikiPermissionProvider::PERMISSION_READ
    );

    $upsertTool = $registry->get(WikiToolRegistrar::TOOL_UPSERT);
    check(
        'wiki.upsert 元数据（write/medium/需确认/wiki_write）',
        $upsertTool !== null
        && $upsertTool->getAccess() === 'write'
        && $upsertTool->getRiskLevel() === 'medium'
        && $upsertTool->requireConfirmation() === true
        && $upsertTool->getPermission() === WikiPermissionProvider::PERMISSION_WRITE
    );

    $compileTool = $registry->get(WikiToolRegistrar::TOOL_COMPILE);
    check(
        'wiki.compile 元数据（execute/medium/需确认）',
        $compileTool !== null
        && $compileTool->getAccess() === 'execute'
        && $compileTool->getRiskLevel() === 'medium'
        && $compileTool->requireConfirmation() === true
    );

    echo "== 2. wiki.search 检索策略 ==\n";

    $raw = $registry->execute(WikiToolRegistrar::TOOL_SEARCH, ['query' => 'A100 功率', 'space' => [$spaceKey]]);
    $res = json_decode($raw, true);

    check('执行返回 JSON 且命中', is_array($res) && ($res['count'] ?? 0) >= 1, mb_substr($raw, 0, 200));
    check('page_first 命中页面结论', ($res['pages'] ?? []) !== [], json_encode($res['pages'][0]['slug'] ?? ''));
    check('page_first 不再返回源碎片', ($res['chunks'] ?? []) === [], (string) count($res['chunks'] ?? []));
    check('页面命中含 slug/rev/url', ($res['pages'][0]['slug'] ?? '') === 'a100-power-cooling'
        && (int) ($res['pages'][0]['rev_no'] ?? 0) >= 1
        && str_contains((string) ($res['pages'][0]['url'] ?? ''), '/admin/wiki/page?id='));
    check('page_first 未回退', ($res['fallback'] ?? true) === false);

    $merged = json_decode($registry->execute(WikiToolRegistrar::TOOL_SEARCH, [
        'query'    => 'A100 功率',
        'space'    => [$spaceKey],
        'strategy' => 'both_merged',
    ]), true);
    check('both_merged 同时尝试两路', is_array($merged) && ($merged['strategy'] ?? '') === 'both_merged');

    $noHit = json_decode($registry->execute(WikiToolRegistrar::TOOL_SEARCH, [
        'query' => 'zzzqqq 完全无关的内容',
        'space' => [$spaceKey],
    ]), true);
    check(
        '分不足时回退源库（fallback=true）',
        is_array($noHit) && (($noHit['fallback'] ?? false) === true || (int) ($noHit['count'] ?? 0) === 0),
        json_encode(['fallback' => $noHit['fallback'] ?? null, 'count' => $noHit['count'] ?? null])
    );

    $badSpace = json_decode($registry->execute(WikiToolRegistrar::TOOL_SEARCH, [
        'query' => 'A100',
        'space' => ['not_exists_space'],
    ]), true);
    check('限定不存在的空间返回 0 条', is_array($badSpace) && (int) ($badSpace['count'] ?? -1) === 0);

    $emptyQuery = json_decode($registry->execute(WikiToolRegistrar::TOOL_SEARCH, ['query' => '   ']), true);
    check('空 query 返回可读错误', is_array($emptyQuery) && isset($emptyQuery['error']));

    echo "== 3. wiki.get ==\n";

    $bySlug = json_decode($registry->execute(WikiToolRegistrar::TOOL_GET, [
        'slug'  => 'a100-power-cooling',
        'space' => $spaceKey,
    ]), true);
    check('按 slug 读取全文', is_array($bySlug) && ($bySlug['title'] ?? '') !== '' && str_contains((string) ($bySlug['content_md'] ?? ''), 'A100'));

    $byId = json_decode($registry->execute(WikiToolRegistrar::TOOL_GET, ['page_id' => $pageId]), true);
    check('按 page_id 读取全文', is_array($byId) && (int) ($byId['id'] ?? 0) === $pageId);
    check('返回引用编号汇总', is_array($byId) && ($byId['citations'] ?? []) !== []);

    $notFound = json_decode($registry->execute(WikiToolRegistrar::TOOL_GET, ['slug' => 'no-such-page']), true);
    check('页面不存在时返回错误', is_array($notFound) && isset($notFound['error']));

    $noArgs = json_decode($registry->execute(WikiToolRegistrar::TOOL_GET, []), true);
    check('缺少 slug/page_id 时返回错误', is_array($noArgs) && isset($noArgs['error']));

    echo "== 4. wiki.upsert（只能写 draft）==\n";

    $createdRes = json_decode($registry->execute(WikiToolRegistrar::TOOL_UPSERT, [
        'space'      => $spaceKey,
        'title'      => 'Agent 新页面',
        'content_md' => "# Agent 页面\n\n这是 Agent 写入的草稿内容。[1]\n",
        'summary'    => 'Agent 摘要',
    ]), true);

    check('Agent 新建成功', is_array($createdRes) && ($createdRes['created'] ?? false) === true, json_encode($createdRes));
    check('Agent 新建为 draft', ($createdRes['status'] ?? '') === WikiPage::STATUS_DRAFT);
    check('Agent 新建后不进影子库', (int) Db::name('ai_wiki_pages')->where('id', (int) ($createdRes['page_id'] ?? 0))->value('shadow_doc_id') === 0);
    check('返回提示需人工发布', str_contains((string) ($createdRes['note'] ?? ''), '草稿'));

    $override = json_decode($registry->execute(WikiToolRegistrar::TOOL_UPSERT, [
        'space'      => $spaceKey,
        'slug'       => 'a100-power-cooling',
        'title'      => 'A100 功率与散热',
        'content_md' => "# A100\n\nAgent 改写的正文。[1]\n",
    ]), true);

    check('Agent 覆盖已发布页成功', is_array($override) && ($override['created'] ?? true) === false, json_encode($override));
    check('覆盖后降级为 draft', ($override['status'] ?? '') === WikiPage::STATUS_DRAFT);
    check('覆盖后影子文档已撤下', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id') === 0);

    $draftRead = json_decode($registry->execute(WikiToolRegistrar::TOOL_GET, ['page_id' => $pageId]), true);
    check('wiki.get 拒绝读取未发布页面', is_array($draftRead) && isset($draftRead['error']));

    $current = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('rev_no');
    $pages->saveManual($pageId, [
        'title'      => 'A100 人工版',
        'content_md' => "# A100\n\n人工确认过的正文，Agent 不可覆盖。[1]\n",
        'status'     => WikiPage::STATUS_PUBLISHED,
    ], $current, 9);

    $humanGuard = json_decode($registry->execute(WikiToolRegistrar::TOOL_UPSERT, [
        'space'      => $spaceKey,
        'slug'       => 'a100-power-cooling',
        'title'      => 'A100 人工版',
        'content_md' => "# 恶意覆盖\n\nx\n",
    ]), true);
    check('人工发布页拒绝 Agent 覆盖', is_array($humanGuard) && str_contains((string) ($humanGuard['error'] ?? ''), '人工编辑'));

    $missingArgs = json_decode($registry->execute(WikiToolRegistrar::TOOL_UPSERT, ['space' => $spaceKey, 'title' => 'x']), true);
    check('缺少参数返回错误', is_array($missingArgs) && isset($missingArgs['error']));

    echo "== 5. wiki.compile ==\n";

    $compileRes = json_decode($registry->execute(WikiToolRegistrar::TOOL_COMPILE, [
        'space' => $spaceKey,
        'topic' => 'A100 功率',
    ]), true);

    check('工具触发编译成功', is_array($compileRes) && ($compileRes['ok'] ?? false) === true, json_encode($compileRes['errors'] ?? []));
    check('编译结果含页面与证据数', (int) ($compileRes['page_id'] ?? 0) > 0 && (int) ($compileRes['evidence'] ?? 0) >= 1);

    $badCompile = json_decode($registry->execute(WikiToolRegistrar::TOOL_COMPILE, ['space' => 'no_such_space', 'topic' => 'x']), true);
    check('空间不存在时返回错误', is_array($badCompile) && isset($badCompile['error']));

    echo "== 6. MCP 外溢 ==\n";

    $mcpTools = new WikiToolProvider($search);
    $list     = $mcpTools->listTools();
    check('MCP 暴露 2 个只读工具', count($list) === 2, (string) count($list));
    check('MCP namespace = wiki', $mcpTools->getNamespace() === 'wiki');

    $mcpSearch = json_decode($list[0]->call(['query' => 'A100 功率', 'space' => [$spaceKey]]), true);
    check('MCP wiki.search 可执行', is_array($mcpSearch) && ($mcpSearch['count'] ?? 0) >= 1, mb_substr(json_encode($mcpSearch), 0, 160));

    $mcpResources = new WikiResourceProvider();
    $resources    = $mcpResources->listResources();
    $uris         = array_map(static fn ($r): string => (string) $r->uri, $resources);

    check('MCP 资源含 wiki://index', in_array('wiki://index', $uris, true), implode(',', array_slice($uris, 0, 5)));
    check('MCP 资源含页面级 URI', count(array_filter($uris, static fn (string $u): bool => str_contains($u, '/page/'))) >= 1);

    $indexContent = json_decode($resources[0]->read(), true);
    check('wiki://index 可读且含页面', is_array($indexContent) && (int) ($indexContent['count'] ?? 0) >= 1);

    $pageUri  = '';
    $pageRes  = null;
    foreach ($resources as $r) {
        if (str_contains((string) $r->uri, '/page/')) {
            $pageUri = (string) $r->uri;
            $pageRes = $r;
            break;
        }
    }
    check('页面资源可读（Markdown）', $pageRes !== null && str_contains($pageRes->read(), '#'), $pageUri);

    setWikiCfg('mcp', ['expose_tools' => false, 'expose_resources' => false]);
    check('关闭开关后 MCP 工具为空', (new WikiToolProvider($search))->listTools() === []);
    check('关闭开关后 MCP 资源为空', (new WikiResourceProvider())->listResources() === []);
    setWikiCfg('mcp', ['expose_tools' => true, 'expose_resources' => true]);

    echo "== 7. 扩展点与纪律 ==\n";

    $permPoints = array_keys(ExtPoint::all(ExtPoints::AI_TOOL_PERMISSION_PROVIDER));
    check('权限 provider 已注册到扩展点', in_array('wise-rag.wiki', $permPoints, true), implode(',', $permPoints));

    $toolPoints = array_keys(ExtPoint::all(ToolProviderInterface::EXT_POINT));
    check('MCP 工具 provider 已注册', in_array('wise-rag.wiki', $toolPoints, true), implode(',', $toolPoints));

    $resPoints = array_keys(ExtPoint::all(ResourceProviderInterface::EXT_POINT));
    check('MCP 资源 provider 已注册', in_array('wise-rag.wiki', $resPoints, true), implode(',', $resPoints));

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
    check('P4 端到端无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    try {
        if ($created['space_id'] > 0) {
            $model = WikiSpace::query()->where('id', $created['space_id'])->find();
            if ($model instanceof WikiSpace) {
                app(SpaceService::class)->delete($model, true);
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
echo count($failures) === 0 ? "P4 验证通过 ✅\n" : "P4 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
