<?php
declare(strict_types=1);

namespace wise\wiki\compile;

use wise\wiki\contract\PromptProfileInterface;
use wise\wiki\dto\Evidence;
use wise\wiki\model\WikiSpace;

/**
 * Prompt 组装：按页面类型（kind）分流模板
 *
 * 版本纪律：模板改动必须同时升 `compile.prompt_version` 并更新评测基线，
 * 否则「这版内容是哪套 prompt 生成的」将无法回溯（revisions 里记录的就是版本号）。
 */
class PromptBuilder implements PromptProfileInterface
{
    /** 模板缺失时的兜底（保证模板文件丢失不影响编译可用性） */
    private const FALLBACK = <<<'MD'
你是 WiseAdmin 知识库的编辑，负责把一个主题编译成一页可维护的 wiki。

硬约束：
1. 只能使用【证据】中的事实；证据不足时写「资料未覆盖」，禁止编造。
2. 每个事实性段落末尾必须标注引用编号，如 [1] 或 [1][3]；编号只能来自【证据】。
3. 正文不超过 {max_page_chars} 字符，用 Markdown，结构：一句话结论 → 关键事实 → 细节 → 相关页面。
4. links 只能引用【现有页面】中出现的 slug，不得编造。
5. 只输出严格 JSON，不要包裹解释文字、不要用 ``` 围栏。

输出 JSON：
{"title":"","slug":"","kind":"{kind}","summary":"","markdown":"","citations":[1],"links":[{"slug":"","rel":"related"}],"confidence":0.8}
MD;

    public function __construct(protected string $templateDir = '')
    {
        $this->templateDir = $templateDir !== ''
            ? $templateDir
            : __DIR__ . DIRECTORY_SEPARATOR . 'template';
    }

    /**
     * @param Evidence[]                      $evidence
     * @param array<int, array<string,mixed>> $existing
     * @return array<int, array{role:string, content:string}>
     */
    public function build(WikiSpace $space, string $topic, string $kind, array $evidence, array $existing = []): array
    {
        $maxChars = max(500, (int) config('wise-rag.wiki.compile.max_page_chars', 20000));

        $system = str_replace(
            ['{max_page_chars}', '{kind}', '{topic}', '{space}'],
            [(string) $maxChars, $kind, $topic, (string) $space->name],
            $this->template($kind)
        );

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $this->renderContext($topic, $evidence, $existing)],
        ];
    }

    /**
     * 读取 kind 对应模板（缺失回落 topic 模板，再回落内置兜底）
     */
    public function template(string $kind): string
    {
        $kind = preg_match('/^[a-z]+$/', $kind) === 1 ? $kind : 'topic';

        foreach ([$kind, 'topic'] as $name) {
            $file = $this->templateDir . DIRECTORY_SEPARATOR . $name . '.md';
            if (is_file($file)) {
                $content = (string) file_get_contents($file);
                if (trim($content) !== '') {
                    return $content;
                }
            }
        }

        return self::FALLBACK;
    }

    /**
     * 上下文渲染：现有页面目录 + 带编号的证据
     *
     * @param Evidence[]                      $evidence
     * @param array<int, array<string,mixed>> $existing
     */
    public function renderContext(string $topic, array $evidence, array $existing = []): string
    {
        $lines = ['【主题】' . $topic, ''];

        $lines[] = '【现有页面】（仅用于避免重复建页；slug 必须复用已有值或新建）';
        if ($existing === []) {
            $lines[] = '（暂无）';
        } else {
            foreach ($existing as $row) {
                $lines[] = sprintf(
                    '- %s | %s | %s',
                    (string) ($row['slug'] ?? ''),
                    (string) ($row['title'] ?? ''),
                    (string) ($row['status'] ?? '')
                );
            }
        }

        $lines[] = '';
        $lines[] = '【证据】（引用编号 [n]）';
        if ($evidence === []) {
            $lines[] = '（无可用证据）';
        } else {
            foreach ($evidence as $item) {
                $meta = trim(implode(' · ', array_filter([
                    $item->title,
                    $item->heading,
                    $item->url,
                ], static fn (string $s): bool => $s !== '')));

                $lines[] = sprintf('[%d] %s', $item->no, $meta !== '' ? $meta : '来源文档');
                $lines[] = $item->content;
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
