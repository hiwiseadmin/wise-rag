<?php
declare(strict_types=1);

namespace wise\rag\dto;

/**
 * 待索引条目（KnowledgeSourceInterface 输出）
 *
 * 二选一提供内容：$text（直接文本）或 $filePath（交由解析器处理）。
 */
final class DocEntry
{
    /**
     * @param string      $externalId 源侧业务 ID（幂等键）
     * @param string      $title      标题
     * @param string|null $text       直接文本内容
     * @param string|null $filePath   待解析文件路径
     * @param array       $meta       元数据（url / owner / mime 等）
     */
    public function __construct(
        public string $externalId,
        public string $title = '',
        public ?string $text = null,
        public ?string $filePath = null,
        public array $meta = []
    ) {
    }

    public function isFile(): bool
    {
        return $this->text === null && $this->filePath !== null;
    }
}
