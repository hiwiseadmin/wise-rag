<?php
declare(strict_types=1);

namespace wise\rag\contract;

use wise\rag\dto\ScoredChunk;
use wise\rag\dto\VectorPoint;

/**
 * 向量存储契约
 *
 * 由 `wise-rag.vector.stores.*` 按名注册，默认使用 `database`（PHP 暴力余弦，
 * 零外部依赖）。规模增大后可切换 pgvector / redis / milvus / qdrant 等。
 */
interface VectorStoreInterface
{
    /**
     * 批量写入 / 覆盖（以 chunkId 为唯一键）
     *
     * @param string        $collection 集合名（= ai_kb_knowledge_bases.collection）
     * @param VectorPoint[] $points     向量点
     * @return int 写入条数
     */
    public function upsert(string $collection, array $points): int;

    /**
     * 按 chunkId 批量删除
     *
     * @param string[] $chunkIds
     * @return int 删除条数
     */
    public function delete(string $collection, array $chunkIds): int;

    /**
     * 按元数据过滤批量删除（如按 kb_id / doc_id / owner）
     *
     * @param array $filter 元数据等值过滤
     * @return int 删除条数
     */
    public function deleteByMeta(string $collection, array $filter): int;

    /**
     * 向量检索
     *
     * 实现约定：本接口的写入类方法（upsert / delete / deleteByMeta / dropCollection）
     * 会改动 `ai_kb_chunks`，而 `ai_kb_knowledge_bases` 的计数列是派生数据 ——
     * 调用方负责在**批量操作结束后**调用 `\wise\rag\support\KbCounter::sync()`（或
     * `KnowledgeBaseService::refreshStats()`）同步计数。
     *
     * 例外：`deleteByMeta()` 无内部调用方、面向外部直接使用，故其实现内部自同步计数，
     * 避免调用方在不知情的情况下让后台统计虚高。
     *
     * @param float[] $vector query 向量
     * @param int     $topK   召回条数
     * @param array   $filter 元数据过滤（kb_id / owner_type / owner_id ...）
     * @return ScoredChunk[] 按 score 降序
     */
    public function search(string $collection, array $vector, int $topK, array $filter = []): array;

    /**
     * 清空集合（重建索引用）
     */
    public function dropCollection(string $collection): void;

    /**
     * 驱动能力声明
     *
     * @return array{type?:string, hybrid?:bool, max_dimension?:int, ...}
     */
    public function capabilities(): array;

    /**
     * 驱动名称（用于配置与调试）
     */
    public function getName(): string;
}
