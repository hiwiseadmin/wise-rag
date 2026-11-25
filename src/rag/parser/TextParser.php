<?php
declare(strict_types=1);

namespace wise\rag\parser;

use wise\rag\contract\DocumentParserInterface;
use wise\rag\dto\ParsedDocument;
use wise\rag\exception\RagException;

/**
 * 纯文本解析器（txt / log / csv 等）
 */
class TextParser implements DocumentParserInterface
{
    public const EXTENSIONS = ['txt', 'text', 'log', 'csv'];

    public const MIMES = ['text/plain', 'text/csv', 'text/x-log', 'application/csv'];

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

        $text = file_get_contents($filePath);
        if ($text === false) {
            throw new RagException("文件读取失败：{$filePath}");
        }

        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

        return new ParsedDocument(
            trim($text),
            (string) pathinfo($filePath, PATHINFO_FILENAME),
            ['format' => 'text', 'path' => $filePath]
        );
    }

    public function getName(): string
    {
        return 'text';
    }
}
