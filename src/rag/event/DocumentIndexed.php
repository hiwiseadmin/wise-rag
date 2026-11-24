<?php
declare(strict_types=1);

namespace wise\rag\event;

/**
 * 文档索引完成事件
 */
final class DocumentIndexed
{
    public function __construct(
        public int $kbId,
        public int $docId,
        public string $externalId,
        public int $chunkCount
    ) {
    }
}
