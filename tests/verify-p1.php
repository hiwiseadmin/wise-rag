<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 纯单元：VectorMath（打包/解包/余弦）、三种分块器、Markdown/Text 解析器、ParserRegistry
 *   2. 数据库往返：KnowledgeBaseService 建库 → ai_kb_chunks 造向量 → DatabaseVectorStore 检索 → 清理
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p1.php
 * 退出码：0 全部通过；1 存在失败
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use think\facade\Db;
use wise\rag\dto\ParsedDocument;
use wise\rag\parser\MarkdownParser;
use wise\rag\parser\ParserRegistry;
use wise\rag\parser\TextParser;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\splitter\FixedSplitter;
use wise\rag\splitter\RecursiveSplitter;
use wise\rag\splitter\StructureSplitter;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorMath;

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

echo "== 1. VectorMath ==\n";
$vec  = [0.1, -0.2, 0.3, 1.0];
$back = VectorMath::unpack(VectorMath::pack($vec));
check('pack/unpack 往返一致', count($back) === 4 && abs($back[3] - 1.0) < 1e-6, json_encode($back));
check('余弦同向 = 1', abs(VectorMath::cosine([1, 0, 0], [1, 0, 0]) - 1.0) < 1e-9);
check('余弦正交 = 0', abs(VectorMath::cosine([1, 0, 0], [0, 1, 0])) < 1e-9);
check('零向量 = 0', VectorMath::cosine([0, 0, 0], [1, 0, 0]) === 0.0);

echo "== 2. 分块器 ==\n";
$long = str_repeat('这是一句用于测试分块的长文本。', 120);
$fixed = (new FixedSplitter())->split(new ParsedDocument($long), ['size' => 300, 'overlap' => 40, 'min_chars' => 10]);
check('FixedSplitter 产出多块', count($fixed) > 1, 'count=' . count($fixed));
check('FixedSplitter 每块不超上限', max(array_map(static fn ($c) => mb_strlen($c->content), $fixed)) <= 300);

$md = "# 标题\n\n引言文字。\n\n## 参数\n\nA100 功率 250W。\n\n## 安装\n\n步骤一二三。";
$structure = (new StructureSplitter())->split(new ParsedDocument($md), ['size' => 500, 'min_chars' => 2]);
check('StructureSplitter 按标题分块', count($structure) >= 3, 'count=' . count($structure));
$headings = array_map(static fn ($c) => $c->meta['heading'] ?? '', $structure);
check('StructureSplitter 记录标题路径', in_array('标题 > 参数', $headings, true), implode(' | ', $headings));

$recursive = (new RecursiveSplitter())->split(new ParsedDocument($long), ['size' => 300, 'overlap' => 40, 'min_chars' => 10]);
check('RecursiveSplitter 产出多块', count($recursive) > 1, 'count=' . count($recursive));
check('RecursiveSplitter 保留标点', str_contains($recursive[0]->content, '。'));

echo "== 3. 解析器 ==\n";
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_rag_verify';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}
$txtFile = $tmpDir . '/sample.txt';
file_put_contents($txtFile, "\xEF\xBB\xBF你好，世界。");
$parsedText = (new TextParser())->parse($txtFile);
check('TextParser 去 BOM', $parsedText->text === '你好，世界。', $parsedText->text);

$mdFile = $tmpDir . '/sample.md';
file_put_contents($mdFile, "# 商品说明书\n\n![图](a.png) 参见 [文档](http://x) 与 **加粗**。");
$parsedMd = (new MarkdownParser())->parse($mdFile);
check('MarkdownParser 取标题', $parsedMd->title === '商品说明书', $parsedMd->title);
check('MarkdownParser 清洗链接/强调', str_contains($parsedMd->text, '参见 文档') && str_contains($parsedMd->text, '加粗'));

$registry = new ParserRegistry([
    'text/plain'    => TextParser::class,
    'text/markdown' => MarkdownParser::class,
]);
check('ParserRegistry 解析 txt', $registry->parse($txtFile)->text === '你好，世界。');
check('ParserRegistry 按扩展名兜底 md', $registry->parse($mdFile)->title === '商品说明书');
try {
    $registry->parse($tmpDir . '/unknown.xyz');
    check('ParserRegistry 未知类型抛异常', false);
} catch (\Throwable $e) {
    check('ParserRegistry 未知类型抛异常', true);
}

echo "== 4. 数据库往返（DatabaseVectorStore） ==\n";
$kbService = $app->make(KnowledgeBaseService::class);
$kbKey     = 'verify_kb_' . substr(md5((string) microtime(true)), 0, 8);
$kbId      = 0;

try {
    $kb   = $kbService->create(['kb_key' => $kbKey, 'name' => 'P1 验证库']);
    $kbId = (int) $kb->id;
    check('建库成功且回写 collection', $kbId > 0 && $kb->collection === 'ai_kb_' . $kbId, (string) $kb->collection);
    check('建库记录了 embedding 维度', (int) $kb->dimension > 0, (string) $kb->dimension);

    $now  = date('Y-m-d H:i:s');
    $rows = [
        ['apple', [1.0, 0.0, 0.0]],
        ['banana', [0.0, 1.0, 0.0]],
        ['apple-pie', [0.9, 0.1, 0.0]],
    ];
    $data = [];
    foreach ($rows as $i => [$content, $vector]) {
        $data[] = [
            'kb_id'        => $kbId,
            'doc_id'       => 0,
            'chunk_index'  => $i,
            'content'      => $content,
            'token_count'  => mb_strlen($content),
            'heading'      => null,
            'page'         => null,
            'content_hash' => md5($content),
            'vector'       => VectorMath::pack($vector),
            'dimension'    => 3,
            'owner_type'   => 'admin',
            'owner_id'     => 0,
            'create_time'  => $now,
            'update_time'  => $now,
        ];
    }
    Db::name('ai_kb_chunks')->insertAll($data);

    $store = (new \wise\rag\vector\VectorFactory(['default' => 'database', 'stores' => ['database' => ['class' => DatabaseVectorStore::class]]]))->make('database');
    $hits  = $store->search($kb->collection, [1.0, 0.0, 0.0], 3);

    check('检索返回命中', count($hits) === 3, 'count=' . count($hits));
    check('最高分是 apple', ($hits[0]->content ?? '') === 'apple', $hits[0]->content ?? '(none)');
    check('命中分数在 (0,1]', ($hits[0]->score ?? 0) > 0.9 && ($hits[0]->score ?? 0) <= 1.0, (string) ($hits[0]->score ?? -1));
    check('结果按分数降序', ($hits[1]->score ?? 0) >= ($hits[2]->score ?? 0));

    check('capabilities 声明 brute', ($store->capabilities()['type'] ?? '') === 'brute');
} catch (\Throwable $e) {
    check('数据库往返无异常', false, $e->getMessage());
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

echo "== 5. 容器装配与 helper ==\n";
$wired = [
    \wise\rag\parser\ParserRegistry::class,
    \wise\rag\splitter\SplitterFactory::class,
    \wise\rag\client\EmbeddingFactory::class,
    \wise\rag\vector\VectorFactory::class,
    \wise\rag\service\KnowledgeBaseService::class,
    \wise\rag\service\IngestionService::class,
    \wise\rag\service\RetrievalService::class,
];
foreach ($wired as $class) {
    $short = substr((string) strrchr($class, '\\'), 1);
    try {
        check("容器可解析 {$short}", $app->make($class) instanceof $class);
    } catch (\Throwable $e) {
        check("容器可解析 {$short}", false, $e->getMessage());
    }
}
foreach (['wise_rag_create', 'wise_rag_ingest', 'wise_rag_search', 'wise_rag_delete_document'] as $fn) {
    check("helper 已加载 {$fn}", function_exists($fn));
}

@unlink($txtFile);
@unlink($mdFile);
@rmdir($tmpDir);

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P1 验证通过 ✅\n" : "P1 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
