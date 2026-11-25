<?php
declare(strict_types=1);

namespace wise\rag\contract;

use wise\rag\dto\ScoredChunk;

/**
 * 关键词检索驱动契约
 *
 * 默认 `mysql_fulltext`（MATCH…AGAINST，中文用 ngram 分词，走全文索引）；
 * 短查询 / 引擎不支持时回退 `like`；亦可切 `redis`。
 * 由 `wise-rag.keyword.driver` 按名选择。
 */
interface KeywordDriverInterface
{
    /**
     * 关键词召回
     *
     * @param string $collection 集合名
     * @param string $query      查询文本
     * @param int    $topK       召回条数
     * @param array  $filter     元数据过滤
     * @return ScoredChunk[]
     */
    public function search(string $collection, string $query, int $topK, array $filter = []): array;

    /**
     * 当前环境是否可用（FULLTEXT 探测 / 版本 / 引擎）
     */
    public function isAvailable(): bool;

    /**
     * 驱动名称（用于配置与调试）
     */
    public function getName(): string;
}
