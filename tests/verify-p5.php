<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. MCP 工具：ExtPoint 注册 → CapabilityRegistry 聚合 → 经 ToolDefinition 执行（含错误结构）
 *   2. MCP 资源：kb://index、kb://{kb_key}/docs、kb://{kb_key}/doc/{id} 可列表且可读取
 *   3. 语义缓存：命中不回源、落库、工厂按配置装饰
 *   4. 评测器：hit_rate / recall / MRR 计算与评测集装载
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p5.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\facade\Config;
use think\facade\Db;
use wise\mcp\registry\CapabilityRegistry;
use wise\rag\cache\CachedEmbeddingClient;
use wise\rag\cache\DatabaseEmbeddingCache;
use wise\rag\cache\EmbeddingCacheFactory;
use wise\rag\client\EmbeddingFactory;
use wise\rag\eval\Evaluator;
use wise\rag\keyword\KeywordDriverFactory;
use wise\rag\rerank\RerankerFactory;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;
use wise\rag\vector\VectorMath;

require __DIR__ . '/../../../../vendor/autoload.php';

/** 计数用假 Embedding：恒定 4 维向量，便于断言缓存命中与检索离线可用 */
class WiseRagP5Embedding implements \wise\rag\contract\EmbeddingInterface
{
    public static int $calls = 0;

    public static int $texts = 0;

    public function embed(array $texts, array $options = []): array
    {
        self::$calls++;
        self::$texts += count($texts);

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

$ragConfig                        = (array) config('wise-rag');
$ragConfig['embedding']           = [
    'source'    => 'own',
    'default'   => 'verify',
    'providers' => [
        'verify' => ['class' => 'WiseRagP5Embedding', 'model' => 'verify', 'dimension' => 4],
    ],
];
$ragConfig['cache']['enabled']    = false;
$ragConfig['mcp']['expose_tools'] = true;
Config::set(['wise-rag' => $ragConfig]);

$embedding = new EmbeddingFactory((array) $ragConfig['embedding']);
$vector    = new VectorFactory(['default' => 'database', 'stores' => ['database' => ['class' => DatabaseVectorStore::class]]]);
$keyword   = new KeywordDriverFactory(['driver' => 'mysql_fulltext', 'fallback' => 'like']);
$kbService = new KnowledgeBaseService($embedding, $vector);
$retrieval = new RetrievalService($embedding, $vector, $kbService, $keyword, new RerankerFactory([]));

$app->instance(\wise\rag\client\EmbeddingFactory::class, $embedding);
$app->instance(KnowledgeBaseService::class, $kbService);
$app->instance(RetrievalService::class, $retrieval);

$kbId   = 0;
$docId  = 0;
$kbKey  = 'p5_verify_' . substr(md5((string) microtime(true)), 0, 6);
$tmpEval = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_rag_p5_eval.json';

try {
    $kb   = $kbService->create(['kb_key' => $kbKey, 'name' => 'P5 验证库', 'embedding' => 'verify']);
    $kbId = (int) $kb->id;

    $now   = date('Y-m-d H:i:s');
    $docId = (int) Db::name('ai_kb_documents')->insertGetId([
        'kb_id' => $kbId, 'source_id' => 0, 'external_id' => 'p5-doc', 'title' => 'A100 手册',
        'mime' => 'text/markdown', 'file_path' => null, 'url' => '/doc/a100',
        'content_hash' => md5('p5'), 'char_count' => 40, 'chunk_count' => 2,
        'owner_type' => 'admin', 'owner_id' => 0, 'status' => 'indexed', 'error' => null, 'meta' => null,
        'create_time' => $now, 'update_time' => $now,
    ]);

    foreach ([
        'A100 功率 250W，支持 PCIe 4.0。',
        'A100 散热说明：被动散热设计。',
    ] as $i => $content) {
        Db::name('ai_kb_chunks')->insert([
            'kb_id' => $kbId, 'doc_id' => $docId, 'chunk_index' => $i, 'content' => $content,
            'token_count' => mb_strlen($content), 'heading' => null, 'page' => null,
            'content_hash' => md5($content), 'vector' => VectorMath::pack([1.0, 0.0, 0.0, 0.0]),
            'dimension' => 4, 'owner_type' => 'admin', 'owner_id' => 0,
            'create_time' => $now, 'update_time' => $now,
        ]);
    }

    echo "== 1. MCP 工具（外溢 kb.search） ==\n";
    $pointNames = [];
    if (class_exists(\wise\support\ExtPoint::class)) {
        $pointNames = array_keys(\wise\support\ExtPoint::all(\wise\mcp\contract\ToolProviderInterface::EXT_POINT));
    }
    check('MCP 工具提供者已注册 wise-rag', in_array('wise-rag', $pointNames, true), implode(',', $pointNames));

    $registry = new CapabilityRegistry();
    $tools    = $registry->tools();
    check('CapabilityRegistry 聚合到 kb.search', isset($tools['kb.search']), implode(',', array_slice(array_keys($tools), 0, 5)));

    $tool = $registry->getTool('kb.search');
    check('工具元数据（kb_search 权限 / 免确认）', $tool !== null
        && $tool->getPermission() === 'ai.tool.kb_search'
        && $tool->requiresConfirmation() === false);

    $raw = (string) $tool->call(['query' => 'A100 功率', 'top_k' => 3]);
    $out = json_decode($raw, true);
    check('MCP 工具端到端执行返回结果', is_array($out) && ($out['count'] ?? 0) >= 1, mb_substr($raw, 0, 160));
    check('结果含引用标题', ($out['results'][0]['source']['title'] ?? '') === 'A100 手册');
    check('结果含引用 kb_key', ($out['results'][0]['source']['kb_key'] ?? '') === $kbKey);

    $bad = (string) $tool->call(['query' => '   ']);
    check('空 query 返回 error 结构', str_contains($bad, '"error"'));

    echo "== 2. MCP 资源（外溢知识库） ==\n";
    $resources = $registry->resources();
    $resourceNames = array_keys($resources);

    check('资源含 kb://index', isset($resources['kb://index']), implode(',', array_slice($resourceNames, 0, 5)));
    check('资源含 kb://{kb_key}/docs', isset($resources["kb://{$kbKey}/docs"]));
    check('资源含文档级 kb://{kb_key}/doc/{id}', isset($resources["kb://{$kbKey}/doc/{$docId}"]));

    $indexJson = json_decode($resources['kb://index']->read(), true);
    $found     = false;
    foreach ((array) ($indexJson['knowledge_bases'] ?? []) as $row) {
        if (($row['kb_key'] ?? '') === $kbKey) {
            $found = true;
        }
    }
    check('kb://index 内容包含该知识库', $found);

    $docText = $resources["kb://{$kbKey}/doc/{$docId}"]->read();
    check('文档资源可读取全文', str_contains($docText, 'A100') && str_contains($docText, '250W'), mb_substr($docText, 0, 60));
    check('资源描述含 kb_key', str_contains($resources["kb://{$kbKey}/docs"]->description, $kbKey));

    echo "== 3. 语义缓存 ==\n";
    try {
        Db::name('ai_kb_embedding_cache')->where('model', 'verify')->delete();
    } catch (\Throwable $e) {
        check('向量缓存表已迁移', false, $e->getMessage());
    }

    WiseRagP5Embedding::$calls = 0;
    $cached = new CachedEmbeddingClient(new WiseRagP5Embedding(), new DatabaseEmbeddingCache([]));

    $first  = $cached->embed(['缓存命中测试文本']);
    $second = $cached->embed(['缓存命中测试文本']);

    check('首次未命中 → 回源一次', WiseRagP5Embedding::$calls === 1, 'calls=' . WiseRagP5Embedding::$calls);
    check('二次命中 → 不再回源', WiseRagP5Embedding::$calls === 1, 'calls=' . WiseRagP5Embedding::$calls);
    check('两次返回向量一致', $first === $second && $first[0] !== []);
    check('缓存已落库', (int) Db::name('ai_kb_embedding_cache')->where('model', 'verify')->count() === 1);

    $stats = $cached->stats();
    check('命中/未命中统计正确', $stats['hits'] === 1 && $stats['misses'] === 1, json_encode($stats));

    $cacheFactory = new EmbeddingCacheFactory([
        'enabled' => true,
        'driver'  => 'database',
        'drivers' => ['database' => ['class' => DatabaseEmbeddingCache::class]],
    ]);
    $factoryOn = new EmbeddingFactory([
        'source'    => 'own',
        'default'   => 'verify',
        'providers' => ['verify' => ['class' => 'WiseRagP5Embedding', 'model' => 'verify', 'dimension' => 4]],
    ], $cacheFactory);

    check('cache.enabled=true → 客户端被装饰', str_ends_with($factoryOn->make('verify')->getName(), '+cache'));
    check('cache.enabled=false → 不装饰', !str_ends_with($embedding->make('verify')->getName(), '+cache'), $embedding->make('verify')->getName());

    echo "== 4. 检索评测 ==\n";
    file_put_contents($tmpEval, json_encode([
        'cases' => [
            ['query' => 'A100 功率是多少', 'expect' => ['250W'], 'kb' => [$kbKey], 'top_k' => 3],
            ['query' => '完全无关的问题', 'expect' => ['不存在的关键词xyz'], 'kb' => [$kbKey], 'top_k' => 3],
        ],
    ], JSON_UNESCAPED_UNICODE));

    $cases = Evaluator::loadCases($tmpEval);
    check('评测集装载（cases 包裹格式）', count($cases) === 2, 'count=' . count($cases));

    $result = (new Evaluator($retrieval, $kbService))->run($cases, 3);
    check('评测用例数 2', (int) $result['summary']['cases'] === 2);
    check('命中率 = 0.5', abs((float) $result['summary']['hit_rate'] - 0.5) < 1e-9, (string) $result['summary']['hit_rate']);
    check('首例命中且 MRR > 0', (int) $result['cases'][0]['hit'] === 1 && (float) $result['cases'][0]['mrr'] > 0, json_encode($result['cases'][0]));
    check('次例未命中', (int) $result['cases'][1]['hit'] === 0, json_encode($result['cases'][1]['matched'] ?? null));
    check('含平均耗时指标', isset($result['summary']['avg_latency_ms']));
} catch (\Throwable $e) {
    check('P5 主流程无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    echo "== 5. 清理 ==\n";
    if ($kbId > 0) {
        try {
            $kbService->delete($kbId, true);
        } catch (\Throwable $e) {
        }
    }
    try {
        Db::name('ai_kb_embedding_cache')->where('model', 'verify')->delete();
    } catch (\Throwable $e) {
    }
    @unlink($tmpEval);

    check('知识库已清理', Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->count() === 0);
    check('缓存测试数据已清理', (int) Db::name('ai_kb_embedding_cache')->where('model', 'verify')->count() === 0);
}

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P5 验证通过 ✅\n" : "P5 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
