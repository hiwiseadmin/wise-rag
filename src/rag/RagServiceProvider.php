<?php
declare(strict_types=1);

namespace wise\rag;

use think\Service;
use wise\rag\cache\EmbeddingCacheFactory;
use wise\rag\client\EmbeddingFactory;
use wise\rag\keyword\KeywordDriverFactory;
use wise\rag\listener\PluginUninstallCleanup;
use wise\rag\mcp\RagResourceProvider;
use wise\rag\mcp\RagToolProvider;
use wise\rag\parser\ParserRegistry;
use wise\rag\rerank\RerankerFactory;
use wise\rag\security\KnowledgePermissionProvider;
use wise\rag\source\SourceFactory;
use wise\rag\splitter\SplitterFactory;
use wise\rag\tool\KnowledgeToolRegistrar;
use wise\rag\vector\VectorFactory;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\traits\RegistersMigrations;
use wise\support\traits\ServiceTrait;

/**
 * wise-rag 服务提供者
 *
 * 通过 composer.json 的 extra.think.services 自动注册。
 *
 * 本 Provider 位于 `src/rag/`，命名空间 `wise\rag\`；Wiki 知识编译（原 wise-llm-wiki 包）
 * 由 `wise\wiki\WikiServiceProvider`（`src/wiki/`）作为**独立 service** 注册，
 * 与本 Provider 平级，互不委托（与 `wise-auth` / `wise-login` 的组织方式一致）。
 *
 * 职责：
 *  1. 合并配置（宿主 config/wise-rag.php 优先，含 `wiki` 段）
 *  3. 注册迁移目录（database/migrations）
 *  4. 声明后台菜单（ExtPoints::MENU_ITEM）
 *  5. 注册 AI 工具权限提供者（AI_TOOL_PERMISSION_PROVIDER）
 *  6. 向 wise-agent 注册知识库检索工具（wise-agent 缺失时零副作用）
 *  7. 注册 Web 路由（配置驱动）
 *
 * 说明：不引入任何自定义 `kb.*` 扩展点常量（决策 #7）——驱动切换由「配置 + 工厂」承担。
 */
class RagServiceProvider extends Service
{
    use ServiceTrait;
    use RegistersMigrations;

    /**
     * 包根目录覆盖：本 Provider 位于 `src/rag/`（比默认约定深一层），
     * `dirname(__FILE__, 2)` 会得到 `src/`，而迁移在包根 `database/migrations`。
     */
    protected function migrationPackageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 包根目录覆盖：配置同样在包根 `config/`，而非 `src/config/`。
     */
    protected function getPackageConfigFilePath(string $configName): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $configName . '.php';
    }

    public function register(): void
    {
        $this->mergeConfig('wise-rag');

        $this->bindRagServices();

        $this->commands([
            command\Discover::class,
            command\Index::class,
            command\Stats::class,
            command\Prune::class,
            command\Evaluate::class,
        ]);
    }

    /**
     * 服务类（KnowledgeBaseService / IngestionService / RetrievalService）
     * 构造参数均为上述类型，交由容器自动装配。
     */
    protected function bindRagServices(): void
    {
        $this->app->bind(
            ParserRegistry::class,
            fn () => new ParserRegistry((array) $this->app->config->get('wise-rag.parser.mapping', []))
        );

        $this->app->bind(
            SplitterFactory::class,
            fn () => new SplitterFactory((string) $this->app->config->get('wise-rag.chunk.splitter', 'structure'))
        );

        $this->app->bind(
            EmbeddingCacheFactory::class,
            fn () => new EmbeddingCacheFactory((array) $this->app->config->get('wise-rag.cache', []))
        );

        $this->app->bind(
            EmbeddingFactory::class,
            fn () => new EmbeddingFactory(
                (array) $this->app->config->get('wise-rag.embedding', []),
                new EmbeddingCacheFactory((array) $this->app->config->get('wise-rag.cache', []))
            )
        );

        $this->app->bind(
            VectorFactory::class,
            fn () => new VectorFactory((array) $this->app->config->get('wise-rag.vector', []))
        );

        $this->app->bind(
            SourceFactory::class,
            fn () => new SourceFactory((array) $this->app->config->get('wise-rag.sources', []))
        );

        $this->app->bind(
            KeywordDriverFactory::class,
            fn () => new KeywordDriverFactory((array) $this->app->config->get('wise-rag.keyword', []))
        );

        $this->app->bind(
            RerankerFactory::class,
            fn () => new RerankerFactory((array) $this->app->config->get('wise-rag.rerank', []))
        );
    }

    public function boot(): void
    {
        $this->registerMigrations();

        if (class_exists(ExtPoint::class) && class_exists(ExtPoints::class)) {
            ExtPoint::register(ExtPoints::MENU_ITEM, 'wise-rag', MenuProvider::class);

            $this->registerPermissionProvider();
        }

        $this->registerKnowledgeTools();

        $this->registerPluginLifecycle();

        $this->registerMcpProviders();


        RouteRegistrar::register();
    }

    /**
     * 注册 MCP 能力提供者（wise-mcp 缺失时零副作用）
     *
     * 经 `ToolProviderInterface::EXT_POINT` / `ResourceProviderInterface::EXT_POINT` 两个 wise-mcp 扩展点注册，
     * 由 wise-mcp 的 CapabilityRegistry 聚合，供外部 MCP 客户端（Claude Desktop 等）消费。
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
                    'class'    => RagToolProvider::class,
                ],
                [
                    'point'    => \wise\mcp\contract\ResourceProviderInterface::EXT_POINT,
                    'contract' => \wise\mcp\contract\ResourceProviderInterface::class,
                    'class'    => RagResourceProvider::class,
                ],
            ];

            foreach ($points as $item) {
                if (!ExtPoint::defined($item['point'])) {
                    ExtPoint::definePoint($item['point'], ExtPoint::POLICY_MULTI, $item['contract']);
                }

                ExtPoint::register($item['point'], 'wise-rag', $item['class']);
            }
        } catch (\Throwable $e) {
            error_log('[wise-rag] 注册 MCP 能力提供者失败: ' . $e->getMessage());
        }
    }

    /**
     * 监听插件卸载事件，清理该插件声明的知识源
     *
     * 事件由 wise-plugin 抛出（`PluginUninstall`，携带 $event->pluginIdent）。
     */
    protected function registerPluginLifecycle(): void
    {
        if (!class_exists(\think\facade\Event::class)) {
            return;
        }

        try {
            $cleanup = new PluginUninstallCleanup();
            \think\facade\Event::listen('PluginUninstall', [$cleanup, 'handle']);
        } catch (\Throwable $e) {
            error_log('[wise-rag] 注册插件卸载清理失败: ' . $e->getMessage());
        }
    }

    /**
     * 注册 AI 工具权限提供者（ExtPoints::AI_TOOL_PERMISSION_PROVIDER）
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

            ExtPoint::register($point, 'wise-rag', KnowledgePermissionProvider::class);
        } catch (\Throwable $e) {
            error_log('[wise-rag] 注册 AI 工具权限提供者失败: ' . $e->getMessage());
        }
    }

    /**
     * 向 wise-agent 的 ToolRegistry 注册检索工具（kb.search / kb.{key}.search）
     *
     * 必须在 boot() 直接注册：wise-agent 在自身 register() 阶段即实例化 ToolRegistry
     * 并触发一次性的 AiToolRegister 事件（早于本包 boot），事件方式收不到。
     * 知识库清单经 agent.tool_cache_ttl 缓存，避免每请求查库。
     */
    protected function registerKnowledgeTools(): void
    {
        if (!(bool) $this->app->config->get('wise-rag.agent.auto_tool', true)) {
            return;
        }

        if (!KnowledgeToolRegistrar::available()) {
            return;
        }

        try {
            $this->app->make(KnowledgeToolRegistrar::class)->registerAll();
        } catch (\Throwable $e) {
            error_log('[wise-rag] 注册知识库检索工具失败: ' . $e->getMessage());
        }
    }
}
