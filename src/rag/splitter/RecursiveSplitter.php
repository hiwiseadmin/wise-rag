<?php
declare(strict_types=1);

namespace wise\rag\splitter;

use wise\rag\contract\TextSplitterInterface;
use wise\rag\dto\Chunk;
use wise\rag\dto\ParsedDocument;

/**
 * 递归分块
 *
 * 先按段落（空行）拆分，再贪心合并至 `size`；单段超长时交给 FixedSplitter 按句读细分。
 * 与 FixedSplitter 的区别：优先保持段落完整，文本保真（不丢标点）。
 */
class RecursiveSplitter implements TextSplitterInterface
{
    public function split(ParsedDocument $doc, array $options = []): array
    {
        $size     = max(1, (int) ($options['size'] ?? 500));
        $overlap  = max(0, min((int) ($options['overlap'] ?? 80), $size - 1));
        $minChars = max(0, (int) ($options['min_chars'] ?? 20));

        $text = trim(str_replace(["\r\n", "\r"], "\n", $doc->text));
        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [];

        $chunks = [];
        $index  = 0;
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) > $size) {
                $this->push($chunks, $index, $buffer, $minChars);
                $buffer = '';

                $sub = (new FixedSplitter())->split(
                    new ParsedDocument($paragraph),
                    ['size' => $size, 'overlap' => $overlap, 'min_chars' => $minChars]
                );
                foreach ($sub as $chunk) {
                    $chunks[] = new Chunk($chunk->content, $index++);
                }
                continue;
            }

            if ($buffer === '') {
                $buffer = $paragraph;
                continue;
            }

            if (mb_strlen($buffer) + mb_strlen($paragraph) + 2 <= $size) {
                $buffer .= "\n\n" . $paragraph;
                continue;
            }

            $this->push($chunks, $index, $buffer, $minChars);
            $tail   = $overlap > 0 ? mb_substr($buffer, -$overlap) : '';
            $buffer = $tail !== '' ? $tail . "\n\n" . $paragraph : $paragraph;
        }

        $this->push($chunks, $index, $buffer, $minChars);

        return $chunks;
    }

    public function getName(): string
    {
        return 'recursive';
    }

    protected function push(array &$chunks, int &$index, string $text, int $minChars): void
    {
        $text = trim($text);
        if ($text !== '' && mb_strlen($text) >= $minChars) {
            $chunks[] = new Chunk($text, $index++);
        }
    }
}
