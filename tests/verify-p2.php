<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 权限提供者：collect() 结构 + 扩展点注册
 *   2. 端到端：建库 → 摄取（假 Embedding，离线）→ 注册检索工具 → 经 ToolRegistry 执行 → 引用溯源
 *   3. 事件：DocumentIndexed / RetrievalPerformed
 *   4. 命名一致性：LLM 函数名 === 注册键（保证工具可被调用）
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p2.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\facade\Db;
use think\facade\Event;
use wise\agent\tool\ToolRegistry;
use wise\rag\client\EmbeddingFactory;
use wise\rag\event\DocumentIndexed;
use wise\rag\event\RetrievalPerformed;
use wise\rag\parser\ParserRegistry;
use wise\rag\security\KnowledgePermissionProvider;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\source\SourceFactory;
use wise\rag\splitter\SplitterFactory;
use wise\rag\tool\KnowledgeToolRegistrar;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;

require __DIR__ . '/../../../../vendor/autoload.php';

/**
 * 离线假 Embedding：恒定返回 4 维向量，避免验证脚本依赖真实 API。
 */
class WiseRagVerifyEmbedding implements \wise\rag\contract\EmbeddingInterface
{
    public function embed(array $texts, array $options = []): array
    {
        return array_fill(0, count($texts), [1.0, 0.0, 0.0, 0.0]);
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

$app = new \think\App();
$app->initialize();

$failures = [];
$checks   = 0;

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

echo "== 1. 权限提供者 ==\n";
$provider = new KnowledgePermissionProvider();
$defs     = $provider->collect();
$ident    = KnowledgePermissionProvider::PERMISSION;

check('权限定义含 ai.tool.kb_search', isset($defs[$ident]));
check('access=read', ($defs[$ident]['access'] ?? '') === 'read');
check('plugin=wise-rag', ($defs[$ident]['plugin'] ?? '') === 'wise-rag');

if (class_exists(\wise\support\ExtPoint::class) && class_exists(\wise\support\ExtPoints::class)) {
    $point = \wise\support\ExtPoints::AI_TOOL_PERMISSION_PROVIDER;
    $registered = [];
    try {
        $registered = array_keys(\wise\support\ExtPoint::all($point));
    } catch (\Throwable $e) {
    }
    check('扩展点已注册 wise-rag（RagServiceProvider::boot 生效）', in_array('wise-rag', $registered, true), implode(',', $registered));
} else {
    check('wise-support ExtPoint 可用', false);
}

echo "== 1.5 Web 路由鉴权（D：中间件默认挂载） ==\n";

$rbac = \wise\auth\middleware\RbacMiddleware::class;
check('wise-auth RbacMiddleware 可用', class_exists($rbac));

$adminMiddleware = (array) config('wise-rag.web.groups.admin.middleware', []);
check('配置默认挂载 RbacMiddleware', in_array($rbac, $adminMiddleware, true), implode(',', $adminMiddleware));

if (class_exists(\wise\support\ExtPoints::class)) {
    $desc    = \wise\rag\RouteRegistrar::describe(\wise\support\ExtPoints::ROUTE_ADMIN);
    $mwCount = (int) ($desc['wise-rag:admin']['middleware'] ?? 0);
    check('已落到 admin 路由分组的中间件链', $mwCount >= 1, 'count=' . $mwCount);
}

if (!KnowledgeToolRegistrar::available()) {
    echo "!! 未检测到 wise-agent，跳过工具注册与执行检查\n";
} else {
    echo "== 2. 端到端（建库 → 摄取 → 工具检索） ==\n";

    $GLOBALS['rag_doc_indexed'] = 0;
    $GLOBALS['rag_retrieval']   = 0;
    Event::listen(DocumentIndexed::class, static function (DocumentIndexed $e): void {
        $GLOBALS['rag_doc_indexed'] = $e->chunkCount;
    });
    Event::listen(RetrievalPerformed::class, static function (RetrievalPerformed $e): void {
        $GLOBALS['rag_retrieval'] = $e->hitCount;
    });

    $embedding = new EmbeddingFactory([
        'default'   => 'verify',
        'providers' => [
            'verify' => ['class' => 'WiseRagVerifyEmbedding', 'model' => 'verify', 'dimension' => 4],
        ],
    ]);
    $vector     = new VectorFactory([
        'default' => 'database',
        'stores'  => ['database' => ['class' => DatabaseVectorStore::class]],
    ]);
    $kbService  = new KnowledgeBaseService($embedding, $vector);
    $ingestion  = new IngestionService(new ParserRegistry([]), new SplitterFactory('structure'), $embedding, $vector, $kbService, new SourceFactory([]));
    $retrieval  = new RetrievalService($embedding, $vector, $kbService);
    $registrar  = new KnowledgeToolRegistrar($kbService, $retrieval);

    $kbKey = 'p2_verify_' . substr(md5((string) microtime(true)), 0, 6);
    $kbId  = 0;

    try {
        $kb   = $kbService->create(['kb_key' => $kbKey, 'name' => 'P2 验证库', 'embedding' => 'verify']);
        $kbId = (int) $kb->id;
        check('建库（假 Embedding 维度=4）', $kbId > 0 && (int) $kb->dimension === 4, (string) $kb->dimension);

        $docId = $ingestion->ingestText(
            $kb,
            "# A100 手册\n\nA100 功率 250W，支持 PCIe 4.0。\n\n## 散热\n\n被动散热设计。",
            ['external_id' => 'verify-doc', 'title' => 'A100 手册']
        );
        check('摄取成功', $docId > 0);
        check('派发 DocumentIndexed', ($GLOBALS['rag_doc_indexed'] ?? 0) >= 1, (string) ($GLOBALS['rag_doc_indexed'] ?? 0));

        $count = $registrar->registerAll();
        $tool  = KnowledgeToolRegistrar::toolName($kbKey);
        $registry = ToolRegistry::instance();

        check('注册 kb.search + 专属工具', $count === 2 && $registry->has(KnowledgeToolRegistrar::GLOBAL_TOOL) && $registry->has($tool), "count={$count}");
        check('专属工具名已安全化', preg_match('/^kb\.[A-Za-z0-9_-]+\.search$/', $tool) === 1, $tool);

        $toolObj = $registry->get($tool);
        check('工具元数据（read/low/kb_search/免确认）',
            $toolObj !== null
            && $toolObj->getAccess() === 'read'
            && $toolObj->getRiskLevel() === 'low'
            && $toolObj->getPermission() === 'ai.tool.kb_search'
            && $toolObj->requireConfirmation() === false
            && $toolObj->getDescription() !== ''
        );

        $schemaNames = array_map(static fn (array $s): string => (string) ($s['function']['name'] ?? ''), $registry->getToolSchemas());
        check('LLM 函数名 === 注册键（可被 invoke）',
            in_array($tool, $schemaNames, true) && array_key_exists($tool, $registry->all()),
            implode(',', array_filter($schemaNames, static fn (string $n): bool => str_starts_with($n, 'kb.')))
        );

        $raw = $registry->execute($tool, ['query' => 'A100 功率是多少', 'top_k' => 3]);
        $out = json_decode($raw, true);
        check('工具执行返回 JSON 结果', is_array($out) && ($out['count'] ?? 0) >= 1, mb_substr((string) $raw, 0, 160));
        check('结果含引用标题', ($out['results'][0]['source']['title'] ?? '') === 'A100 手册', (string) ($out['results'][0]['source']['title'] ?? ''));
        check('结果含命中分数', isset($out['results'][0]['score']) && $out['results'][0]['score'] > 0.9, (string) ($out['results'][0]['score'] ?? ''));
        check('派发 RetrievalPerformed', ($GLOBALS['rag_retrieval'] ?? 0) >= 1, (string) ($GLOBALS['rag_retrieval'] ?? 0));

        $bad = $registry->execute($tool, ['query' => '   ']);
        check('空 query 友好返回错误', str_contains($bad, 'query 不能为空'));

        $globalRaw = $registry->execute(KnowledgeToolRegistrar::GLOBAL_TOOL, ['query' => 'A100']);
        $globalOut = json_decode($globalRaw, true);
        check('全库工具 kb.search 可用', is_array($globalOut) && ($globalOut['count'] ?? 0) >= 1, mb_substr((string) $globalRaw, 0, 120));
    } catch (\Throwable $e) {
        check('端到端无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    } finally {
        if ($kbId > 0) {
            try {
                $kbService->delete($kbId, true);
                check('清理验证数据', Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->count() === 0);
            } catch (\Throwable $e) {
                check('清理验证数据', false, $e->getMessage());
            }
        }
    }
}

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P2 验证通过 ✅\n" : "P2 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
