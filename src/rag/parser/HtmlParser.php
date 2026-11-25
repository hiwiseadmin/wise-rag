<?php
declare(strict_types=1);

namespace wise\rag\parser;

use wise\rag\contract\DocumentParserInterface;
use wise\rag\dto\ParsedDocument;
use wise\rag\exception\RagException;

/**
 * HTML 解析器（去标签 → 纯文本）
 */
class HtmlParser implements DocumentParserInterface
{
    public const EXTENSIONS = ['html', 'htm'];

    public const MIMES = ['text/html', 'application/xhtml+xml'];

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

        $html = file_get_contents($filePath);
        if ($html === false) {
            throw new RagException("文件读取失败：{$filePath}");
        }

        $title = (string) pathinfo($filePath, PATHINFO_FILENAME);
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/su', ' ', $html) ?? $html;
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr)\b[^>]*>/iu', "\n", $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return new ParsedDocument(
            trim($text),
            $title,
            ['format' => 'html', 'path' => $filePath]
        );
    }

    public function getName(): string
    {
        return 'html';
    }
}
