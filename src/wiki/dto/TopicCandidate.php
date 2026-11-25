<?php
declare(strict_types=1);

namespace wise\wiki\dto;

/**
 * 待编译主题候选（选题阶段的输出）
 */
final class TopicCandidate
{
    public function __construct(
        public string $topic,
        public string $reason = '',
        public int $hits = 0,
        public string $kind = 'topic'
    ) {
    }
}
