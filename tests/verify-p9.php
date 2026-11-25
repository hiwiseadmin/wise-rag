<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. refreshStats() 正确维护 doc_count / chunk_count / vector_count
 *   2. overview() 与实时扫描结果等价
 *   3. overview() 命中缓存，且 flushStatsCache() 能主动失效
 *   4. overview() 的 vectors 取自计数列 —— 不再扫描 vector 大列（通过「绕过计数」反证）
 *   5. 单条摄取 / 删除文档后计数同步
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p9.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\facade\Config;
use think\facade\Db;
use wise\rag\client\EmbeddingFactory;
use wise\rag\dto\DocEntry;
use wise\rag\parser\ParserRegistry;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\source\SourceFactory;
use wise\rag\splitter\SplitterFactory;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;
use wise\rag\vector\VectorMath;

require __DIR__ . '/../../../../vendor/autoload.php';

class WiseRagP9Embedding implements \wise\rag\contract\EmbeddingInterface
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
        return 'verify-p9';
    }

    public function getName(): string
    {
        return 'verify-p9';
    }
}

$app = new \think\App();
$app->initialize();

$ragConfig                        = (array) config('wise-rag');
$ragConfig['embedding']           = [
    'source'    => 'own',
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WiseRagP9Embedding', 'model' => 'verify-p9', 'dimension' => 4]],
];
$ragConfig['stats']['cache_ttl']  = 60;
Config::set(['wise-rag' => $ragConfig]);

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

$embedding = new EmbeddingFactory([
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WiseRagP9Embedding', 'model' => 'verify-p9', 'dimension' => 4]],
]);
$vector = new VectorFactory([
    'default' => 'database',
    'stores'  => ['database' => ['class' => DatabaseVectorStore::class]],
]);

$kbService = new KnowledgeBaseService($embedding, $vector);
$ingestion = new IngestionService(
    new ParserRegistry([]),
    new SplitterFactory('structure'),
    $embedding,
    $vector,
    $kbService,
    new SourceFactory([])
);

/** 该知识库中「真实存在向量」的分块数（实时扫描，作为对照） */
$scanVectors = static function (int $kbId): int {
    return (int) Db::name('ai_kb_chunks')->where('kb_id', $kbId)->whereNotNull('vector')->count();
};

$kbId     = 0;
$kbKey    = 'verify_p9_' . substr(md5((string) microtime(true)), 0, 8);
$extraKbId = 0;
$docId    = 0;

try {
    KnowledgeBaseService::flushStatsCache();

    echo "== 1. refreshStats 维护三项计数 ==\n";

    $kb   = $kbService->create(['kb_key' => $kbKey, 'name' => 'P9 验证库']);
    $kbId = (int) $kb->id;

    $now   = date('Y-m-d H:i:s');
    $docId = (int) Db::name('ai_kb_documents')->insertGetId([
        'kb_id' => $kbId, 'source_id' => 0, 'external_id' => 'p9:doc', 'title' => 'P9',
        'mime' => 'text/plain', 'file_path' => null, 'url' => null, 'content_hash' => md5('p9'),
        'char_count' => 10, 'chunk_count' => 10, 'owner_type' => 'admin', 'owner_id' => 0,
        'status' => 'indexed', 'error' => null, 'meta' => null,
        'create_time' => $now, 'update_time' => $now,
    ]);

    $rows = [];
    for ($i = 0; $i < 10; $i++) {
        $rows[] = [
            'kb_id' => $kbId, 'doc_id' => $docId, 'chunk_index' => $i,
            'content' => "c{$i}", 'token_count' => 2, 'heading' => null, 'page' => null,
            'content_hash' => md5("c{$i}"),
            'vector' => $i < 6 ? VectorMath::pack([1.0, 0.0, 0.0, 0.0]) : null,
            'dimension' => $i < 6 ? 4 : null,
            'owner_type' => 'admin', 'owner_id' => 0,
            'create_time' => $now, 'update_time' => $now,
        ];
    }
    Db::name('ai_kb_chunks')->insertAll($rows);

    $kbService->refreshStats($kbId);
    $fresh = Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->find();

    check('doc_count = 1', (int) ($fresh['doc_count'] ?? -1) === 1, 'doc_count=' . ($fresh['doc_count'] ?? '-'));
    check('chunk_count = 10', (int) ($fresh['chunk_count'] ?? -1) === 10, 'chunk_count=' . ($fresh['chunk_count'] ?? '-'));
    check('vector_count = 6', (int) ($fresh['vector_count'] ?? -1) === 6, 'vector_count=' . ($fresh['vector_count'] ?? '-'));

    echo "== 2. overview 与实时扫描等价 ==\n";

    KnowledgeBaseService::flushStatsCache();
    $a = $kbService->overview();

    $sumVectorCount = (int) Db::name('ai_kb_knowledge_bases')->sum('vector_count');
    check('vectors 等于 SUM(vector_count)', ($a['vectors'] ?? -1) === $sumVectorCount, 'vectors=' . ($a['vectors'] ?? '-') . ' sum=' . $sumVectorCount);
    check('failed 等于 status=error 计数', ($a['failed'] ?? -1) === (int) Db::name('ai_kb_documents')->where('status', 'error')->count());
    check('chunks 等于分块总数', ($a['chunks'] ?? -1) === (int) Db::name('ai_kb_chunks')->count());
    check('log_enabled=false 时不含 retrievals_24h', !array_key_exists('retrievals_24h', $a));

    echo "== 3. 缓存命中与主动失效 ==\n";

    $extraKbId = (int) Db::name('ai_kb_knowledge_bases')->insertGetId([
        'kb_key' => $kbKey . '_extra', 'name' => 'P9 额外库', 'owner_type' => 'admin', 'owner_id' => 0,
        'visibility' => 'private', 'embedding' => 'verify', 'embed_model' => 'verify-p9', 'dimension' => 4,
        'vector_store' => 'database', 'collection' => '', 'chunk_config' => null, 'retrieval_config' => null,
        'doc_count' => 0, 'chunk_count' => 0, 'vector_count' => 0, 'status' => 'active', 'meta' => null,
        'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
    ]);

    $b = $kbService->overview();
    check('未失效缓存时返回旧值', $b === $a, 'bases=' . ($b['bases'] ?? '-'));

    KnowledgeBaseService::flushStatsCache();
    $c = $kbService->overview();
    check('flushStatsCache 后反映新值', ($c['bases'] ?? -1) === ($a['bases'] ?? -1) + 1, 'bases=' . ($c['bases'] ?? '-'));

    Db::name('ai_kb_knowledge_bases')->where('id', $extraKbId)->delete();
    $extraKbId = 0;

    echo "== 4. vectors 取自计数列（不扫描 vector 大列） ==\n";

    Db::name('ai_kb_chunks')->where('doc_id', $docId)->whereNotNull('vector')->limit(1)->update([
        'vector' => null, 'dimension' => null,
    ]);

    KnowledgeBaseService::flushStatsCache();
    $d = $kbService->overview();
    $scanned = $sumVectorCount - 1;

    check('overview 仍返回计数列的值', ($d['vectors'] ?? -1) === $sumVectorCount, 'vectors=' . ($d['vectors'] ?? '-'));
    check('与实时扫描值不同（证明未扫描 vector 列）', ($d['vectors'] ?? -1) !== $scanned, 'scanned=' . $scanned);
    check('实时扫描确实少了 1', $scanVectors($kbId) === 5, 'scan=' . $scanVectors($kbId));

    $kbService->refreshStats($kbId);
    $e = $kbService->overview();
    check('refreshStats 后缓存自动失效并纠正', ($e['vectors'] ?? -1) === $scanned, 'vectors=' . ($e['vectors'] ?? '-'));

    echo "== 5. 单条摄取 / 删除文档后计数同步 ==\n";

    $before    = Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->find();
    $beforeDoc = (int) $before['doc_count'];

    $newDocId = $ingestion->ingestText($kb, 'A100 的功耗为 250W，显存 40GB，适用于数据中心场景。');
    $afterIngest = Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->find();

    check('摄取后 doc_count +1', (int) $afterIngest['doc_count'] === $beforeDoc + 1, 'doc_count=' . $afterIngest['doc_count']);
    check('摄取后 vector_count 增长', (int) $afterIngest['vector_count'] > (int) $before['vector_count'], 'vector_count=' . $afterIngest['vector_count']);
    check(
        '摄取后 vector_count 与实时扫描一致',
        (int) $afterIngest['vector_count'] === $scanVectors($kbId),
        'counter=' . $afterIngest['vector_count'] . ' scan=' . $scanVectors($kbId)
    );

    $ingestion->deleteDocument($newDocId);
    $afterDelete = Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->find();

    check('删除文档后 doc_count 回落', (int) $afterDelete['doc_count'] === $beforeDoc, 'doc_count=' . $afterDelete['doc_count']);
    check(
        '删除文档后 vector_count 与实时扫描一致',
        (int) $afterDelete['vector_count'] === $scanVectors($kbId),
        'counter=' . $afterDelete['vector_count'] . ' scan=' . $scanVectors($kbId)
    );

    echo "== 6. deleteByMeta 自同步计数（对外 API 不留隐患） ==\n";

    $store   = $vector->make('database');
    $deleted = $store->deleteByMeta((string) $kb->collection, ['doc_id' => $docId]);

    check('deleteByMeta 删除分块', $deleted === 10, 'deleted=' . $deleted);

    $afterMeta  = Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->find();
    $realChunks = (int) Db::name('ai_kb_chunks')->where('kb_id', $kbId)->count();
    check(
        'deleteByMeta 自动同步 chunk_count / vector_count',
        (int) $afterMeta['chunk_count'] === $realChunks && (int) $afterMeta['vector_count'] === $scanVectors($kbId),
        'chunk=' . $afterMeta['chunk_count'] . '/' . $realChunks
            . ' vector=' . $afterMeta['vector_count'] . '/' . $scanVectors($kbId)
    );
} catch (\Throwable $e) {
    check('未捕获异常', false, $e::class . ': ' . $e->getMessage());
} finally {
    if ($extraKbId > 0) {
        Db::name('ai_kb_knowledge_bases')->where('id', $extraKbId)->delete();
    }
    if ($kbId > 0) {
        try {
            $kbService->delete($kbId, true);
        } catch (\Throwable $e) {
            echo "  [warn] 清理知识库失败：{$e->getMessage()}\n";
        }
    }
    KnowledgeBaseService::flushStatsCache();
}

echo "\n";
echo $failures === []
    ? "P9 全部通过（共 {$checks} 项）\n"
    : sprintf("P9 共 %d 项，失败 %d 项：\n", $checks, count($failures));

foreach ($failures as $item) {
    echo "  - {$item}\n";
}

exit($failures === [] ? 0 : 1);
