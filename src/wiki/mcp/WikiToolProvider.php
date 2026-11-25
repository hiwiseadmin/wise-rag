<?php
declare(strict_types=1);

namespace wise\wiki\mcp;

use wise\mcp\contract\ToolProviderInterface;
use wise\mcp\dto\ToolDefinition;
use wise\wiki\security\WikiPermissionProvider;
use wise\wiki\service\WikiSearchService;

/**
 * MCP 工具提供者：把 wiki 检索以 MCP Tool 暴露给外部客户端（Claude Desktop 等）
 *
 * 只暴露**只读**工具：写入类（wiki.upsert / wiki.compile）不进 MCP ——
 * 外部客户端没有后台的审核链路，放开写入等于绕过发布闸门。
 */
class WikiToolProvider implements ToolProviderInterface
{
    public function __construct(protected WikiSearchService $search)
    {
    }

    public function getNamespace(): string
    {
        return 'wiki';
    }

    /**
     * @return ToolDefinition[]
     */
    public function listTools(): array
    {
        if (!(bool) $this->cfg('wise-rag.wiki.mcp.expose_tools', true)) {
            return [];
        }

        return [$this->searchTool(), $this->getTool()];
    }

    protected function searchTool(): ToolDefinition
    {
        return new ToolDefinition(
            'wiki.search',
            '检索 wiki 页面（编译后的结构化结论，只读），返回页面摘要、版本、新鲜度与后台链接。',
            [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => '自然语言问题或关键词',
                    ],
                    'space' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => '限定空间 space_key 列表（可选）',
                    ],
                    'top_k' => [
                        'type'        => 'integer',
                        'description' => '返回条数（默认 5，上限 20）',
                    ],
                ],
                'required'   => ['query'],
            ],
            function (array $arguments): array {
                $query = trim((string) ($arguments['query'] ?? ''));
                if ($query === '') {
                    return ToolDefinition::error('query 不能为空');
                }

                $topK = min(20, max(1, (int) ($arguments['top_k'] ?? 5)));

                try {
                    $result = $this->search->search($query, (array) ($arguments['space'] ?? []), $topK);
                } catch (\Exception $e) {
                    error_log('[wise-llm-wiki] MCP wiki.search 失败: ' . $e->getMessage());

                    return ToolDefinition::error('检索失败');
                }

                return $result;
            },
            [
                'title'                 => 'Wiki 检索',
                'permission'            => WikiPermissionProvider::PERMISSION_READ,
                'requires_confirmation' => false,
            ]
        );
    }

    protected function getTool(): ToolDefinition
    {
        return new ToolDefinition(
            'wiki.get',
            '按 slug 或 page_id 读取 wiki 页面全文（Markdown，含 [n] 引用标记）。',
            [
                'type'       => 'object',
                'properties' => [
                    'slug' => [
                        'type'        => 'string',
                        'description' => '页面 slug',
                    ],
                    'page_id' => [
                        'type'        => 'integer',
                        'description' => '页面 ID（与 slug 二者其一）',
                    ],
                    'space' => [
                        'type'        => 'string',
                        'description' => '空间 space_key（slug 重复时需要）',
                    ],
                ],
                'required'   => [],
            ],
            function (array $arguments): array {
                $slug   = trim((string) ($arguments['slug'] ?? ''));
                $pageId = (int) ($arguments['page_id'] ?? 0);

                if ($slug === '' && $pageId <= 0) {
                    return ToolDefinition::error('slug 与 page_id 至少提供一个');
                }

                try {
                    return $this->search->getPage(
                        trim((string) ($arguments['space'] ?? '')) ?: null,
                        $slug !== '' ? $slug : null,
                        $pageId
                    );
                } catch (\Exception $e) {
                    error_log('[wise-llm-wiki] MCP wiki.get 失败: ' . $e->getMessage());

                    return ToolDefinition::error('页面不存在或读取失败');
                }
            },
            [
                'title'                 => 'Wiki 读页',
                'permission'            => WikiPermissionProvider::PERMISSION_READ,
                'requires_confirmation' => false,
            ]
        );
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return function_exists('config') ? config($key, $default) : $default;
    }
}
