<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 人工编辑：乐观锁（rev_no 冲突拒绝）、空值校验、版本快照、editor_type=admin
 *   2. 人工优先：人工编辑后重编译只产生候选版本，不覆盖正文
 *   3. 候选版本：detail 暴露、apply 写回正文并发布、无候选时报错
 *   4. 发布 / 归档：影子库进出（发布态才可检索）
 *   5. 列表与详情：分页、状态 / 脏 / 关键词过滤
 *   6. 空间管理：更新、非法状态拒绝、重复 key 拒绝、删除级联
 *   7. 编译台：失败任务重试
 *   8. 契约一致性：路由 ↔ 菜单权限节点一一对应、控制器方法齐全、前端资源已发布
 *
 * 用法：php src/packages/wiseadmin/wise-llm-wiki/tests/verify-p3.php
 * 退出码：0 全部通过；1 存在失败
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use think\facade\Config;
use think\facade\Db;
use wise\agent\contract\AiClientInterface;
use wise\rag\client\EmbeddingFactory;
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
use wise\wiki\dto\CompileInput;
use wise\wiki\exception\WikiException;
use wise\wiki\MenuProvider;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;
use wise\wiki\service\CompileService;
use wise\wiki\service\PageService;
use wise\wiki\service\SpaceService;

class WikiP3Embedding implements \wise\rag\contract\EmbeddingInterface
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

class WikiP3Llm implements AiClientInterface
{
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $context = (string) ($messages[1]['content'] ?? '');
        preg_match_all('/^\[(\d{1,3})\]/m', $context, $m);
        $nos = array_values(array_unique(array_map('intval', $m[1] ?? []))) ?: [1];

        $payload = [
            'title'      => 'A100 功率与散热',
            'slug'       => 'a100-power-cooling',
            'kind'       => 'topic',
            'summary'    => 'LLM 生成的摘要。',
            'markdown'   => sprintf("# A100\n\nLLM 结论。[%d]\n\n## 散热\n\n散热说明。[%d]\n", $nos[0], $nos[1] ?? $nos[0]),
            'citations'  => array_values(array_unique([$nos[0], $nos[1] ?? $nos[0]])),
            'links'      => [],
            'confidence' => 0.9,
        ];

        return [
            'content'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'tool_calls' => [],
            'usage'      => ['prompt_tokens' => 80, 'completion_tokens' => 40],
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
$created  = ['space_id' => 0, 'src_kb_id' => 0, 'shadow_kb_id' => 0, 'page_id' => 0, 'space2_id' => 0];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  [ok]   {$label}\n";
        return;
    }
    $failures[] = $label . ($detail !== '' ? " ({$detail})" : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : "") . "\n";
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

/**
 * 清掉容器里已缓存的本包服务实例（原因见 verify-p1 同名函数注释）
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

$pkgRoot   = dirname(__DIR__);
$embedding = new EmbeddingFactory([
    'default'   => 'verify',
    'providers' => ['verify' => ['class' => 'WikiP3Embedding', 'model' => 'verify', 'dimension' => 4]],
]);
$vector    = new VectorFactory(['default' => 'database', 'stores' => ['database' => ['class' => DatabaseVectorStore::class]]]);
$kbService = new KnowledgeBaseService($embedding, $vector);
$ingestion = new IngestionService(new ParserRegistry([]), new SplitterFactory('structure'), $embedding, $vector, $kbService, new SourceFactory([]));
$retrieval = new RetrievalService($embedding, $vector, $kbService);
$bridge    = new RagBridge($kbService, $retrieval, $ingestion);

$app->instance(RagBridge::class, $bridge);
$app->instance(AgentBridge::class, new AgentBridge(new WikiP3Llm()));
purgeWikiServices($app);

setWikiCfg('queue', ['enabled' => false]);
setWikiCfg('compile', ['auto_publish' => true]);

try {
    echo "== 0. 准备 ==\n";

    $srcKb = $kbService->create(['kb_key' => 'p3_src_' . substr(md5((string) microtime(true)), 0, 6), 'name' => 'P3 源库', 'embedding' => 'verify']);
    $created['src_kb_id'] = (int) $srcKb->id;
    $ingestion->ingestText($srcKb, "# A100 手册\n\nA100 功率 250W，支持 PCIe 4.0 与 NVLink 互联。\n\n## 散热\n\n采用被动散热设计，满载温度控制在 75 度以内。", [
        'external_id' => 'p3-a100',
        'title'       => 'A100 手册',
    ]);

    $spaces = $app->make(SpaceService::class);
    $space  = $spaces->create([
        'space_key'    => 'p3_verify_' . substr(md5((string) $srcKb->id), 0, 6),
        'name'         => 'P3 验证空间',
        'source_kb_id' => (int) $srcKb->id,
    ]);
    $created['space_id']     = (int) $space->id;
    $created['shadow_kb_id'] = (int) $space->shadow_kb_id;

    $chunkCount = (int) Db::name('ai_kb_chunks')->where('kb_id', (int) $srcKb->id)->count();
    $probeHits  = $bridge->retrieve('A100 功率', [(int) $srcKb->id], 5);
    check('源库已索引且可检索', $chunkCount >= 1 && $probeHits !== [], 'chunks=' . $chunkCount . ', hits=' . count($probeHits));

    $compile = $app->make(CompileService::class);
    $first   = $compile->compile(new CompileInput((int) $space->id, 'A100 功率'));
    $pageId  = (int) ($first['page_id'] ?? 0);
    $created['page_id'] = $pageId;

    check(
        '初始页面已发布（auto_publish=true）',
        $pageId > 0 && ($first['status'] ?? '') === WikiPage::STATUS_PUBLISHED,
        json_encode($first, JSON_UNESCAPED_UNICODE)
    );

    $pages = $app->make(PageService::class);

    echo "== 1. 人工编辑与乐观锁 ==\n";

    $revBefore = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('rev_no');
    $saved     = $pages->saveManual($pageId, [
        'title'      => 'A100 人工修订版',
        'content_md' => "# A100\n\n人工写的内容，必须保留。[1]\n",
        'summary'    => '人工摘要',
    ], $revBefore, 7);

    check('人工保存成功', ($saved['rev_no'] ?? 0) === $revBefore + 1, json_encode($saved));
    check('editor_type 变为 admin', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('editor_type') === 'admin');
    check('editor_id 记录正确', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('editor_id') === 7);
    check('人工版本写入 revisions', (string) Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->where('rev_no', $revBefore + 1)->value('editor_type') === 'admin');
    check('人工版本 change_reason=manual', (string) Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->where('rev_no', $revBefore + 1)->value('change_reason') === 'manual');

    $conflict = '';
    try {
        $pages->saveManual($pageId, ['title' => '冲突写入', 'content_md' => 'x'], $revBefore, 7);
    } catch (WikiException $e) {
        $conflict = $e->getMessage();
    }
    check('过期版本号被拒绝并提示刷新', $conflict !== '' && str_contains($conflict, '刷新'), $conflict);
    check('冲突时正文未被覆盖', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('content_md') === "# A100\n\n人工写的内容，必须保留。[1]\n");

    $emptyTitle = '';
    try {
        $pages->saveManual($pageId, ['title' => '', 'content_md' => 'x'], 0, 7);
    } catch (WikiException $e) {
        $emptyTitle = $e->getMessage();
    }
    check('空标题被拒绝', str_contains($emptyTitle, '标题不能为空'), $emptyTitle);

    $emptyBody = '';
    try {
        $pages->saveManual($pageId, ['title' => 'x', 'content_md' => '   '], 0, 7);
    } catch (WikiException $e) {
        $emptyBody = $e->getMessage();
    }
    check('空正文被拒绝', str_contains($emptyBody, '正文不能为空'), $emptyBody);

    echo "== 2. 人工优先：重编译只产生候选版本 ==\n";

    $manualBody = (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('content_md');
    $manualRev  = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('rev_no');

    $recompile = $compile->compile(new CompileInput((int) $space->id, 'A100 功率', CompileInput::TRIGGER_MANUAL, $pageId));
    check('人工稿重编译标记 deferred', ($recompile['deferred'] ?? false) === true, json_encode($recompile));
    check('人工正文未被覆盖', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('content_md') === $manualBody);
    check('人工版本号未跳变', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('rev_no') === $manualRev);

    $detail = $pages->detail($pageId);
    check('详情返回候选版本', is_array($detail['candidate']) && (int) $detail['candidate']['rev_no'] === $manualRev + 1, json_encode(array_keys($detail)));
    check('详情返回版本列表', count($detail['revisions']) >= 2, (string) count($detail['revisions']));
    check('版本列表不含正文（只给预览）', !array_key_exists('content_md', $detail['revisions'][0]) && isset($detail['revisions'][0]['content_preview']));

    echo "== 3. 应用候选版本 ==\n";

    $candidateBody = (string) $detail['candidate']['content_md'];
    $applied       = $pages->applyCandidate($pageId, 7);

    check('应用后版本号推进到候选版本', (int) $applied['rev_no'] === $manualRev + 1, (string) $applied['rev_no']);
    check('应用后正文为候选内容', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('content_md') === $candidateBody);
    check('应用后状态为 published', (string) $applied['status'] === WikiPage::STATUS_PUBLISHED);
    check('应用后已发布到影子库', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id') > 0);
    check('应用后不再有候选版本', $pages->candidate($pageId) === null);

    $noCandidate = '';
    try {
        $pages->applyCandidate($pageId, 7);
    } catch (WikiException $e) {
        $noCandidate = $e->getMessage();
    }
    check('无候选时报可读错误', str_contains($noCandidate, '没有待应用'), $noCandidate);

    echo "== 4. 发布 / 归档 ==\n";

    $archived = $pages->archive($pageId, 7);
    check('归档成功', ($archived['archived'] ?? false) === true);
    check('归档后影子文档已撤回', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id') === 0);
    check('归档后状态为 archived', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('status') === WikiPage::STATUS_ARCHIVED);

    $published = $pages->publish($pageId, 7);
    check('发布成功', ($published['published'] ?? false) === true && (int) $published['doc_id'] > 0, json_encode($published));
    check('发布后状态为 published', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('status') === WikiPage::STATUS_PUBLISHED);

    $pages->saveManual($pageId, [
        'title'      => 'A100 人工修订版',
        'content_md' => "# A100\n\n临时改回草稿，不应再被检索。[1]\n",
        'status'     => WikiPage::STATUS_DRAFT,
    ], (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('rev_no'), 7);

    check('改回 draft 后状态正确', (string) Db::name('ai_wiki_pages')->where('id', $pageId)->value('status') === WikiPage::STATUS_DRAFT);
    check('改回 draft 后影子文档已撤下', (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('shadow_doc_id') === 0);

    $pages->publish($pageId, 7);

    echo "== 5. 列表与详情 ==\n";

    $list = $pages->paginate(['space_id' => (int) $space->id], 1, 10);
    check('分页返回总数', ($list['total'] ?? 0) >= 1);
    check('列表不含正文大字段', !array_key_exists('content_md', $list['list'][0]));

    $byStatus = $pages->paginate(['space_id' => (int) $space->id, 'status' => WikiPage::STATUS_PUBLISHED]);
    check('按状态过滤', ($byStatus['total'] ?? 0) >= 1);

    $byOther = $pages->paginate(['space_id' => (int) $space->id, 'status' => WikiPage::STATUS_DRAFT]);
    check('过滤不匹配状态时总数为 0', ($byOther['total'] ?? -1) === 0, (string) ($byOther['total'] ?? -1));

    $pages->markDirty($pageId, WikiPage::DIRTY_MANUAL);
    $byDirty = $pages->paginate(['space_id' => (int) $space->id, 'dirty' => 1]);
    check('按待重编译过滤', ($byDirty['total'] ?? 0) === 1);
    $pages->clearDirty($pageId);

    $byKeyword = $pages->paginate(['space_id' => (int) $space->id, 'keyword' => '人工修订']);
    check('按关键词过滤标题', ($byKeyword['total'] ?? 0) === 1, (string) ($byKeyword['total'] ?? 0));

    $revRow = $pages->revision($pageId, 1);
    check('可按版本号取全文', is_array($revRow) && isset($revRow['content_md']));

    echo "== 6. 空间管理 ==\n";

    $updated = $spaces->update((int) $space->id, ['name' => 'P3 验证空间（改）', 'budget_tokens_month' => 12345]);
    check('空间可更新名称与预算', (string) $updated->name === 'P3 验证空间（改）' && (int) $updated->budget_tokens_month === 12345);

    $badStatus = '';
    try {
        $spaces->update((int) $space->id, ['status' => 'whatever']);
    } catch (WikiException $e) {
        $badStatus = $e->getMessage();
    }
    check('非法状态被拒绝', str_contains($badStatus, '非法状态'), $badStatus);

    $dupKey = '';
    try {
        $spaces->create(['space_key' => (string) $space->space_key, 'name' => 'dup', 'source_kb_id' => (int) $srcKb->id]);
    } catch (WikiException $e) {
        $dupKey = $e->getMessage();
    }
    check('重复 space_key 被拒绝', str_contains($dupKey, '空间已存在'), $dupKey);

    echo "== 7. 编译台：失败任务重试 ==\n";

    $failedJobId = (int) Db::name('ai_wiki_compile_jobs')->insertGetId([
        'space_id'       => (int) $space->id,
        'page_id'        => $pageId,
        'trigger_type'   => 'manual',
        'status'         => 'failed',
        'prompt_version' => 'v1',
        'model_name'     => 'verify-1',
        'evidence_count' => 0,
        'tokens_in'      => 0,
        'tokens_out'     => 0,
        'cost'           => 0,
        'latency_ms'     => 0,
        'attempt'        => 3,
        'error'          => '模拟失败',
        'payload'        => json_encode(['topic' => 'A100 功率', 'slug' => 'a100-power-cooling', 'kind' => 'topic'], JSON_UNESCAPED_UNICODE),
        'create_time'    => date('Y-m-d H:i:s'),
        'update_time'    => date('Y-m-d H:i:s'),
    ]);

    $retried = $compile->retryJob($failedJobId);
    check('失败任务可重试并成功', ($retried['ok'] ?? false) === true, json_encode($retried['errors'] ?? []));

    $okJobId  = (int) Db::name('ai_wiki_compile_jobs')->insertGetId([
        'space_id'       => (int) $space->id,
        'page_id'        => $pageId,
        'trigger_type'   => 'manual',
        'status'         => 'ok',
        'prompt_version' => 'v1',
        'model_name'     => '',
        'evidence_count' => 0,
        'tokens_in'      => 0,
        'tokens_out'     => 0,
        'cost'           => 0,
        'latency_ms'     => 0,
        'attempt'        => 1,
        'error'          => '',
        'payload'        => '{}',
        'create_time'    => date('Y-m-d H:i:s'),
        'update_time'    => date('Y-m-d H:i:s'),
    ]);
    $badRetry = '';
    try {
        $compile->retryJob($okJobId);
    } catch (WikiException $e) {
        $badRetry = $e->getMessage();
    }
    check('成功任务不允许重试', str_contains($badRetry, '只有失败'), $badRetry);

    echo "== 8. 契约一致性 ==\n";

    $routeSrc = (string) file_get_contents($pkgRoot . '/src/RouteRegistrar.php');
    preg_match_all("/Route::rule\('([^']+)'/", $routeSrc, $rm);
    $routes = array_map(static fn (string $p): string => '/admin/wiki/' . $p, $rm[1] ?? []);

    $menuItem = (new MenuProvider())->items()[0] ?? [];
    $paths    = array_map(static fn (array $c): string => (string) ($c['path'] ?? ''), (array) ($menuItem['child'] ?? []));

    $routesWithoutEntry = array_values(array_diff($routes, ['/admin/wiki/index']));

    $missingInMenu = array_values(array_diff($routesWithoutEntry, $paths));
    check('每条路由都有菜单权限节点', $missingInMenu === [], implode(',', $missingInMenu));

    $missingInRoute = array_values(array_diff($paths, $routes));
    check('每个菜单节点都有对应路由', $missingInRoute === [], implode(',', $missingInRoute));

    preg_match_all('/@([a-zA-Z][a-zA-Z0-9]*)\"/', $routeSrc, $mm);
    $methods = array_values(array_unique($mm[1] ?? []));
    $controllerClass = 'wise\\wiki\\controller\\admin\\WikiController';
    $missingMethods  = array_values(array_filter($methods, static fn (string $m): bool => !method_exists($controllerClass, $m)));
    check('路由指向的控制器方法都存在', $missingMethods === [], implode(',', $missingMethods));
    check('路由与控制器方法数量 ≥ 15', count($methods) >= 15, (string) count($methods));

    $viewSrc = (string) file_get_contents($pkgRoot . '/view/admin/wiki/index.html');
    $tabs    = ['spaces', 'pages', 'jobs', 'debug'];
    $missingTabs = array_values(array_filter($tabs, static fn (string $t): bool => !str_contains($viewSrc, 'data-pane="' . $t . '"')));
    check('视图含四个页签', $missingTabs === [], implode(',', $missingTabs));
    check('视图含编辑抽屉与版本对比弹层', str_contains($viewSrc, 'id="page-drawer"') && str_contains($viewSrc, 'id="diff-modal"'));

    $publishedJs = $pkgRoot . '/../../../public/statics/plugins/wise-llm-wiki/js/wiki.js';
    check('前端脚本已发布到 statics', is_file($publishedJs), $publishedJs);

    $ctrlSrc = (string) file_get_contents($pkgRoot . '/src/controller/admin/WikiController.php');
    check('控制器有预算条数据端点', str_contains($ctrlSrc, 'public function stats'));
    check('控制器有源库下拉端点', str_contains($ctrlSrc, 'public function kbList'));

    echo "== 9. 删除级联 ==\n";

    $beforePages = (int) Db::name('ai_wiki_pages')->where('space_id', (int) $space->id)->count();
    check('删除前存在页面', $beforePages >= 1);

    $ok = $spaces->delete($space, true);
    check('空间删除成功', $ok === true);
    check('页面已级联删除', (int) Db::name('ai_wiki_pages')->where('space_id', (int) $space->id)->count() === 0);
    check('版本已级联删除', (int) Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->count() === 0);
    check('任务已级联删除', (int) Db::name('ai_wiki_compile_jobs')->where('space_id', (int) $space->id)->count() === 0);
    check('影子知识库已删除', (int) Db::name('ai_kb_knowledge_bases')->where('id', $created['shadow_kb_id'])->count() === 0);
    $created['space_id'] = 0;
} catch (\Throwable $e) {
    check('P3 端到端无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
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
echo count($failures) === 0 ? "P3 验证通过 ✅\n" : "P3 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
