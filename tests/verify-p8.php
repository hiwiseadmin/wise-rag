<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 跨页召回：最优结果位于最后一页时仍被召回（游标分页不漏行）
 *   2. 结果等价：与「全量计算 + 排序」的期望一致（含 topK 截断）
 *   3. 过滤与维度隔离：filter 生效、维度不符的向量不参与
 *   4. hydrate：返回的 content / meta 完整（正是它让分页可以只取向量）
 *   5. VectorRepairService：识别损坏向量 → 清空 + 标记文档 → 健康行不受影响
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p8.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\facade\Config;
use think\facade\Db;
use wise\rag\client\EmbeddingFactory;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\VectorRepairService;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;
use wise\rag\vector\VectorMath;

require __DIR__ . '/../../../../vendor/autoload.php';

class WiseRagP8Embedding implements \wise\rag\contract\EmbeddingInterface
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
        return 'verify-p8';
    }

    public function getName(): string
    {
        return 'verify-p8';
    }
}

$app = new \think\App();
$app->initialize();

$ragConfig              = (array) config('wise-rag');
$ragConfig['embedding'] = [
    'source'    => 'own',
    'default'   => 'verify',
    'providers' => [
        'verify' => ['class' => 'WiseRagP8Embedding', 'model' => 'verify-p8', 'dimension' => 4],
    ],
];
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

$TOTAL     = 2500;
$PAGE_SIZE = 100;
$QUERY     = [1.0, 0.0, 0.0, 0.0];

$embedding = new EmbeddingFactory([
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WiseRagP8Embedding', 'model' => 'verify-p8', 'dimension' => 4]],
]);
$vector = new VectorFactory([
    'default' => 'database',
    'stores'  => [
        'database' => ['class' => DatabaseVectorStore::class, 'scan_page_size' => $PAGE_SIZE],
    ],
]);

$kbService = new KnowledgeBaseService($embedding, $vector);
$store     = $vector->make('database');

/** 第 $i 行的 delta（越小越相似；i 越大越相似 → 最优结果落在最后一页） */
$deltaOf = static fn (int $i): float => 0.001 * ($TOTAL - $i);

/** 该知识库中「真实存在向量」的分块数（实时扫描，作为计数列的对照） */
$scanVectors = static function (int $kbId): int {
    return (int) Db::name('ai_kb_chunks')->where('kb_id', $kbId)->whereNotNull('vector')->count();
};

$kbId      = 0;
$kbKey     = 'verify_p8_' . substr(md5((string) microtime(true)), 0, 8);
$docId     = 0;
$idByIndex = [];

try {
    $kb   = $kbService->create(['kb_key' => $kbKey, 'name' => 'P8 验证库']);
    $kbId = (int) $kb->id;

    echo "== 1. 造数（{$TOTAL} 分块 / 页大小 {$PAGE_SIZE}） ==\n";

    $now  = date('Y-m-d H:i:s');
    $docId = (int) Db::name('ai_kb_documents')->insertGetId([
        'kb_id'        => $kbId,
        'source_id'    => 0,
        'external_id'  => 'p8:doc',
        'title'        => 'P8 文档',
        'mime'         => 'text/markdown',
        'file_path'    => null,
        'url'          => null,
        'content_hash' => md5('p8'),
        'char_count'   => 100,
        'chunk_count'  => $TOTAL,
        'owner_type'   => 'admin',
        'owner_id'     => 0,
        'status'       => 'indexed',
        'error'        => null,
        'meta'         => null,
        'create_time'  => $now,
        'update_time'  => $now,
    ]);

    $rows = [];
    for ($i = 0; $i < $TOTAL; $i++) {
        $rows[] = [
            'kb_id'        => $kbId,
            'doc_id'       => $docId,
            'chunk_index'  => $i,
            'content'      => "chunk-{$i} 正文内容",
            'token_count'  => 10,
            'heading'      => "H{$i}",
            'page'         => $i % 7,
            'content_hash' => md5("chunk-{$i}"),
            'vector'       => VectorMath::pack([1.0, $deltaOf($i), 0.0, 0.0]),
            'dimension'    => 4,
            'owner_type'   => $i % 3 === 0 ? 'member' : 'admin',
            'owner_id'     => 0,
            'create_time'  => $now,
            'update_time'  => $now,
        ];
    }

    $rows[] = [
        'kb_id'        => $kbId,
        'doc_id'       => $docId,
        'chunk_index'  => $TOTAL,
        'content'      => 'canary-8dim',
        'token_count'  => 10,
        'heading'      => null,
        'page'         => null,
        'content_hash' => md5('canary'),
        'vector'       => VectorMath::pack([1.0, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0, 1.0]),
        'dimension'    => 8,
        'owner_type'   => 'admin',
        'owner_id'     => 0,
        'create_time'  => $now,
        'update_time'  => $now,
    ];

    foreach (array_chunk($rows, 500) as $batch) {
        Db::name('ai_kb_chunks')->insertAll($batch);
    }

    $idByIndex = array_map('intval', Db::name('ai_kb_chunks')
        ->where('doc_id', $docId)
        ->column('id', 'chunk_index'));

    check('分块写入完成', count($idByIndex) === $TOTAL + 1, 'count=' . count($idByIndex));

    echo "== 2. 跨页召回与结果等价 ==\n";

    $hits = $store->search($kb->collection, $QUERY, 5);
    check('返回 5 条', count($hits) === 5, 'count=' . count($hits));

    $expected = [];
    for ($i = $TOTAL - 1; $i >= $TOTAL - 5; $i--) {
        $expected[] = (string) ($idByIndex[$i] ?? 0);
    }
    $actual = array_map(static fn ($h): string => $h->chunkId, $hits);
    check('最优结果位于最后一页仍被召回', $actual === $expected, 'actual=' . implode(',', $actual) . ' expected=' . implode(',', $expected));

    $descending = true;
    for ($i = 1, $n = count($hits); $i < $n; $i++) {
        if ($hits[$i - 1]->score < $hits[$i]->score) {
            $descending = false;
            break;
        }
    }
    check('分数降序', $descending);

    check(
        '分数与直接计算一致',
        abs($hits[0]->score - VectorMath::cosine($QUERY, [1.0, $deltaOf($TOTAL - 1), 0.0, 0.0])) < 1e-9
    );

    $hitsSmall = $store->search($kb->collection, $QUERY, 3);
    check('topK=3 仅返回 3 条', count($hitsSmall) === 3, 'count=' . count($hitsSmall));
    check(
        'topK 截断后仍为全局最优 3 条',
        array_map(static fn ($h): string => $h->chunkId, $hitsSmall) === array_slice($expected, 0, 3)
    );

    echo "== 3. 过滤与维度隔离 ==\n";

    $filtered = $store->search($kb->collection, $QUERY, 5, ['owner_type' => 'member']);
    $allMember = true;
    foreach ($filtered as $hit) {
        if (($hit->meta['owner_type'] ?? '') !== 'member') {
            $allMember = false;
            break;
        }
    }
    $expectedMember = [];
    for ($i = $TOTAL - 1; count($expectedMember) < 5 && $i >= 0; $i--) {
        if ($i % 3 === 0) {
            $expectedMember[] = (string) ($idByIndex[$i] ?? 0);
        }
    }
    check('filter 生效（仅 member）', $allMember && count($filtered) === 5, 'count=' . count($filtered));
    check(
        'filter 下的全局最优与手工计算结果一致',
        array_map(static fn ($h): string => $h->chunkId, $filtered) === $expectedMember,
        'actual=' . implode(',', array_map(static fn ($h): string => $h->chunkId, $filtered))
    );

    $canaryId = (string) ($idByIndex[$TOTAL] ?? '0');
    $hitIds   = array_map(static fn ($h): string => $h->chunkId, $store->search($kb->collection, $QUERY, 100));
    check('维度不符的向量不被召回', !in_array($canaryId, $hitIds, true));

    echo "== 4. hydrate 补齐正文与元数据 ==\n";

    check('content 已回填', ($hits[0]->content ?? '') !== '', 'content=' . ($hits[0]->content ?? ''));
    check('heading 已回填', ($hits[0]->meta['heading'] ?? '') !== '');
    check('page 已转 int', is_int($hits[0]->meta['page'] ?? null));
    check('meta.owner_type 已回填', ($hits[0]->meta['owner_type'] ?? '') !== '');

    echo "== 5. VectorRepairService（损坏向量修复） ==\n";

    $repair = $app->make(VectorRepairService::class);

    $brokenIds = [];
    foreach ([0, 1, 2] as $k) {
        $brokenIds[] = (int) Db::name('ai_kb_chunks')->insertGetId([
            'kb_id'        => $kbId,
            'doc_id'       => $docId,
            'chunk_index'  => $TOTAL + 10 + $k,
            'content'      => "broken-{$k}",
            'token_count'  => 8,
            'heading'      => null,
            'page'         => null,
            'content_hash' => md5("broken-{$k}"),
            'vector'       => VectorMath::pack([1.0, 0.0, 0.0, 0.0]),
            'dimension'    => 8,
            'owner_type'   => 'admin',
            'owner_id'     => 0,
            'create_time'  => $now,
            'update_time'  => $now,
        ]);
    }

    $before = $repair->inspect($kbId);
    check('inspect 识别损坏向量', $before['chunks'] === 3, 'chunks=' . $before['chunks']);
    check('inspect 识别受影响文档', $before['documents'] === 1, 'docs=' . $before['documents']);
    check('inspect 识别受影响知识库', $before['kbs'] === 1, 'kbs=' . $before['kbs']);

    $healthyBefore = (int) Db::name('ai_kb_chunks')
        ->where('doc_id', $docId)
        ->whereNotNull('vector')
        ->where('dimension', 4)
        ->count();

    $kbService->refreshStats($kbId);
    $counterBefore = (int) Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->value('vector_count');
    check('修复前计数与实时扫描一致', $counterBefore === $scanVectors($kbId), 'counter=' . $counterBefore . ' scan=' . $scanVectors($kbId));

    $fixed = $repair->repair($kbId);
    check('repair 清空 3 个损坏向量', $fixed['chunks'] === 3, 'chunks=' . $fixed['chunks']);
    check('repair 标记 1 篇文档', $fixed['documents'] === 1, 'docs=' . $fixed['documents']);
    check('repair 返回受影响知识库数', $fixed['kbs'] === 1, 'kbs=' . $fixed['kbs']);

    $counterAfter = (int) Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->value('vector_count');
    check(
        'repair 自动刷新受影响知识库计数',
        $counterAfter === $scanVectors($kbId) && $counterAfter < $counterBefore,
        "before={$counterBefore} after={$counterAfter} scan=" . $scanVectors($kbId)
    );

    $stillBroken = (int) Db::name('ai_kb_chunks')
        ->whereIn('id', $brokenIds)
        ->whereNotNull('vector')
        ->count();
    check('损坏向量已置空', $stillBroken === 0, 'left=' . $stillBroken);

    $healthyAfter = (int) Db::name('ai_kb_chunks')
        ->where('doc_id', $docId)
        ->whereNotNull('vector')
        ->where('dimension', 4)
        ->count();
    check('健康向量不受影响', $healthyAfter === $healthyBefore, "before={$healthyBefore} after={$healthyAfter}");

    $doc = Db::name('ai_kb_documents')->where('id', $docId)->find();
    check('文档被标记为 error（否则重建索引会被幂等跳过）', (string) ($doc['status'] ?? '') === 'error', 'status=' . ($doc['status'] ?? '-'));
    check('error 含重建提示', mb_strpos((string) ($doc['error'] ?? ''), '重建索引') !== false, (string) ($doc['error'] ?? '-'));

    $after = $repair->inspect($kbId);
    check('二次 inspect 归零', $after['chunks'] === 0 && $after['documents'] === 0, json_encode($after));

    echo "== 6. 候选数少于 topK 时仍按分数降序（接口契约） ==\n";

    $smallKb = $kbService->create(['kb_key' => $kbKey . '_small', 'name' => 'P8 小库']);
    $smallIds = [];
    foreach ([[1.0, 0.9, 0.0, 0.0], [1.0, 0.1, 0.0, 0.0], [1.0, 0.5, 0.0, 0.0]] as $k => $vec) {
        $smallIds[] = (int) Db::name('ai_kb_chunks')->insertGetId([
            'kb_id'        => (int) $smallKb->id,
            'doc_id'       => 0,
            'chunk_index'  => $k,
            'content'      => "small-{$k}",
            'token_count'  => 7,
            'heading'      => null,
            'page'         => null,
            'content_hash' => md5("small-{$k}"),
            'vector'       => VectorMath::pack($vec),
            'dimension'    => 4,
            'owner_type'   => 'admin',
            'owner_id'     => 0,
            'create_time'  => $now,
            'update_time'  => $now,
        ]);
    }

    $small = $store->search($smallKb->collection, $QUERY, 10);
    check('少于 topK 时全部返回', count($small) === 3, 'count=' . count($small));

    $ordered = true;
    for ($i = 1, $n = count($small); $i < $n; $i++) {
        if ($small[$i - 1]->score < $small[$i]->score) {
            $ordered = false;
            break;
        }
    }
    check(
        '少于 topK 时仍按分数降序',
        $ordered,
        'scores=' . implode(',', array_map(static fn ($h): float => round($h->score, 4), $small))
    );
    check(
        '最优为最相似向量',
        ($small[0]->chunkId ?? '') === (string) $smallIds[1],
        'top=' . ($small[0]->chunkId ?? '-') . ' expect=' . $smallIds[1]
    );

    $kbService->delete((int) $smallKb->id, true);
} catch (\Throwable $e) {
    check('未捕获异常', false, $e::class . ': ' . $e->getMessage());
} finally {
    if ($kbId > 0) {
        try {
            $kbService->delete($kbId, true);
        } catch (\Throwable $e) {
            echo "  [warn] 清理知识库失败：{$e->getMessage()}\n";
        }
    }
}

echo "\n";
echo $failures === []
    ? "P8 全部通过（共 {$checks} 项）\n"
    : sprintf("P8 共 %d 项，失败 %d 项：\n", $checks, count($failures));

foreach ($failures as $item) {
    echo "  - {$item}\n";
}

exit($failures === [] ? 0 : 1);
