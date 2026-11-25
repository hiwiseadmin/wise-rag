<?php
declare(strict_types=1);

namespace wise\wiki\tool;

use wise\agent\contract\ToolInterface;
use wise\wiki\dto\CompileInput;
use wise\wiki\exception\WikiException;
use wise\wiki\security\WikiPermissionProvider;
use wise\wiki\service\CompileService;
use wise\wiki\service\SpaceService;

/**
 * Wiki 编译工具（`wiki.compile`，写入 / 执行）
 *
 * 会真实调用 LLM 并消耗预算，因此 `risk=medium` 且**需要人工确认**。
 * 后台的「触发编译」按钮与 `php think wiki:compile` 走同一条管线（CompileService）。
 */
class WikiCompileTool implements ToolInterface
{
    public function __construct(
        protected CompileService $compile,
        protected SpaceService $spaces,
        protected string $toolName = WikiToolRegistrar::TOOL_COMPILE
    ) {
    }

    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        return '把某个主题编译成 wiki 页面（会调用 LLM，消耗 token 预算）：从源知识库取料 → 生成结构化页面 → '
            . '落库为 review/draft。默认不发布，需人工确认。';
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
                'topic' => [
                    'type'        => 'string',
                    'description' => '要编译的主题（越具体越好，如「A100 功率与散热」）',
                ],
                'kind' => [
                    'type'        => 'string',
                    'enum'        => ['topic', 'entity', 'decision', 'faq', 'glossary'],
                    'description' => '页面类型（默认 topic）',
                ],
            ],
            'required'   => ['space', 'topic'],
        ];
    }

    public function execute(array $arguments): string
    {
        $spaceKey = trim((string) ($arguments['space'] ?? ''));
        $topic    = trim((string) ($arguments['topic'] ?? ''));

        if ($spaceKey === '') {
            return json_encode(['error' => 'space 不能为空'], JSON_UNESCAPED_UNICODE);
        }
        if ($topic === '') {
            return json_encode(['error' => 'topic 不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $space = $this->spaces->findByKey($spaceKey);
        if ($space === null) {
            return json_encode(['error' => '空间不存在：' . $spaceKey], JSON_UNESCAPED_UNICODE);
        }

        try {
            $result = $this->compile->compile(new CompileInput(
                (int) $space->id,
                $topic,
                CompileInput::TRIGGER_AGENT,
                0,
                '',
                (string) ($arguments['kind'] ?? 'topic')
            ));
        } catch (WikiException $e) {
            return json_encode(['error' => mb_substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] wiki.compile 执行失败: ' . $e->getMessage());

            return json_encode(['error' => '编译失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
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
        return 'execute';
    }

    public function getRiskLevel(): string
    {
        return 'medium';
    }
}
