<?php
declare(strict_types=1);

namespace wise\wiki;

use think\Service;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\wiki\bridge\RagBridge;
use wise\wiki\listener\DocumentIndexedListener;
use wise\wiki\security\WikiPermissionProvider;

/**
 * wise-llm-wiki 服务提供者（原独立包，现为 wise-rag 内的独立命名空间 `wise\wiki`）
 *
 * 源码位于 `src/wiki/`（与 `src/rag/` 平级），通过 composer.json 的
 * `extra.think.services` 与 `wise\rag\RagServiceProvider` **各自独立注册**
 * （与 `wise-auth` / `wise-login`、`wise-attachment` / `wise-image` 的组织方式一致）。
 *
 * 职责：
 *  1. 绑定对上游的两个桥（RagBridge / AgentBridge）
 *  2. 声明后台菜单（ExtPoints::MENU_ITEM）
 *  3. 注册 AI 工具权限提供者（AI_TOOL_PERMISSION_PROVIDER）
 *  4. 注册 Web 路由（配置驱动）
 *  5. 事件监听 / Agent 工具 / MCP 提供者
 *
 * 配置与迁移统一由 RagServiceProvider 负责（同一 `wise-rag.php` 的 `wiki` 段、
 * 同一 `database/migrations`），本 Provider 不重复 merge / 注册。
 * 约定：本包不引入任何自定义扩展点常量（设计文档决策 #7）。
 */
class WikiServiceProvider extends Service
{
    public function register(): void
    {


        $this->commands([
            command\Compile::class,
            command\Doctor::class,
            command\Stats::class,
            command\Evaluate::class,
        ]);
    }

    public function boot(): void
    {

        if (class_exists(ExtPoint::class) && class_exists(ExtPoints::class)) {
            ExtPoint::register(ExtPoints::MENU_ITEM, 'rag.wiki', MenuProvider::class);

            $this->registerPermissionProvider();
        }

        $this->registerInvalidationListener();

        $this->registerAgentTools();

        $this->registerMcpProviders();

        RouteRegistrar::register();
    }

    /**
     * 注册 wiki.* 工具到 wise-agent
     *
     * 与 wise-rag 相同的时机约束：wise-agent 在自身 register() 阶段就实例化 ToolRegistry
     * 并触发一次性 AiToolRegister（早于本包 boot），因此只能在这里直接注册。
     */
    protected function registerAgentTools(): void
    {
        if (!(bool) $this->app->config->get('wise-rag.wiki.agent.auto_tool', true)) {
            return;
        }

        if (!\wise\wiki\tool\WikiToolRegistrar::available()) {
            return;
        }

        try {
            $this->app->make(\wise\wiki\tool\WikiToolRegistrar::class)->registerAll();
        } catch (\Throwable $e) {
            error_log('[wise-llm-wiki] 注册 wiki 工具失败: ' . $e->getMessage());
        }
    }

    /**
     * 注册 MCP 能力提供者（wise-mcp 缺失时零副作用）
     */
    protected function registerMcpProviders(): void
    {
        if (!class_exists(ExtPoint::class) || !class_exists(ExtPoints::class)) {
            return;
        }

        if (!interface_exists(\wise\mcp\contract\ToolProviderInterface::class)) {
            return;
        }

        try {
            $points = [
                [
                    'point'    => \wise\mcp\contract\ToolProviderInterface::EXT_POINT,
                    'contract' => \wise\mcp\contract\ToolProviderInterface::class,
                    'class'    => \wise\wiki\mcp\WikiToolProvider::class,
                ],
                [
                    'point'    => \wise\mcp\contract\ResourceProviderInterface::EXT_POINT,
                    'contract' => \wise\mcp\contract\ResourceProviderInterface::class,
                    'class'    => \wise\wiki\mcp\WikiResourceProvider::class,
                ],
            ];

            foreach ($points as $item) {
                if (!ExtPoint::defined($item['point'])) {
                    ExtPoint::definePoint($item['point'], ExtPoint::POLICY_MULTI, $item['contract']);
                }

                ExtPoint::register($item['point'], 'rag.wiki', $item['class']);
            }
        } catch (\Throwable $e) {
            error_log('[wise-llm-wiki] 注册 MCP 能力提供者失败: ' . $e->getMessage());
        }
    }

    /**
     * 注册 wise-rag「文档索引完成」监听
     *
     * 上游类型只出现在 bridge（契约自检 grep 断言），因此经 RagBridge 注册；
     * wise-rag 未安装时 listenDocumentIndexed() 返回 false，零副作用。
     */
    protected function registerInvalidationListener(): void
    {
        try {
            RagBridge::listenDocumentIndexed(static function (object $event): void {
                app(DocumentIndexedListener::class)->handle($event);
            });
        } catch (\Throwable $e) {
            error_log('[wise-llm-wiki] 注册失效监听失败: ' . $e->getMessage());
        }
    }

    /**
     * 注册 AI 工具权限提供者
     *
     * 该扩展点通常由 wise-agent 声明；这里做防御式 definePoint 兜底，
     * 保证与包启动顺序无关（重复定义不抛异常）。
     */
    protected function registerPermissionProvider(): void
    {
        try {
            $point = ExtPoints::AI_TOOL_PERMISSION_PROVIDER;

            if (!ExtPoint::defined($point)) {
                ExtPoint::definePoint($point, ExtPoint::POLICY_MULTI);
            }

            ExtPoint::register($point, 'rag.wiki', WikiPermissionProvider::class);
        } catch (\Throwable $e) {
            error_log('[wise-llm-wiki] 注册 AI 工具权限提供者失败: ' . $e->getMessage());
        }
    }
}
