<?php
declare(strict_types=1);

namespace wise\rag\splitter;

use wise\rag\contract\TextSplitterInterface;
use wise\rag\dto\Chunk;
use wise\rag\dto\ParsedDocument;

/**
 * 固定窗口分块
 *
 * 按 `size`（字符）切窗、`overlap` 重叠；尽量在句读/换行处断开，避免切断语义。
 */
class FixedSplitter implements TextSplitterInterface
{
    /** 句读边界字符（含中英文） */
    protected const BOUNDARIES = ["\n", '。', '！', '？', '；', '.', '!', '?', ';'];

    public function split(ParsedDocument $doc, array $options = []): array
    {
        $size     = max(1, (int) ($options['size'] ?? 500));
        $overlap  = (int) ($options['overlap'] ?? 80);
        $overlap  = max(0, min($overlap, $size - 1));
        $minChars = max(0, (int) ($options['min_chars'] ?? 20));

        $text = trim(str_replace(["\r\n", "\r"], "\n", $doc->text));
        $len  = mb_strlen($text);
        if ($len === 0) {
            return [];
        }

        $chunks = [];
        $index  = 0;
        $start  = 0;
        $minCut = (int) floor($size * 0.6);

        while ($start < $len) {
            $end   = min($len, $start + $size);
            $piece = mb_substr($text, $start, $end - $start);

            if ($end < $len) {
                $cut = $this->findCut($piece, $minCut);
                if ($cut !== null) {
                    $piece = mb_substr($piece, 0, $cut);
                    $end   = $start + $cut;
                }
            }

            $piece = trim($piece);
            if (mb_strlen($piece) >= $minChars) {
                $chunks[] = new Chunk($piece, $index++);
            }

            if ($end >= $len) {
                break;
            }

            $next  = $end - $overlap;
            $start = $next > $start ? $next : $end;
        }

        return $chunks;
    }

    public function getName(): string
    {
        return 'fixed';
    }

    /**
     * 从窗口尾部向前找最近的句读边界（含标点），返回切点长度
     */
    protected function findCut(string $piece, int $minPos): ?int
    {
        $len = mb_strlen($piece);
        for ($i = $len - 1; $i >= $minPos; $i--) {
            if (in_array(mb_substr($piece, $i, 1), self::BOUNDARIES, true)) {
                return $i + 1;
            }
        }

        return null;
    }
}
