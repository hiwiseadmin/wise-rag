<?php
declare(strict_types=1);

namespace wise\rag\dto;

/**
 * 文本分块（TextSplitterInterface 输出）
 */
final class Chunk
{
    /**
     * @param string $content 块文本
     * @param int    $index   块序号（从 0 起）
     * @param array  $meta    块元数据（heading / page / table 等）
     */
    public function __construct(
        public string $content,
        public int $index = 0,
        public array $meta = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['content'] ?? ''),
            (int) ($data['index'] ?? 0),
            (array) ($data['meta'] ?? [])
        );
    }

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'index'   => $this->index,
            'meta'    => $this->meta,
        ];
    }
}
