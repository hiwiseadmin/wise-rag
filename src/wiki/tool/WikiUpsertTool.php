<?php
declare(strict_types=1);

namespace wise\wiki\tool;

use wise\agent\contract\ToolInterface;
use wise\wiki\exception\WikiException;
use wise\wiki\security\WikiPermissionProvider;
use wise\wiki\service\PageService;
use wise\wiki\service\SpaceService;

/**
 * Wiki 写页工具（`wiki.upsert`，写入）
 *
 * **决策 2：Agent 只能写 draft**。哪怕目标页已发布，写入后也会降级为 draft 并从影子库撤下，
 * 必须由人工在后台确认发布 —— 否则「审核」形同虚设＼�。
 *
 * 人工内容保护：目标页 `editor_type=admin` 且已发布时直接拒绝，不覆盖人工修订。
 */
class WikiUpsertTool implements ToolInterface
{
    public function __construct(
        protected PageService $pages,
        protected SpaceService $spaces,
        protected string $toolName = WikiToolRegistrar::TOOL_UPSERT
    ) {
    }

    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        return '新建或更新一个 wiki 页面（Markdown）。写入结果**只能是草稿（draft）**，需要人工在后台确认后才会被检索。'
            . '正文中的事实请用 [n] 标注引用编号。已被人工编辑并发布的页面不允许被覆盖。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'space' => [
                    'type'        => 'string',
                    'description' => '目标空间 space_key',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => '页面标题',
                ],
                'content_md' => [
                    'type'        => 'string',
                    'description' => 'Markdown 正文（含 [n] 引用标记）',
                ],
                'slug' => [
                    'type'        => 'string',
                    'description' => '页面 slug（小写字母/数字/连字符；缺省按标题生成）',
                ],
                'summary' => [
                    'type'        => 'string',
                    'description' => '一句话摘要',
                ],
                'kind' => [
                    'type'        => 'string',
                    'enum'        => ['topic', 'entity', 'decision', 'faq', 'glossary'],
                    'description' => '页面类型（默认 topic）',
                ],
            ],
            'required'   => ['space', 'title', 'content_md'],
        ];
    }

    public function execute(array $arguments): string
    {
        $spaceKey = trim((string) ($arguments['space'] ?? ''));
        if ($spaceKey === '') {
            return json_encode(['error' => 'space 不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $space = $this->spaces->findByKey($spaceKey);
        if ($space === null) {
            return json_encode(['error' => '空间不存在：' . $spaceKey], JSON_UNESCAPED_UNICODE);
        }

        try {
            $result = $this->pages->saveAgent((int) $space->id, [
                'title'      => (string) ($arguments['title'] ?? ''),
                'content_md' => (string) ($arguments['content_md'] ?? ''),
                'summary'    => (string) ($arguments['summary'] ?? ''),
                'slug'       => (string) ($arguments['slug'] ?? ''),
                'kind'       => (string) ($arguments['kind'] ?? 'topic'),
            ]);
        } catch (WikiException $e) {
            return json_encode(['error' => mb_substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] wiki.upsert 执行失败: ' . $e->getMessage());

            return json_encode(['error' => '写入失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode($result + [
            'note' => '已保存为草稿：需管理员在后台「Wiki 知识编译 → 页面」确认发布后才会进入检索。',
            'url'  => '/admin/rag/wiki/page?id=' . (int) $result['page_id'],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function getPermission(): string
    {
        return WikiPermissionProvider::PERMISSION_WRITE;
    }

    public function requireConfirmation(): bool
    {
        return true;
    }

    public function getAccess(): string
    {
        return 'write';
    }

    public function getRiskLevel(): string
    {
        return 'medium';
    }
}
