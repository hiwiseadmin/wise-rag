<?php
declare(strict_types=1);

namespace wise\rag\contract;

use wise\rag\dto\DocEntry;
use wise\rag\dto\IngestionContext;

/**
 * 知识源契约
 *
 * 定义「一批原始文档从哪里来」：内置 table / attachment / manual 三种由
 * manifest 的 `ai_schema.knowledge_sources` 声明后自动装配；需要自定义拉取逻辑
 * （如外部 API）的实现可自行提供。
 */
interface KnowledgeSourceInterface
{
    /**
     * 源唯一 key，如 'smartstock:product_docs'
     */
    public function getKey(): string;

    /**
     * 拉取待索引条目
     *
     * @return iterable<DocEntry>
     */
    public function fetch(IngestionContext $ctx): iterable;

    /**
     * 展示名
     */
    public function getTitle(): string;

    /**
     * 增量水位（上次同步点：时间戳 / 游标等）
     */
    public function getCheckpoint(): mixed;
}
