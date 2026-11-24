<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 正常路径：摄取成功 → status=indexed / error=null / 分块均有向量
 *   2. 失败路径：embedding 抛错 → status=error / error 落库 / 计入 failed 统计
 *   3. 自愈路径：失败后重跑成功 → error 被清空、旧分块被替换（不重复）
 *   4. 边界路径：内容过短无分块 → status=indexed / chunk_count=0
 *   5. 维度护栏：真实返回维度与知识库声明不一致 → 拦截且文档标记 error
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p6.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\facade\Config;
use think\facade\Db;
use wise\rag\client\EmbeddingFactory;
use wise\rag\dto\DocEntry;
use wise\rag\exception\ProviderException;
use wise\rag\parser\ParserRegistry;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\source\SourceFactory;
use wise\rag\splitter\SplitterFactory;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;

require __DIR__ . '/../../../../vendor/autoload.php';

/**
 * 可控 embedding 桩
 *
 * reportedDimension 与 actualDimension 分离，用于模拟
 * 「配置声明 4 维、远端实际返回 8 维」这种只能靠真实返回值发现的不一致。
 */
class WiseRagP6Embedding implements \wise\rag\contract\EmbeddingInterface
{
    public static int $reportedDimension = 4;

    public static int $actualDimension = 4;

    public static bool $fail = false;

    public function embed(array $texts, array $options = []): array
    {
        if (self::$fail) {
            throw new ProviderException('桩：Embedding 服务不可用（模拟 5xx）');
        }

        return array_fill(0, count($texts), array_fill(0, self::$actualDimension, 0.5));
    }

    public function getDimension(): int
    {
        return self::$reportedDimension;
    }

    public function getModel(): string
    {
        return 'verify-p6';
    }

    public function getName(): string
    {
        return 'verify-p6';
    }
}

$app = new \think\App();
$app->initialize();

$ragConfig              = (array) config('wise-rag');
$ragConfig['embedding'] = [
    'source'    => 'own',
    'default'   => 'verify',
    'providers' => [
        'verify' => ['class' => 'WiseRagP6Embedding', 'model' => 'verify-p6', 'dimension' => 4],
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

$embedding = new EmbeddingFactory([
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WiseRagP6Embedding', 'model' => 'verify-p6', 'dimension' => 4]],
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

/** 读取文档行 */
$loadDoc = static function (int $kbId, string $externalId): array {
    $row = Db::name('ai_kb_documents')
        ->where('kb_id', $kbId)
        ->where('external_id', $externalId)
        ->find();

    return is_array($row) ? $row : [];
};

/** 统计该文档已写入向量的分块数 */
$vectorCount = static function (int $docId): int {
    return (int) Db::name('ai_kb_chunks')->where('doc_id', $docId)->whereNotNull('vector')->count();
};

$kbId     = 0;
$kbKey    = 'verify_p6_' . substr(md5((string) microtime(true)), 0, 8);
$failedBefore = 0;

try {
    $kb   = $kbService->create(['kb_key' => $kbKey, 'name' => 'P6 验证库']);
    $kbId = (int) $kb->id;

    $failedBefore = (int) Db::name('ai_kb_documents')->where('status', 'error')->count();

    echo "== 1. 正常路径（事务化摄取） ==\n";

    $text = "# 参数\n\nA100 的功耗为 250W，显存 40GB。\n\n# 备注\n\n适用于数据中心场景，需配套散热方案。";
    $res  = $ingestion->ingest($kb, new DocEntry('p6:ok', 'A100 参数', $text));

    $doc = $loadDoc($kbId, 'p6:ok');
    check('返回 doc_id 与库内一致', (int) ($doc['id'] ?? 0) === (int) $res['doc_id']);
    check('status = indexed', (string) ($doc['status'] ?? '') === 'indexed', 'status=' . ($doc['status'] ?? '-'));
    check('error 为空', ($doc['error'] ?? null) === null, 'error=' . var_export($doc['error'] ?? null, true));
    check('chunk_count > 0', (int) ($doc['chunk_count'] ?? 0) > 0, 'chunk_count=' . ($doc['chunk_count'] ?? 0));
    check(
        '分块全部写入向量',
        $vectorCount((int) $res['doc_id']) === (int) ($doc['chunk_count'] ?? 0),
        'vectors=' . $vectorCount((int) $res['doc_id']) . ' chunks=' . ($doc['chunk_count'] ?? 0)
    );

    echo "== 2. 失败路径（embedding 抛错） ==\n";

    WiseRagP6Embedding::$fail = true;
    $thrown = '';
    try {
        $ingestion->ingest($kb, new DocEntry('p6:fail', '失败文档', $text));
    } catch (\Throwable $e) {
        $thrown = $e->getMessage();
    }
    WiseRagP6Embedding::$fail = false;

    $failDoc = $loadDoc($kbId, 'p6:fail');
    check('异常向上抛出', $thrown !== '', 'thrown=' . $thrown);
    check('status = error', (string) ($failDoc['status'] ?? '') === 'error', 'status=' . ($failDoc['status'] ?? '-'));
    check(
        'error 字段已落库且含原因',
        mb_strpos((string) ($failDoc['error'] ?? ''), 'Embedding') !== false,
        'error=' . mb_substr((string) ($failDoc['error'] ?? ''), 0, 80)
    );
    check(
        '失败文档计入 failed 统计',
        (int) Db::name('ai_kb_documents')->where('status', 'error')->count() > $failedBefore
    );

    echo "== 3. 自愈路径（失败后重跑成功） ==\n";

    $again  = $ingestion->ingest($kb, new DocEntry('p6:fail', '失败文档', $text));
    $healed = $loadDoc($kbId, 'p6:fail');
    check('重跑后 status = indexed', (string) ($healed['status'] ?? '') === 'indexed', 'status=' . ($healed['status'] ?? '-'));
    check('重跑后 error 被清空', ($healed['error'] ?? null) === null, 'error=' . var_export($healed['error'] ?? null, true));
    check(
        '重跑不产生重复分块',
        (int) Db::name('ai_kb_chunks')->where('doc_id', (int) $again['doc_id'])->count() === (int) $healed['chunk_count'],
        'rows=' . Db::name('ai_kb_chunks')->where('doc_id', (int) $again['doc_id'])->count()
            . ' chunk_count=' . $healed['chunk_count']
    );
    check(
        '重跑后分块均有向量',
        $vectorCount((int) $again['doc_id']) === (int) $healed['chunk_count']
    );

    echo "== 4. 边界路径（内容过短，无分块） ==\n";

    $short = $ingestion->ingest($kb, new DocEntry('p6:empty', '短文本', '短'));
    $shortDoc = $loadDoc($kbId, 'p6:empty');
    check('chunk_count = 0', (int) ($shortDoc['chunk_count'] ?? -1) === 0, 'chunk_count=' . ($shortDoc['chunk_count'] ?? '-'));
    check('status = indexed（不留 parsed 残留）', (string) ($shortDoc['status'] ?? '') === 'indexed', 'status=' . ($shortDoc['status'] ?? '-'));
    check('无分块时不写向量', $vectorCount((int) $short['doc_id']) === 0);

    echo "== 5. 维度护栏（真实返回维度 ≠ 声明维度） ==\n";

    WiseRagP6Embedding::$actualDimension = 8;
    $dimThrown = '';
    try {
        $ingestion->ingest($kb, new DocEntry('p6:dim', '维度不符', $text));
    } catch (\Throwable $e) {
        $dimThrown = $e->getMessage();
    }
    WiseRagP6Embedding::$actualDimension = 4;

    $dimDoc = $loadDoc($kbId, 'p6:dim');
    check('抛出 RagException', $dimThrown !== '' && (mb_strpos($dimThrown, '实际维度') !== false), 'msg=' . $dimThrown);
    check('维度不符的文档标记为 error', (string) ($dimDoc['status'] ?? '') === 'error', 'status=' . ($dimDoc['status'] ?? '-'));
    check('维度不符时不写入任何向量', $vectorCount((int) ($dimDoc['id'] ?? 0)) === 0);
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
    ? "P6 全部通过（共 {$checks} 项）\n"
    : sprintf("P6 共 %d 项，失败 %d 项：\n", $checks, count($failures));

foreach ($failures as $item) {
    echo "  - {$item}\n";
}

exit($failures === [] ? 0 : 1);
