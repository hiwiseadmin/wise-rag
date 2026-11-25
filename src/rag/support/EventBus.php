<?php
declare(strict_types=1);

namespace wise\rag\support;

use think\facade\Event;

/**
 * 框架事件派发薄封装
 *
 * 便于脱离框架单测（无 Event facade 时静默跳过），并隔离异常：
 * 事件监听方的失败不应影响摄取 / 检索主流程。
 */
final class EventBus
{
    public static function trigger(object $event): void
    {
        if (!class_exists(Event::class)) {
            return;
        }

        try {
            Event::trigger($event);
        } catch (\Throwable $e) {
            error_log('[wise-rag] 事件派发失败 ' . $event::class . ': ' . $e->getMessage());
        }
    }
}
