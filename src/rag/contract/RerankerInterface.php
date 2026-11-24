<?php
declare(strict_types=1);

namespace wise\rag\contract;

use wise\rag\dto\ScoredChunk;

/**
 * 重排契约
 *
 * 对召回候选做精排（如 BGE-reranker / Cohere / LLM rerank）。
 * 由 `wise-rag.retrieval.rerank.provider` 按名选择。
 */
interface RerankerInterface
{
    /**
     * 重排
     *
     * @param string        $query      原始查询
     * @param ScoredChunk[] $candidates 候选（含向量 / 关键词分）
     * @param int           $topN       返回条数
     * @return ScoredChunk[] 重排后的结果
     */
    public function rerank(string $query, array $candidates, int $topN): array;

    /**
     * 驱动名称（用于配置与调试）
     */
    public function getName(): string;
}
