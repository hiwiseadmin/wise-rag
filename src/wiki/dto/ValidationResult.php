<?php
declare(strict_types=1);

namespace wise\wiki\dto;

/**
 * 编译产物校验结果
 *
 * 设计要点：`errors` 非空即失败（重试或落库 error）；`warnings` 只影响状态降级。
 * 引用覆盖率低于阈值不判失败，而是把页面降为 draft（宁可不可见，不可误导）。
 */
final class ValidationResult
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_REVIEW    = 'review';
    public const STATUS_PUBLISHED = 'published';

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    public function __construct(
        public bool $ok,
        public array $errors = [],
        public array $warnings = [],
        public float $citationCoverage = 0.0,
        public string $status = self::STATUS_DRAFT
    ) {
    }

    public function isClean(): bool
    {
        return $this->ok && $this->warnings === [];
    }

    /**
     * @return array{ok:bool, errors:string[], warnings:string[], citation_coverage:float, status:string}
     */
    public function toArray(): array
    {
        return [
            'ok'                => $this->ok,
            'errors'            => $this->errors,
            'warnings'          => $this->warnings,
            'citation_coverage' => round($this->citationCoverage, 4),
            'status'            => $this->status,
        ];
    }
}
