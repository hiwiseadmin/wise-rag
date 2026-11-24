<?php
declare(strict_types=1);

namespace wise\rag\source;

use wise\rag\contract\KnowledgeSourceInterface;
use wise\rag\dto\IngestionContext;

/**
 * 手工知识源
 *
 * 内容由人工上传 / 直接灌入（不自动拉取），仅作为知识库的占位来源，
 * 便于在后台看到「该知识库支持人工维护」。
 */
class ManualSource implements KnowledgeSourceInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected string $sourceKey,
        protected array $config = [],
        protected mixed $checkpoint = null
    ) {
    }

    public function getKey(): string
    {
        return $this->sourceKey;
    }

    /**
     * 手工源无自动拉取内容
     *
     * @return iterable<int, never>
     */
    public function fetch(IngestionContext $ctx): iterable
    {
        return [];
    }

    public function getTitle(): string
    {
        return (string) ($this->config['title'] ?? $this->sourceKey);
    }

    public function getCheckpoint(): mixed
    {
        return $this->checkpoint;
    }
}
