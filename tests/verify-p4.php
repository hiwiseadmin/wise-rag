<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 包内界面：控制器类、视图 scope 解析、静态资源已发布
 *   2. 关键词驱动：工厂映射、FULLTEXT 可用性、LIKE 回退、短查询保护
 *   3. 混合检索：RRF 融合能召回「仅关键词命中」的分块（纯向量召回不到）
 *   4. 重排：驱动工厂 + 顺序被重排
 *   5. 检索日志：log_enabled 时写入 ai_kb_retrieval_logs
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p4.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use wise\rag\client\EmbeddingFactory;
use wise\rag\dto\RetrievalQuery;
use wise\rag\dto\ScoredChunk;
use wise\rag\keyword\FulltextDriver;
use wise\rag\keyword\KeywordDriverFactory;
use wise\rag\keyword\LikeDriver;
use wise\rag\rerank\RerankerFactory;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\source\SourceFactory;
use wise\rag\splitter\SplitterFactory;
use wise\rag\vector\DatabaseVectorStore;
use wise\rag\vector\VectorFactory;
use wise\rag\vector\VectorMath;

require __DIR__ . '/../../../../vendor/autoload.php';

class WiseRagP4Embedding implements \wise\rag\contract\EmbeddingInterface
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

/** 假重排器：反转候选顺序，用于验证重排链路生效 */
class WiseRagP4Reranker implements \wise\rag\contract\RerankerInterface
{
    public function rerank(string $query, array $candidates, int $topN): array
    {
        $reversed = array_reverse($candidates);

        foreach ($reversed as $i => $chunk) {
            $chunk->score = 1.0 - ($i * 0.1);
        }

        return array_slice($reversed, 0, max(1, $topN));
    }

    public function getName(): string
    {
        return 'fake';
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

$embedding = new EmbeddingFactory([
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WiseRagP4Embedding', 'model' => 'verify', 'dimension' => 4]],
]);
$vector    = new VectorFactory(['default' => 'database', 'stores' => ['database' => ['class' => DatabaseVectorStore::class]]]);
$keyword   = new KeywordDriverFactory([
    'driver'   => 'mysql_fulltext',
    'fallback' => 'like',
    'min_length' => 2,
    'max_terms'  => 8,
]);
$kbService = new KnowledgeBaseService($embedding, $vector);
$retrieval = new RetrievalService(
    $embedding,
    $vector,
    $kbService,
    $keyword,
    new RerankerFactory(['providers' => ['fake' => ['class' => 'WiseRagP4Reranker']]])
);
$ingestion = new IngestionService(new \wise\rag\parser\ParserRegistry([]), new SplitterFactory('structure'), $embedding, $vector, $kbService, new SourceFactory([]));

$kbId = 0;

try {
    echo "== 1. 包内后台界面 ==\n";
    check('控制器类存在', class_exists(\wise\rag\controller\admin\KbController::class));
    $root = class_exists(\app\common\view\ViewScope::class) ? \app\common\view\ViewScope::resolveRoot('wise-rag') : null;
    check('视图 scope 可解析到包内 view/', is_string($root) && str_contains(str_replace('\\', '/', $root), 'wise-rag/view/'), (string) $root);
    if (class_exists(\app\common\view\ViewScope::class)) {
        $hit = \app\common\view\ViewScope::locate('wise-rag@admin/kb/index');
        check('视图 admin/kb/index 可定位', !empty($hit['file']), (string) ($hit['file'] ?? ''));
    }
    $js = public_path() . 'statics' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'wise-rag' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'kb.js';
    check('前端脚本已发布到 statics', is_file($js), $js);

    if (class_exists(\wise\support\ExtPoint::class) && class_exists(\wise\support\ExtPoints::class)) {
        $routeNames = [];
        try {
            $routeNames = array_keys(\wise\support\ExtPoint::all(\wise\support\ExtPoints::ROUTE_ADMIN));
        } catch (\Throwable $e) {
        }
        check('后台路由已投递到 ROUTE_ADMIN（admin 应用内落地）', in_array('wise-rag:admin', $routeNames, true), implode(',', $routeNames));
    }

    echo "== 2. 关键词驱动 ==\n";
    check('工厂内置 fulltext/like', array_keys(KeywordDriverFactory::builtin()) === ['mysql_fulltext', 'like']);
    check('可创建 FULLTEXT 驱动', $keyword->make('mysql_fulltext') instanceof FulltextDriver);
    check('可创建 LIKE 驱动', $keyword->make('like') instanceof LikeDriver);

    $kb   = $kbService->create(['kb_key' => 'p4_verify_' . substr(md5((string) microtime(true)), 0, 6), 'name' => 'P4 验证库', 'embedding' => 'verify']);
    $kbId = (int) $kb->id;

    $now = date('Y-m-d H:i:s');
    $docId = (int) Db::name('ai_kb_documents')->insertGetId([
        'kb_id' => $kbId, 'source_id' => 0, 'external_id' => 'p4-doc', 'title' => 'A100 手册',
        'mime' => 'text/markdown', 'file_path' => null, 'url' => '/doc/a100',
        'content_hash' => md5('p4'), 'char_count' => 40, 'chunk_count' => 2,
        'owner_type' => 'admin', 'owner_id' => 0, 'status' => 'indexed', 'error' => null, 'meta' => null,
        'create_time' => $now, 'update_time' => $now,
    ]);

    $rows = [
        ['A100 功率 250W，支持 PCIe 4.0。', [1.0, 0.0, 0.0, 0.0]],
        ['A100 散热说明：被动散热设计。', [0.0, 1.0, 0.0, 0.0]],
    ];
    foreach ($rows as $i => [$content, $vec]) {
        Db::name('ai_kb_chunks')->insert([
            'kb_id' => $kbId, 'doc_id' => $docId, 'chunk_index' => $i, 'content' => $content,
            'token_count' => mb_strlen($content), 'heading' => null, 'page' => null,
            'content_hash' => md5($content), 'vector' => VectorMath::pack($vec), 'dimension' => 4,
            'owner_type' => 'admin', 'owner_id' => 0, 'create_time' => $now, 'update_time' => $now,
        ]);
    }

    FulltextDriver::resetAvailability();
    try {
        Cache::delete('wise_rag:ft_available:' . md5((string) Db::name('ai_kb_chunks')->getTable()));
    } catch (\Throwable $e) {
    }
    $ft          = $keyword->make('mysql_fulltext');
    $ftAvailable = $ft->isAvailable();
    check('FULLTEXT 可用性探测完成', is_bool($ftAvailable), $ftAvailable ? 'available' : 'unavailable');

    $ftHits = $ft->search($kb->collection, 'A100', 5);
    check('FULLTEXT 关键词命中', count($ftHits) >= 2, 'count=' . count($ftHits));
    check('FULLTEXT 短查询保护（1 字 → 空）', $ft->search($kb->collection, 'A', 5) === []);

    $likeHits = $keyword->make('like')->search($kb->collection, 'A100', 5);
    check('LIKE 回退驱动命中', count($likeHits) >= 2, 'count=' . count($likeHits));
    check('LIKE 转义下划线通配', $keyword->make('like')->search($kb->collection, 'A_100', 5) === []);

    echo "== 3. 混合检索（RRF） ==\n";
    $hybridHits = $retrieval->retrieve(new RetrievalQuery('A100', null, [$kbId], 5, [], true));
    check('混合检索有命中', count($hybridHits) >= 1, 'count=' . count($hybridHits));

    $contents = array_map(static fn (ScoredChunk $h): string => $h->content, $hybridHits);
    $hasOrthogonal = false;
    foreach ($contents as $c) {
        if (str_contains($c, '散热')) {
            $hasOrthogonal = true;
        }
    }
    check('关键词独有命中被融合召回（正交向量）', $hasOrthogonal, implode(' | ', $contents));
    check('融合分数已归一化（top=1）', abs(($hybridHits[0]->score ?? 0) - 1.0) < 1e-6, (string) ($hybridHits[0]->score ?? -1));
    check('引用溯源存在', ($hybridHits[0]->meta['cite']['title'] ?? '') === 'A100 手册');

    $vectorOnly = $retrieval->retrieve(new RetrievalQuery('A100', null, [$kbId], 5, [], false));
    $onlyVectorKeepsOrthogonal = false;
    foreach ($vectorOnly as $h) {
        if (str_contains($h->content, '散热')) {
            $onlyVectorKeepsOrthogonal = true;
        }
    }
    check('关闭混合时向量召回不含正交分块', !$onlyVectorKeepsOrthogonal, 'count=' . count($vectorOnly));

    echo "== 4. 重排 ==\n";
    $ragConfig                                        = (array) config('wise-rag');
    $ragConfig['retrieval']['rerank']                 = ['enabled' => true, 'provider' => 'fake', 'top_n' => 4];
    Config::set(['wise-rag' => $ragConfig]);

    $reranked = $retrieval->retrieve(new RetrievalQuery('A100', null, [$kbId], 5, [], true));
    check('重排后仍有结果', count($reranked) >= 2, 'count=' . count($reranked));
    check('重排改变了首位（反转生效）', ($reranked[0]->content ?? '') !== ($hybridHits[0]->content ?? ''), (string) ($reranked[0]->content ?? ''));
    check('重排分数由驱动给出', ($reranked[0]->score ?? 0) > 0.9);

    echo "== 5. 检索日志 ==\n";
    $ragConfig['retrieval']['rerank']['enabled'] = false;
    $ragConfig['retrieval']['log_enabled']       = true;
    Config::set(['wise-rag' => $ragConfig]);

    $before = (int) Db::name('ai_kb_retrieval_logs')->count();
    $retrieval->retrieve(new RetrievalQuery('A100 功率', null, [$kbId], 3, [], true));
    $after = (int) Db::name('ai_kb_retrieval_logs')->count();
    check('检索日志已写入', $after === $before + 1, "before={$before} after={$after}");

    $log = Db::name('ai_kb_retrieval_logs')->order('id', 'desc')->find();
    check('日志字段完整', is_array($log) && (int) $log['hit_count'] >= 1 && (int) $log['kb_id'] === $kbId, json_encode($log ? array_intersect_key($log, array_flip(['kb_id', 'hit_count', 'top_score'])) : []));
} catch (\Throwable $e) {
    check('P4 主流程无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    echo "== 6. 清理 ==\n";
    if ($kbId > 0) {
        try {
            $kbService->delete($kbId, true);
        } catch (\Throwable $e) {
        }
    }
    try {
        Db::name('ai_kb_retrieval_logs')->where('kb_id', $kbId)->delete();
    } catch (\Throwable $e) {
    }
    check('验证数据已清理', Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->count() === 0);
}

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P4 验证通过 ✅\n" : "P4 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
