<?php
declare(strict_types=1);

namespace wise\wiki\dto;

/**
 * 一次编译请求
 *
 * `pageId > 0` 表示重编译已有页面；否则按 `slug` / `topic` 新建。
 */
final class CompileInput
{
    public const TRIGGER_MANUAL      = 'manual';
    public const TRIGGER_SCHEDULE    = 'schedule';
    public const TRIGGER_DOC_CHANGED = 'doc_changed';
    public const TRIGGER_AGENT       = 'agent';

    public function __construct(
        public int $spaceId,
        public string $topic,
        public string $trigger = self::TRIGGER_MANUAL,
        public int $pageId = 0,
        public string $slug = '',
        public string $kind = 'topic'
    ) {
    }
}
