<?php
declare(strict_types=1);

namespace wise\rag\dto;

/**
 * 向量写入点（VectorStoreInterface::upsert 输入）
 */
final class VectorPoint
{
    /**
     * @param string  $chunkId 分块 ID（唯一键）
     * @param float[] $vector  向量
     * @param array   $payload 元数据（kb_id / doc_id / owner_type / owner_id ...）
     */
    public function __construct(
        public string $chunkId,
        public array $vector,
        public array $payload = []
    ) {
    }
}
