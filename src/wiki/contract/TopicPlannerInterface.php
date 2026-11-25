<?php
declare(strict_types=1);

namespace wise\wiki\contract;

use wise\wiki\dto\TopicCandidate;
use wise\wiki\model\WikiSpace;

/**
 * 选题契约
 *
 * 实现方决定「本轮该编译哪些主题」，默认实现见 {@see \wise\wiki\compile\TopicPlanner}。
 * 插件可用 mergeConfig 替换实现（不改本包源码）。
 */
interface TopicPlannerInterface
{
    /**
     * @return TopicCandidate[]
     */
    public function plan(WikiSpace $space, int $limit = 20): array;
}
