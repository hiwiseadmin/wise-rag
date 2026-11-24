<?php
declare(strict_types=1);

namespace wise\rag\tool;

use think\facade\Cache;
use wise\agent\tool\ToolRegistry;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;

/**
 * 知识库检索工具注册器
 *
 * 为每个启用的知识库注册 `kb.{kb_key}.search`，并注册全库工具 `kb.search`。
 *
 * ── 命名与命名空间（关键约束）──────────────────────────────────────────
 * wise-agent 的 `ToolRegistry` 以 `ToolInterface::getName()` 作为暴露给 LLM 的函数名，
 * 而执行时按 LLM 返回的函数名直接 `get($name)` 查注册键；`register()` 在非
 * `builtin` 命名空间下会把键前缀为 `{namespace}.`。因此只有当工具注册于
 * **默认命名空间**、且 `getName()` 返回全名时，`getName() === 注册键` 才成立
 * （否则 LLM 调用会命中 `Tool not found`）。故此处不使用自定义命名空间。
 *
 * ── 注册时机 ───────────────────────────────────────────────────────────
 * wise-agent 在自身 `register()` 阶段即 `ToolRegistry::instance()` 并触发一次性的
 * `AiToolRegister` 事件，此时 wise-rag 尚未 boot，故事件方式不可用；
 * 本包在 `RagServiceProvider::boot()` 直接注册，并用缓存（agent.tool_cache_ttl）
 * 缓存知识库清单，避免每请求查库。
 */
class KnowledgeToolRegistrar
{
    /** 全库检索工具名 */
    public const GLOBAL_TOOL = 'kb.search';

    /** 知识库清单缓存键 */
    protected const CACHE_KEY = 'wise_rag:kb_tools';

    public function __construct(
        protected KnowledgeBaseService $kbService,
        protected RetrievalService $retrieval
    ) {
    }

    /**
     * wise-agent 是否可用
     */
    public static function available(): bool
    {
        return class_exists(ToolRegistry::class)
            && interface_exists(\wise\agent\contract\ToolInterface::class);
    }

    /**
     * 注册全部检索工具
     *
     * @param object|null $registry ToolRegistry 实例（省略则使用单例）
     * @return int 注册的工具数量
     */
    public function registerAll(?object $registry = null): int
    {
        if (!self::available()) {
            return 0;
        }

        $registry ??= ToolRegistry::instance();
        $options = $this->options();
        $bases   = $this->bases();

        if ($bases === [] && empty($options['global_tool'])) {
            return 0;
        }

        $count = 0;

        if (!empty($options['global_tool']) && $bases !== []) {
            $name = self::GLOBAL_TOOL;
            $registry->unregister($name);
            $registry->register(new KnowledgeSearchTool(
                $name,
                '检索全部已启用的知识库（RAG）。当问题涉及内部文档、手册、FAQ、产品资料时优先使用，返回带引用溯源的片段。',
                $this->retrieval,
                array_map(static fn (array $b): int => (int) $b['id'], $bases),
                $options
            ));
            $count++;
        }

        foreach ($bases as $base) {
            $name = self::toolName((string) $base['kb_key']);
            $registry->unregister($name);
            $registry->register(new KnowledgeSearchTool(
                $name,
                sprintf('检索知识库「%s」（%s），返回带引用溯源的片段。', (string) $base['name'], (string) $base['kb_key']),
                $this->retrieval,
                [(int) $base['id']],
                $options
            ));
            $count++;
        }

        return $count;
    }

    /**
     * 失效知识库清单缓存
     *
     * 知识库增删后调用，使检索工具列表即时刷新（不必等 TTL 到期）。
     */
    public static function flushToolCache(): void
    {
        if (!class_exists(Cache::class)) {
            return;
        }

        try {
            Cache::delete(self::CACHE_KEY);
        } catch (\Exception $e) {
        }
    }

    /**
     * 知识库专属工具名（已做函数名安全字符处理）
     */
    public static function toolName(string $kbKey): string
    {
        return 'kb.' . self::sanitize($kbKey) . '.search';
    }

    /**
     * LLM 函数名仅允许 [A-Za-z0-9_-]
     */
    protected static function sanitize(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $name) ?? '';
        $safe = trim($safe, '_-');

        return mb_substr($safe === '' ? 'default' : $safe, 0, 40);
    }

    /**
     * 知识库清单（带缓存）
     *
     * @return array<int, array{id:int, kb_key:string, name:string}>
     */
    protected function bases(): array
    {
        $ttl = (int) ($this->options()['tool_cache_ttl'] ?? 60);

        if ($ttl > 0 && $this->cacheAvailable()) {
            try {
                $cached = Cache::get(self::CACHE_KEY);
                if (is_array($cached)) {
                    return $cached;
                }
            } catch (\Exception $e) {
            }
        }

        $rows = [];
        foreach ($this->kbService->all(true) as $kb) {
            $rows[] = [
                'id'     => (int) $kb->id,
                'kb_key' => (string) $kb->kb_key,
                'name'   => (string) $kb->name,
            ];
        }

        if ($ttl > 0 && $this->cacheAvailable()) {
            try {
                Cache::set(self::CACHE_KEY, $rows, $ttl);
            } catch (\Exception $e) {
            }
        }

        return $rows;
    }

    protected function cacheAvailable(): bool
    {
        return class_exists(Cache::class) && function_exists('config');
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return function_exists('config') ? (array) config('wise-rag.agent', []) : [];
    }
}
