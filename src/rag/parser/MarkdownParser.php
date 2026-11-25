<?php
declare(strict_types=1);

namespace wise\rag\parser;

use wise\rag\contract\DocumentParserInterface;
use wise\rag\dto\ParsedDocument;
use wise\rag\exception\RagException;

/**
 * Markdown 解析器
 *
 * 保留标题行（供 StructureSplitter 结构化分块），仅做轻度清洗：
 * 去图片/链接语法、去强调标记，保留正文与列表。
 */
class MarkdownParser implements DocumentParserInterface
{
    public const EXTENSIONS = ['md', 'markdown', 'mdown', 'mkd'];

    public const MIMES = ['text/markdown', 'text/x-markdown'];

    public function supports(string $mime, string $ext): bool
    {
        return in_array(strtolower($mime), self::MIMES, true)
            || in_array(strtolower($ext), self::EXTENSIONS, true);
    }

    public function parse(string $filePath, array $options = []): ParsedDocument
    {
        if (!is_file($filePath)) {
            throw new RagException("文件不存在：{$filePath}");
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new RagException("文件读取失败：{$filePath}");
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $title = (string) pathinfo($filePath, PATHINFO_FILENAME);
        if (preg_match('/^#\s+(.+?)\s*$/mu', $raw, $m)) {
            $title = trim($m[1]);
        }

        $text = $raw;
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/(\*\*|__)(.+?)\1/u', '$2', $text) ?? $text;
        $text = preg_replace('/(\*|_)(.+?)\1/u', '$2', $text) ?? $text;
        $text = str_replace('`', '', $text);

        return new ParsedDocument(
            trim($text),
            $title,
            ['format' => 'markdown', 'path' => $filePath]
        );
    }

    public function getName(): string
    {
        return 'markdown';
    }
}
