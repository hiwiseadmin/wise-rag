<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. 包元数据（composer.json / 薄规范）
 *   2. 服务装配（Provider / 配置合并 / 两个桥 / helper）
 *   3. 六张表结构与关键列类型、唯一索引
 *   4. 数据库往返（建空间 / 唯一键 / 建页面 / 自清理）
 *   5. 菜单 / 权限 / 路由投递
 *   6. 控制器与视图
 *   7. 与 wise-rag 的共存安全（不被 prune 误判为孤儿知识源）
 *
 * 用法：php src/packages/wiseadmin/wise-llm-wiki/tests/verify-p0.php
 * 退出码：0 全部通过；1 存在失败
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use think\facade\Db;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\wiki\bridge\AgentBridge;
use wise\wiki\bridge\RagBridge;
use wise\wiki\MenuProvider;
use wise\wiki\security\WikiPermissionProvider;

$app = new \think\App();
$app->initialize();

$failures = [];
$checks   = 0;
$created  = ['space_id' => 0, 'page_id' => 0];

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

function tableExists(string $table): bool
{
    try {
        Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function columnType(string $table, string $column): string
{
    try {
        $rows = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return strtolower((string) ($rows[0]['Type'] ?? ''));
    } catch (\Throwable $e) {
        return '';
    }
}

function indexExists(string $table, string $index): bool
{
    try {
        $rows = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");

        return $rows !== [];
    } catch (\Throwable $e) {
        return false;
    }
}

$pkgRoot = dirname(__DIR__);

echo "== 1. 包元数据 ==\n";
$composerRaw = @file_get_contents($pkgRoot . '/composer.json');
$composer    = json_decode((string) $composerRaw, true);
check('composer.json 可解析', is_array($composer));
check('包名正确', ($composer['name'] ?? '') === 'wiseadmin/wise-llm-wiki', (string) ($composer['name'] ?? ''));
check('require 依赖 wise-rag', array_key_exists('wiseadmin/wise-rag', (array) ($composer['require'] ?? [])));
$services = (array) ($composer['extra']['think']['services'] ?? []);
check('extra.think.services 声明 Provider', in_array('wise\\wiki\\WikiServiceProvider', $services, true), implode(',', $services));
check('未使用已废弃的 migrate-path 字段', !isset($composer['extra']['think']['migrate-path']));
check('autoload 含 wise\\wiki 命名空间', isset($composer['autoload']['psr-4']['wise\\wiki\\']));

echo "== 2. 服务装配 ==\n";
check('服务提供者类存在', class_exists(\wise\wiki\WikiServiceProvider::class));
check('配置已合并 enable', config('wise-rag.wiki.enable') === true, var_export(config('wise-rag.wiki.enable'), true));
check('配置 llm.temperature 默认 0.2', (float) config('wise-rag.wiki.llm.temperature') === 0.2);
check('配置 compile.prompt_version = v1', config('wise-rag.wiki.compile.prompt_version') === 'v1');
check('配置 web.prefix = admin/wiki', config('wise-rag.wiki.web.prefix') === 'admin/wiki');

try {
    check('容器可解析 RagBridge', $app->make(RagBridge::class) instanceof RagBridge);
} catch (\Throwable $e) {
    check('容器可解析 RagBridge', false, $e->getMessage());
}
try {
    check('容器可解析 AgentBridge', $app->make(AgentBridge::class) instanceof AgentBridge);
} catch (\Throwable $e) {
    check('容器可解析 AgentBridge', false, $e->getMessage());
}
check('RagBridge 判定 wise-rag 可用', RagBridge::available());
$helpers = ['wise_wiki_enabled', 'wise_wiki_rag', 'wise_wiki_agent', 'wise_wiki_compilable'];
foreach ($helpers as $fn) {
    check("helper 已加载 {$fn}", function_exists($fn));
}

echo "== 3. 表结构 ==\n";
$tables = [
    'ai_wiki_spaces',
    'ai_wiki_pages',
    'ai_wiki_page_revisions',
    'ai_wiki_links',
    'ai_wiki_dependencies',
    'ai_wiki_compile_jobs',
];
$realTables = [];
foreach ($tables as $t) {
    $real = (string) Db::name($t)->getTable();
    $realTables[$t] = $real;
    check("表已创建 {$t}", tableExists($real));
}

check(
    'pages.content_md 为 longtext',
    str_contains(columnType($realTables['ai_wiki_pages'], 'content_md'), 'longtext'),
    columnType($realTables['ai_wiki_pages'], 'content_md')
);
check(
    'revisions.content_md 为 longtext',
    str_contains(columnType($realTables['ai_wiki_page_revisions'], 'content_md'), 'longtext'),
    columnType($realTables['ai_wiki_page_revisions'], 'content_md')
);
check(
    'pages 具备增量判定列（source_fingerprint / shadow_doc_id / dirty_reason）',
    columnType($realTables['ai_wiki_pages'], 'source_fingerprint') !== ''
    && columnType($realTables['ai_wiki_pages'], 'shadow_doc_id') !== ''
    && columnType($realTables['ai_wiki_pages'], 'dirty_reason') !== ''
);
check(
    'dependencies 记录 doc_hash（防 --force 全量重编译）',
    columnType($realTables['ai_wiki_dependencies'], 'doc_hash') !== ''
);
check(
    'compile_jobs 用 trigger_type（规避 MySQL 保留字）',
    columnType($realTables['ai_wiki_compile_jobs'], 'trigger_type') !== ''
    && columnType($realTables['ai_wiki_compile_jobs'], 'trigger') === ''
);

$ukMap = [
    'ai_wiki_spaces'          => 'uk_space_key',
    'ai_wiki_pages'           => 'uk_space_slug',
    'ai_wiki_page_revisions'  => 'uk_page_rev',
    'ai_wiki_links'           => 'uk_link',
    'ai_wiki_dependencies'    => 'uk_dep',
];
foreach ($ukMap as $t => $uk) {
    check("唯一索引存在 {$t}.{$uk}", indexExists($realTables[$t], $uk));
}

echo "== 4. 数据库往返 ==\n";
$spaceKey = 'verify_p0_' . substr(md5((string) microtime(true)), 0, 8);
try {
    $now      = date('Y-m-d H:i:s');
    $spaceId  = (int) Db::name('ai_wiki_spaces')->insertGetId([
        'space_key'     => $spaceKey,
        'name'          => 'P0 验证空间',
        'source_kb_id'  => 0,
        'shadow_kb_id'  => 0,
        'owner_type'    => 'admin',
        'owner_id'      => 0,
        'visibility'    => 'private',
        'status'        => 'active',
        'create_time'   => $now,
        'update_time'   => $now,
    ]);
    $created['space_id'] = $spaceId;
    check('插入空间成功', $spaceId > 0, (string) $spaceId);

    $row = Db::name('ai_wiki_spaces')->where('id', $spaceId)->find();
    check('回读空间字段一致', is_array($row) && $row['space_key'] === $spaceKey && $row['status'] === 'active');

    $dup = false;
    try {
        Db::name('ai_wiki_spaces')->insert([
            'space_key'   => $spaceKey,
            'name'        => '重复键',
            'create_time' => $now,
            'update_time' => $now,
        ]);
    } catch (\Throwable $e) {
        $dup = true;
    }
    check('space_key 唯一键生效', $dup);

    $longText = str_repeat('页面正文测试。', 8000);
    $pageId   = (int) Db::name('ai_wiki_pages')->insertGetId([
        'space_id'           => $spaceId,
        'slug'               => 'verify-page',
        'title'              => 'P0 验证页',
        'kind'               => 'topic',
        'status'             => 'draft',
        'content_md'         => $longText,
        'summary'            => '摘要',
        'rev_no'             => 1,
        'source_fingerprint' => str_repeat('a', 40),
        'staleness'          => 0,
        'quality_score'      => 0,
        'dirty_reason'       => '',
        'editor_type'        => 'system',
        'editor_id'          => 0,
        'locked_by'          => '',
        'create_time'        => $now,
        'update_time'        => $now,
    ]);
    $created['page_id'] = $pageId;
    check('插入页面成功', $pageId > 0, (string) $pageId);

    $page = Db::name('ai_wiki_pages')->where('id', $pageId)->find();
    check(
        '长正文可完整读写（longtext 生效）',
        is_array($page) && mb_strlen((string) $page['content_md']) === mb_strlen($longText),
        is_array($page) ? (string) mb_strlen((string) $page['content_md']) : 'no row'
    );
    check('rev_no / fingerprint 回读正确', is_array($page) && (int) $page['rev_no'] === 1 && strlen((string) $page['source_fingerprint']) === 40);
} catch (\Throwable $e) {
    check('数据库往返无异常', false, $e->getMessage());
} finally {
    try {
        if ($created['page_id'] > 0) {
            Db::name('ai_wiki_pages')->where('id', $created['page_id'])->delete();
        }
        if ($created['space_id'] > 0) {
            Db::name('ai_wiki_spaces')->where('id', $created['space_id'])->delete();
        }
        $left = (int) Db::name('ai_wiki_spaces')->where('space_key', $spaceKey)->count();
        check('清理验证数据', $left === 0, 'left=' . $left);
    } catch (\Throwable $e) {
        check('清理验证数据', false, $e->getMessage());
    }
}

echo "== 5. 菜单 / 权限 / 路由 ==\n";
$menu = new MenuProvider();
check('菜单 ident 正确', $menu->ident() === 'wise-rag.wiki', $menu->ident());

$containers = $menu->containers();
check('菜单容器挂靠「插件管理」', (($containers[0]['anchor'] ?? '') === '插件管理'), (string) ($containers[0]['anchor'] ?? ''));

$items = $menu->items();
$first = $items[0] ?? [];
check('菜单入口指向 /admin/wiki/index', ($first['path'] ?? '') === '/admin/wiki/index', (string) ($first['path'] ?? ''));
check('菜单声明了按钮级权限节点', count((array) ($first['child'] ?? [])) >= 5, (string) count((array) ($first['child'] ?? [])));

$perm  = (new WikiPermissionProvider())->collect();
$read  = $perm[WikiPermissionProvider::PERMISSION_READ] ?? [];
$write = $perm[WikiPermissionProvider::PERMISSION_WRITE] ?? [];
check('权限 provider 声明两项', count($perm) === 2, (string) count($perm));
check('读权限 access=read', ($read['access'] ?? '') === 'read', (string) ($read['access'] ?? ''));
check('写权限 access=write', ($write['access'] ?? '') === 'write', (string) ($write['access'] ?? ''));

$adminRoutes = array_keys(ExtPoint::all(ExtPoints::ROUTE_ADMIN));
check(
    '路由已投递 ROUTE_ADMIN（wise-llm-wiki:admin）',
    in_array('wise-llm-wiki:admin', $adminRoutes, true),
    implode(',', array_filter($adminRoutes, static fn ($n) => str_contains((string) $n, 'wiki')))
);

echo "== 6. 控制器与视图 ==\n";
$controller = 'wise\\wiki\\controller\\admin\\WikiController';
check('控制器类存在', class_exists($controller));
if (class_exists($controller)) {
    $methods = ['index', 'spaces', 'pages', 'jobs', 'compile', 'pageSave', 'pageDelete', 'testRetrieval'];
    $missing = array_values(array_filter($methods, static fn ($m) => !method_exists($controller, $m)));
    check('控制器端点齐全', $missing === [], implode(',', $missing));
    check('控制器继承后台 BaseController', is_subclass_of($controller, \app\admin\controller\BaseController::class));
}
check('包内视图存在', is_file($pkgRoot . '/view/admin/wiki/index.html'));

echo "== 7. 与 wise-rag 的共存安全 ==\n";
check(
    '未创建任何 ai_kb_sources 记录（prune 不会判为孤儿知识源）',
    (int) Db::name('ai_kb_sources')->where('source_key', 'like', '%wise-llm-wiki%')->count() === 0
);
$migrationFiles = glob($pkgRoot . '/database/migrations/*.php') ?: [];
$noDown         = [];
foreach ($migrationFiles as $file) {
    if (!str_contains((string) file_get_contents($file), 'public function down')) {
        $noDown[] = basename($file);
    }
}
check('六个迁移文件齐全且都实现 down()', count($migrationFiles) === 6 && $noDown === [], implode(',', $noDown));

echo "\n----------------------------------------\n";
echo "检查项：{$checks}，失败： " . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo count($failures) === 0 ? "P0 验证通过 ✅\n" : "P0 验证未通过 ❌\n";

exit(count($failures) === 0 ? 0 : 1);
