<?php
declare(strict_types=1);

namespace wise\rag\splitter;

use wise\rag\contract\TextSplitterInterface;
use wise\rag\dto\Chunk;
use wise\rag\dto\ParsedDocument;

/**
 * 结构化分块（默认）
 *
 * 以 Markdown 标题（# ~ ######）为小节边界切分，小节超长时再用 FixedSplitter 细分；
 * 每块记录 `meta.heading`（标题路径，如「第 2 章 > 参数」）用于引用溯源。
 * 无标题的文档自动退化为固定窗口分块。
 */
class StructureSplitter implements TextSplitterInterface
{
    public function split(ParsedDocument $doc, array $options = []): array
    {
        $size     = max(1, (int) ($options['size'] ?? 500));
        $minChars = max(0, (int) ($options['min_chars'] ?? 20));

        $text  = trim(str_replace(["\r\n", "\r"], "\n", $doc->text));
        if ($text === '') {
            return [];
        }
        $lines = explode("\n", $text);

        $sections   = [];
        $stack      = [];
        $buffer     = [];
        $sawHeading = false;

        $flush = function () use (&$sections, &$buffer, &$stack): void {
            $body = trim(implode("\n", $buffer));
            $buffer = [];
            if ($body === '') {
                return;
            }
            $heading = implode(' > ', array_filter($stack, static fn ($v): bool => $v !== ''));
            $sections[] = ['heading' => $heading, 'body' => $body];
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*$/u', $line, $m)) {
                $flush();
                $level = strlen($m[1]);
                $stack = array_slice($stack, 0, $level - 1);
                $stack[$level - 1] = trim($m[2]);
                $sawHeading        = true;
                continue;
            }
            $buffer[] = $line;
        }
        $flush();

        if (!$sawHeading || $sections === []) {
            return (new FixedSplitter())->split($doc, $options);
        }

        $chunks = [];
        $index  = 0;
        foreach ($sections as $section) {
            $body    = $section['body'];
            $heading = $section['heading'];

            if (mb_strlen($body) <= $size) {
                if (mb_strlen($body) >= $minChars) {
                    $chunks[] = new Chunk($body, $index++, ['heading' => $heading]);
                }
                continue;
            }

            $sub = (new FixedSplitter())->split(new ParsedDocument($body), $options);
            foreach ($sub as $chunk) {
                $chunks[] = new Chunk($chunk->content, $index++, ['heading' => $heading]);
            }
        }

        return $chunks;
    }

    public function getName(): string
    {
        return 'structure';
    }
}
