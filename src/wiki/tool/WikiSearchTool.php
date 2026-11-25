<?php
declare(strict_types=1);

namespace wise\wiki\tool;

use wise\agent\contract\ToolInterface;
use wise\wiki\exception\WikiException;
use wise\wiki\security\WikiPermissionProvider;
use wise\wiki\service\WikiSearchService;

/**
 * Wiki 检索工具（`wiki.search`，只读）
 *
 * 默认 `page_first`：优先返回**已编译的页面结论**，命中分不足才退回源文档碎片。
 * 相比 `kb.search`（wise-rag）的差异：kb.search 只给碎片，本工具给「人已经整理过的结论 + 引用数」。
 */
class WikiSearchTool implements ToolInterface
{
    public function __construct(
        protected WikiSearchService $search,
        protected string $toolName = WikiToolRegistrar::TOOL_SEARCH
    ) {
    }

    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        return '检索 wiki 页面（编译后的结构化结论，只读）。当问题涉及内部知识的「结论 / 总结 / 对比 / 流程」时优先使用；'
            . '返回页面摘要、版本、新鲜度与后台链接。需要原文碎片时可用 kb.search。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => '自然语言问题或关键词',
                ],
                'space' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => '限定 wiki 空间 space_key 列表（可选，缺省检索全部启用空间）',
                ],
                'top_k' => [
                    'type'        => 'integer',
                    'description' => '返回条数（默认 5，上限 20）',
                ],
                'strategy' => [
                    'type'        => 'string',
                    'enum'        => ['page_first', 'both_merged'],
                    'description' => 'page_first（默认，先看页面结论）| both_merged（页面 + 源碎片都返回）',
                ],
            ],
            'required'   => ['query'],
        ];
    }

    public function execute(array $arguments): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return json_encode(['error' => 'query 不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $topK = (int) ($arguments['top_k'] ?? 5);
        $topK = min(20, max(1, $topK > 0 ? $topK : 5));

        try {
            $result = $this->search->search(
                $query,
                (array) ($arguments['space'] ?? []),
                $topK,
                isset($arguments['strategy']) ? (string) $arguments['strategy'] : null
            );
        } catch (WikiException $e) {
            return json_encode(['error' => mb_substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] wiki.search 执行失败: ' . $e->getMessage());

            return json_encode(['error' => '检索失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    public function getPermission(): string
    {
        return WikiPermissionProvider::PERMISSION_READ;
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getAccess(): string
    {
        return 'read';
    }

    public function getRiskLevel(): string
    {
        return 'low';
    }
}
