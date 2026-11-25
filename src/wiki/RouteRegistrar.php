<?php
declare(strict_types=1);

namespace wise\wiki;

use think\facade\Route;
use wise\support\router\RouteRegistrar as BaseRouteRegistrar;

/**
 * wise-llm-wiki 路由注册器（配置驱动）
 *
 * 前缀 / 中间件 / 开关全部由 config('wise-rag.wiki.web') 控制，包源码不改。
 * 必须继承本基类：应用挂载由框架统一负责（投递到 ExtPoints::ROUTE_ADMIN 等待 admin 应用落地）。
 *
 * ⚠️ configKey 必须是**完整配置路径**（`wise-rag.wiki`），基类据此读取
 * `config($configKey . '.web')` 并推导扩展点实现名。写错（如 `rag.wiki`）时
 * 取到空配置 → enable 判定失败 → 整组路由静默不注册（表现为 404）。
 * 也不能退化成 `wise-rag`：与同包的 KB 路由注册器同名，扩展点里会互相覆盖。
 *
 * 约定：**每条路由都要在 MenuProvider 里有同名 path 节点**，否则后台拿不到派生权限
 * （verify-p3 会逐条断言，防止「接口能调但没人有权限」或「有权限但接口不存在」）。
 */
class RouteRegistrar extends BaseRouteRegistrar
{
    protected string $configKey = 'wise-rag.wiki';

    protected function define(array $config): void
    {
        $this->group('admin', static function (): void {
            $c = '\wise\wiki\controller\admin\WikiController';

            Route::rule('index', "{$c}@index", 'GET');
            Route::rule('stats', "{$c}@stats", 'GET|POST');

            Route::rule('spaces', "{$c}@spaces", 'GET|POST');
            Route::rule('kb-list', "{$c}@kbList", 'GET|POST');
            Route::rule('space/save', "{$c}@spaceSave", 'POST');
            Route::rule('space/delete', "{$c}@spaceDelete", 'POST');

            Route::rule('pages', "{$c}@pages", 'GET|POST');
            Route::rule('page', "{$c}@page", 'GET|POST');
            Route::rule('page/revision', "{$c}@pageRevision", 'GET|POST');
            Route::rule('page/save', "{$c}@pageSave", 'POST');
            Route::rule('page/publish', "{$c}@pagePublish", 'POST');
            Route::rule('page/archive', "{$c}@pageArchive", 'POST');
            Route::rule('page/delete', "{$c}@pageDelete", 'POST');
            Route::rule('page/apply', "{$c}@pageApply", 'POST');

            Route::rule('compile', "{$c}@compile", 'POST');
            Route::rule('jobs', "{$c}@jobs", 'GET|POST');
            Route::rule('job/retry', "{$c}@jobRetry", 'POST');

            Route::rule('test-retrieval', "{$c}@testRetrieval", 'GET|POST');
        });
    }
}
