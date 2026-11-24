<?php
declare(strict_types=1);

namespace wise\rag\event;

/**
 * 索引失败事件
 */
final class IndexFailed
{
    public function __construct(
        public int $kbId,
        public string $externalId,
        public string $stage,
        public string $error
    ) {
    }
}
