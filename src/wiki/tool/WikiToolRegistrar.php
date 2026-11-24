<?php
declare(strict_types=1);

namespace wise\wiki\tool;

use wise\agent\contract\ToolInterface;
use wise\agent\tool\ToolRegistry;
use wise\wiki\service\CompileService;
use wise\wiki\service\PageService;
use wise\wiki\service\SpaceService;
use wise\wiki\service\WikiSearchService;

/**
 * 向 wise-agent 注册 wiki.* 工具
 *
 * ── 命名约束（与 wise-rag 同一结论）──────────────────────────────────
 * `ToolRegistry::register()` 在非 `builtin` 命名空间下会把键前缀为 `{namespace}.`，
 * 而执行时按 LLM 返回的函数名直接 `get($name)` 查键。因此工具必须注册于**默认命名空间**
 * 且 `getName()` 返回全名，保证 `getName() === 注册键`，否则会命中 `Tool not found`。
 *
 * ── 注册时机 ───────────────────────────────────────────────────────
 * wise-agent 在自身 `register()` 阶段就实例化 ToolRegistry 并触发一次性的 AiToolRegister，
 * 早于本包 boot，故事件方式收不到；本包在 ServiceProvider::boot() 直接注册（与 wise-rag 一致）。
 */
class WikiToolRegistrar
{
    public const TOOL_SEARCH  = 'wiki.search';
    public const TOOL_GET     = 'wiki.get';
    public const TOOL_UPSERT  = 'wiki.upsert';
    public const TOOL_COMPILE = 'wiki.compile';

    public function __construct(
        protected WikiSearchService $search,
        protected PageService $pages,
        protected SpaceService $spaces,
        protected CompileService $compile
    ) {
    }

    public static function available(): bool
    {
        return class_exists(ToolRegistry::class) && interface_exists(ToolInterface::class);
    }

    /**
     * @param object|null $registry ToolRegistry 实例（省略则用单例）
     * @return int 注册的工具数量
     */
    public function registerAll(?object $registry = null): int
    {
        if (!self::available()) {
            return 0;
        }

        $registry ??= ToolRegistry::instance();

        $tools = [
            new WikiSearchTool($this->search),
            new WikiGetTool($this->search),
            new WikiUpsertTool($this->pages, $this->spaces),
            new WikiCompileTool($this->compile, $this->spaces),
        ];

        $count = 0;
        foreach ($tools as $tool) {
            $name = $tool->getName();

            $registry->unregister($name);
            $registry->register($tool);
            $count++;
        }

        return $count;
    }
}
