<?php
declare(strict_types=1);

namespace wise\rag;

use think\facade\Route;
use wise\support\router\RouteRegistrar as BaseRouteRegistrar;

/**
 * wise-rag 路由注册器（配置驱动）
 *
 * 前缀 / 中间件 / 开关全部由 config('wise-rag.web') 控制，包源码不改：
 *
 * ```php
 * 'web' => [
 *     'enable' => true,
 *     'app'    => 'admin',
 *     'prefix' => 'admin/kb',
 *     'groups' => [
 *         'admin' => [
 *             'enable'     => true,
 *             'middleware' => [\wise\auth\middleware\RbacMiddleware::class],
 *         ],
 *     ],
 * ],
 * ```
 */
class RouteRegistrar extends BaseRouteRegistrar
{
    protected string $configKey = 'wise-rag';

    protected function define(array $config): void
    {
        $this->group('admin', static function (): void {
            $controller = '\wise\rag\controller\admin\KbController';

            Route::rule('index', "{$controller}@index", 'GET');

            Route::rule('bases', "{$controller}@bases", 'GET|POST');
            Route::rule('base/save', "{$controller}@baseSave", 'POST');
            Route::rule('base/delete', "{$controller}@baseDelete", 'POST');
            Route::rule('base/reindex', "{$controller}@baseReindex", 'POST');

            Route::rule('documents', "{$controller}@documents", 'GET|POST');
            Route::rule('document/upload', "{$controller}@documentUpload", 'POST');
            Route::rule('document/ingest', "{$controller}@documentIngest", 'POST');
            Route::rule('document/delete', "{$controller}@documentDelete", 'POST');

            Route::rule('source/list', "{$controller}@sourceList", 'GET|POST');
            Route::rule('source/sync', "{$controller}@sourceSync", 'POST');

            Route::rule('test-retrieval', "{$controller}@testRetrieval", 'GET|POST');
            Route::rule('stats', "{$controller}@stats", 'GET|POST');
        });
    }
}
