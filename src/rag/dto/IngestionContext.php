<?php
declare(strict_types=1);

namespace wise\rag\dto;

/**
 * 摄取上下文（KnowledgeSourceInterface 输入）
 */
final class IngestionContext
{
    /**
     * @param int    $kbId      知识库 ID
     * @param string $sourceKey 知识源 key
     * @param mixed  $since     增量水位（上次同步点）
     * @param int    $batchSize 批量大小
     * @param array  $options   扩展选项
     */
    public function __construct(
        public int $kbId,
        public string $sourceKey = '',
        public mixed $since = null,
        public int $batchSize = 500,
        public array $options = []
    ) {
    }
}
