<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. SourceFactory：内置驱动映射与按行创建
 *   2. TableSource 端到端：真实临时表 → 知识源行 → ingestSource → 增量水位
 *   3. KnowledgeDiscovery 端到端：临时插件 manifest → 自动建库 + 落源（幂等）→ 索引
 *   4. 检索可命中（离线假 Embedding）
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p3.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\facade\Db;
use wise\rag\client\EmbeddingFactory;
use wise\rag\discovery\KnowledgeDiscovery;
use wise\rag\dto\RetrievalQuery;
use wise\rag\parser\ParserRegistry;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\source\ManualSource;
use wise\rag\source\SourceFactory;
use wise\rag\source\TableSource;
use wise\rag\splitter\SplitterFactory;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;

require __DIR__ . '/../../../../vendor/autoload.php';

class WiseRagP3Embedding implements \wise\rag\contract\EmbeddingInterface
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

$ragConfig              = (array) config('wise-rag');
$ragConfig['embedding'] = [
    'source'    => 'own',
    'default'   => 'verify',
    'providers' => [
        'verify' => ['class' => 'WiseRagP3Embedding', 'model' => 'verify', 'dimension' => 4],
    ],
];
\think\facade\Config::set(['wise-rag' => $ragConfig]);

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
    'providers' => ['verify' => ['class' => 'WiseRagP3Embedding', 'model' => 'verify', 'dimension' => 4]],
]);
$vector    = new VectorFactory([
    'default' => 'database',
    'stores'  => ['database' => ['class' => DatabaseVectorStore::class]],
]);
$kbService = new KnowledgeBaseService($embedding, $vector);
$ingestion = new IngestionService(new ParserRegistry([]), new SplitterFactory('structure'), $embedding, $vector, $kbService, new SourceFactory([]));
$retrieval = new RetrievalService($embedding, $vector, $kbService);

$fullTable = (string) Db::name('ai_kb_sources')->getTable();
$prefix    = substr($fullTable, 0, strlen($fullTable) - strlen('ai_kb_sources'));
$tempTable = 'wise_rag_verify_items';
$tempFull  = $prefix . $tempTable;

$tempPlugin = 'wise_rag_verify_tmp';
$pluginDir  = (function_exists('plugin_path') ? plugin_path() : __DIR__ . '/../../../../plugins/') . $tempPlugin;

$createdKbIds = [];

try {
    echo "== 1. SourceFactory ==\n";
    $factory = new SourceFactory([]);
    check('内置驱动含 manual/table/attachment', array_keys(SourceFactory::builtin()) === ['manual', 'table', 'attachment']);
    $manual = $factory->make(['provider' => 'manual', 'source_key' => 'x:y', 'config' => '{"title":"手工"}']);
    check('创建 manual 驱动', $manual instanceof ManualSource && $manual->getTitle() === '手工');
    $tableSrc = $factory->make(['provider' => 'table', 'source_key' => 'x:z', 'config' => '{"table":"t"}']);
    check('创建 table 驱动', $tableSrc instanceof TableSource);

    echo "== 2. TableSource 端到端 ==\n";
    Db::execute(sprintf(
        'CREATE TABLE IF NOT EXISTS `%s` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) DEFAULT \'\', `description` TEXT NULL, `update_time` DATETIME NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        $tempFull
    ));
    $now = date('Y-m-d H:i:s');
    Db::execute(sprintf(
        "INSERT INTO `%s` (`name`,`description`,`update_time`) VALUES ('A100','功率 250W','%s'),('H100','功率 700W','%s')",
        $tempFull,
        $now,
        $now
    ));
    check('临时表就绪', (int) Db::name($tempTable)->count() === 2);

    $kb   = $kbService->create(['kb_key' => 'p3_verify_kb_' . substr(md5((string) microtime(true)), 0, 6), 'name' => 'P3 验证库', 'embedding' => 'verify']);
    $kbId = (int) $kb->id;
    $createdKbIds[] = $kbId;

    Db::name('ai_kb_sources')->where('source_key', 'verify:items')->delete();

    Db::name('ai_kb_sources')->insert([
        'kb_id'       => $kbId,
        'source_key'  => 'verify:items',
        'origin'      => 'manual',
        'provider'    => 'table',
        'config'      => json_encode([
            'table'          => $tempTable,
            'title_field'    => 'name',
            'content_fields' => ['name', 'description'],
            'updated_field'  => 'update_time',
            'title'          => '验证条目',
        ], JSON_UNESCAPED_UNICODE),
        'sync_mode'   => 'manual',
        'status'      => 'active',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $sourceId = (int) Db::name('ai_kb_sources')->where('source_key', 'verify:items')->value('id');
    check('知识源行已写入', $sourceId > 0);

    $first = $ingestion->ingestSource($sourceId);
    check('首次索引：2 篇文档', (int) $first['documents'] === 2, json_encode($first));
    check('首次索引：产出分块', (int) $first['chunks'] >= 2, (string) $first['chunks']);

    $checkpoint = (string) Db::name('ai_kb_sources')->where('id', $sourceId)->value('checkpoint');
    check('增量水位已写入', $checkpoint !== '', $checkpoint);

    $second = $ingestion->ingestSource($sourceId);
    check('二次索引增量生效（无新文档）', (int) $second['documents'] === 0, json_encode($second));

    Db::name('ai_kb_documents')->where('kb_id', $kbId)->select();

    $hits = $retrieval->retrieve(new RetrievalQuery('A100 功率', null, [$kbId], 3));
    check('检索可命中', count($hits) >= 1, 'count=' . count($hits));
    check('引用含文档标题', ($hits[0]->meta['cite']['title'] ?? '') !== '', (string) ($hits[0]->meta['cite']['title'] ?? ''));

    echo "== 3. KnowledgeDiscovery 端到端 ==\n";
    $discovery = new KnowledgeDiscovery($kbService);
    check('manifests() 可扫描到已装插件', count($discovery->manifests()) >= 1, 'count=' . count($discovery->manifests()));

    if (!is_dir($pluginDir)) {
        mkdir($pluginDir, 0755, true);
    }
    file_put_contents($pluginDir . '/manifest.json', json_encode([
        'name'    => $tempPlugin,
        'title'   => 'P3 验证插件',
        'version' => '0.0.1',
        'ai_schema' => [
            'knowledge_sources' => [
                [
                    'key'       => 'items',
                    'title'     => '验证条目',
                    'provider'  => 'table',
                    'config'    => [
                        'table'          => $tempTable,
                        'title_field'    => 'name',
                        'content_fields' => ['name', 'description'],
                        'updated_field'  => 'update_time',
                    ],
                    'sync_mode' => 'manual',
                    'chunk'     => ['size' => 200, 'overlap' => 20, 'splitter' => 'structure'],
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $found = $discovery->discover($tempPlugin);
    check('发现 1 个插件声明', count($found) === 1, json_encode(array_column($found, 'plugin')));
    $entry = $found[0] ?? [];
    check('自动创建知识库', !empty($entry['kb_id']) && !empty($entry['created_kb']));
    check('落库 1 个知识源', ($entry['sources'][0] ?? '') === $tempPlugin . ':items', json_encode($entry['sources'] ?? []));
    if (!empty($entry['kb_id'])) {
        $createdKbIds[] = (int) $entry['kb_id'];
    }

    $again = $discovery->discover($tempPlugin);
    check('重复发现幂等（不新建库）', empty($again[0]['created_kb']));
    $sourceRows = (int) Db::name('ai_kb_sources')->where('source_key', $tempPlugin . ':items')->count();
    check('知识源不重复落库', $sourceRows === 1, 'rows=' . $sourceRows);

    check('全局默认 embedding 已切到 verify', (string) config('wise-rag.embedding.default') === 'verify', (string) config('wise-rag.embedding.default'));
    $discoveredKb = $kbService->get((int) ($entry['kb_id'] ?? 0));
    check('发现库使用离线 embedding', ($discoveredKb->embedding ?? '') === 'verify', (string) ($discoveredKb->embedding ?? '(none)'));

    $discovered = $ingestion->ingestKnowledgeBase((int) ($entry['kb_id'] ?? 0));
    $docTotal   = 0;
    foreach ((array) ($discovered['details'] ?? []) as $d) {
        $docTotal += (int) $d['documents'];
    }
    check('发现后可直接索引（2 篇文档）', $docTotal === 2, json_encode($discovered));

    $dump = $discovery->dump($tempPlugin);
    check('dump 可导出声明', ($dump['knowledge_sources'][0]['key'] ?? '') === 'items');
} catch (\Throwable $e) {
    check('P3 主流程无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    echo "== 4. 清理 ==\n";
    foreach ($createdKbIds as $id) {
        try {
            $kbService->delete((int) $id, true);
        } catch (\Throwable $e) {
        }
    }
    Db::name('ai_kb_sources')->where('source_key', $tempPlugin . ':items')->delete();
    Db::name('ai_kb_sources')->where('source_key', 'verify:items')->delete();
    check('知识库已清理', Db::name('ai_kb_knowledge_bases')->whereIn('id', $createdKbIds ?: [0])->count() === 0);

    try {
        Db::execute('DROP TABLE IF EXISTS `' . $tempFull . '`');
    } catch (\Throwable $e) {
    }
    if (is_file($pluginDir . '/manifest.json')) {
        @unlink($pluginDir . '/manifest.json');
    }
    if (is_dir($pluginDir)) {
        @rmdir($pluginDir);
    }
    check('临时插件目录已移除', !is_dir($pluginDir));
}

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P3 验证通过 ✅\n" : "P3 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
