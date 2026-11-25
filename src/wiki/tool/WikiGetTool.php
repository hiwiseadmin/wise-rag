<?php
declare(strict_types=1);

namespace wise\wiki\tool;

use wise\agent\contract\ToolInterface;
use wise\wiki\exception\WikiException;
use wise\wiki\security\WikiPermissionProvider;
use wise\wiki\service\WikiSearchService;

/**
 * Wiki 读页工具（`wiki.get`，只读）
 *
 * 与 wiki.search 的分工：search 是「找」，get 是「读全文」。
 * LLM 通常先 search 拿到 slug，再 get 读完整正文（页面正文是 Markdown，含 `[n]` 引用标记）。
 */
class WikiGetTool implements ToolInterface
{
    public function __construct(
        protected WikiSearchService $search,
        protected string $toolName = WikiToolRegistrar::TOOL_GET
    ) {
    }

    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        return '按 slug 或 page_id 读取一个 wiki 页面的完整正文（Markdown，含 [n] 引用标记）。'
            . '先用 wiki.search 找到 slug，再用本工具读全文。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => [
                    'type'        => 'string',
                    'description' => '页面 slug（wiki.search 返回的 slug 字段）',
                ],
                'page_id' => [
                    'type'        => 'integer',
                    'description' => '页面 ID（与 slug 二者其一）',
                ],
                'space' => [
                    'type'        => 'string',
                    'description' => '空间 space_key（slug 在多个空间重复时需要）',
                ],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $arguments): string
    {
        $slug   = trim((string) ($arguments['slug'] ?? ''));
        $pageId = (int) ($arguments['page_id'] ?? 0);
        $space  = trim((string) ($arguments['space'] ?? ''));

        if ($slug === '' && $pageId <= 0) {
            return json_encode(['error' => 'slug 与 page_id 至少提供一个'], JSON_UNESCAPED_UNICODE);
        }

        try {
            $page = $this->search->getPage($space !== '' ? $space : null, $slug !== '' ? $slug : null, $pageId);
        } catch (WikiException $e) {
            return json_encode(['error' => mb_substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] wiki.get 执行失败: ' . $e->getMessage());

            return json_encode(['error' => '读取失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode($page, JSON_UNESCAPED_UNICODE);
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
