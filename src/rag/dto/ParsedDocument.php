<?php
declare(strict_types=1);

namespace wise\rag\dto;

/**
 * 解析后的文档（DocumentParserInterface 输出）
 */
final class ParsedDocument
{
    /**
     * @param string $text  规范化纯文本
     * @param string $title 标题
     * @param array  $meta  元数据（来源 / 页码 / 作者等）
     * @param int    $pages 页数（不适用时为 0）
     */
    public function __construct(
        public string $text,
        public string $title = '',
        public array $meta = [],
        public int $pages = 0
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['text'] ?? ''),
            (string) ($data['title'] ?? ''),
            (array) ($data['meta'] ?? []),
            (int) ($data['pages'] ?? 0)
        );
    }

    public function toArray(): array
    {
        return [
            'text'  => $this->text,
            'title' => $this->title,
            'meta'  => $this->meta,
            'pages' => $this->pages,
        ];
    }
}
