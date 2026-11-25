<?php
declare(strict_types=1);

namespace wise\rag\event;

/**
 * 一次检索完成事件（观测 / 评测 / 审计）
 */
final class RetrievalPerformed
{
    /**
     * @param int[]    $kbIds
     * @param float|null $topScore 最高分（无命中时为 null）
     */
    public function __construct(
        public string $query,
        public array $kbIds,
        public int $hitCount,
        public ?float $topScore,
        public int $latencyMs
    ) {
    }
}
