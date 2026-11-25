<?php
declare(strict_types=1);

namespace wise\rag\parser;

use wise\rag\contract\DocumentParserInterface;
use wise\rag\dto\ParsedDocument;
use wise\rag\exception\RagException;

/**
 * PDF 解析器
 *
 * 依赖可选包 `smalot/pdfparser`（composer suggest）。未安装时抛出可读异常，
 * 不影响其它格式；接入方按需安装即可。
 */
class PdfParser implements DocumentParserInterface
{
    public const EXTENSIONS = ['pdf'];

    public const MIMES = ['application/pdf'];

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

        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new RagException('PDF 解析需要依赖 smalot/pdfparser，请执行：composer require smalot/pdfparser');
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($filePath);
            $text   = (string) $pdf->getText();
            $pages  = count($pdf->getPages());
        } catch (\Throwable $e) {
            throw new RagException('PDF 解析失败：' . $e->getMessage(), 0, $e);
        }

        return new ParsedDocument(
            trim($text),
            (string) pathinfo($filePath, PATHINFO_FILENAME),
            ['format' => 'pdf', 'path' => $filePath],
            $pages
        );
    }

    public function getName(): string
    {
        return 'pdf';
    }
}
