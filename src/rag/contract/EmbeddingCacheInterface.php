<?php
declare(strict_types=1);

namespace wise\rag\contract;

/**
 * 向量缓存契约（语义缓存）
 *
 * 以「文本 hash + 模型」为键缓存向量，避免重复 embedding（省成本）。
 */
interface EmbeddingCacheInterface
{
    /**
     * 读取缓存向量
     *
     * @return float[]|null 命中返回向量，未命中返回 null
     */
    public function get(string $hash, string $model): ?array;

    /**
     * 写入缓存向量
     *
     * @param float[] $vector
     */
    public function put(string $hash, string $model, array $vector): void;

    /**
     * 驱动名称（用于配置与调试）
     */
    public function getName(): string;
}
