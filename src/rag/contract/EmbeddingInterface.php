<?php
declare(strict_types=1);

namespace wise\rag\contract;

/**
 * 向量化（Embedding）契约
 *
 * 由 `wise-rag.embedding.providers.*` 按名注册；`embedding.source` 决定
 * 鉴权 / 端点是否复用 wise-agent.providers。V1 内置 OpenAI 协议兼容客户端。
 *
 * 注意：本契约独立于 wise-agent 的 `AiClientInterface`（chat），互不耦合。
 */
interface EmbeddingInterface
{
    /**
     * 批量向量化
     *
     * @param string[] $texts   待向量化文本
     * @param array    $options 覆盖参数（batch_size / model 等）
     * @return float[][] 与 $texts 等长、元素为浮点数组
     */
    public function embed(array $texts, array $options = []): array;

    /**
     * 向量维度
     */
    public function getDimension(): int;

    /**
     * 实际模型名（用于知识库维度 / 模型一致性校验）
     */
    public function getModel(): string;

    /**
     * Provider 名称（用于配置与调试）
     */
    public function getName(): string;
}
