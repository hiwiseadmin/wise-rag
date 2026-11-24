<?php
declare(strict_types=1);

namespace wise\wiki\dto;

/**
 * LLM 编译产物（已通过结构解析，尚未通过 OutputValidator）
 */
final class CompileOutput
{
    /**
     * @param int[]                          $citations 正文实际引用的 evidence 编号
     * @param array<int, array{slug:string, rel:string}> $links 关联页面（slug 由 PageWriter 解析成 id）
     */
    public function __construct(
        public string $title,
        public string $slug,
        public string $kind,
        public string $summary,
        public string $markdown,
        public array $citations = [],
        public array $links = [],
        public float $confidence = 0.0,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public string $model = '',
        public string $promptVersion = ''
    ) {
    }

    public function chars(): int
    {
        return mb_strlen(trim($this->markdown));
    }
}
