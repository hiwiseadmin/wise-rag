<?php
declare(strict_types=1);

namespace wise\rag\dto;

/**
 * 带分数的命中分块（检索 / 重排输出）
 */
final class ScoredChunk
{
    /**
     * @param string $chunkId      分块 ID
     * @param string $docId        文档 ID
     * @param string $content      分块文本
     * @param float  $score        融合后的最终分数
     * @param float  $vectorScore  向量分
     * @param float  $keywordScore 关键词分
     * @param array  $meta         元数据（含引用溯源 cite 信息）
     */
    public function __construct(
        public string $chunkId,
        public string $docId,
        public string $content,
        public float $score = 0.0,
        public float $vectorScore = 0.0,
        public float $keywordScore = 0.0,
        public array $meta = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'chunk_id'      => $this->chunkId,
            'doc_id'        => $this->docId,
            'content'       => $this->content,
            'score'         => $this->score,
            'vector_score'  => $this->vectorScore,
            'keyword_score' => $this->keywordScore,
            'meta'          => $this->meta,
        ];
    }
}
