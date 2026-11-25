<?php
declare(strict_types=1);

namespace wise\wiki\dto;

/**
 * 一条取料证据（编译输入的最小单元）
 *
 * `no` 是给 LLM 看的引用编号（从 1 开始），页面正文里用 `[n]` 标注；
 * `docHash` 记录引用时的源文档内容 hash —— 它是增量重编译的判定依据（见 ai_wiki_dependencies）。
 */
final class Evidence
{
    public function __construct(
        public int $no,
        public string $content,
        public int $docId,
        public int $chunkId,
        public string $docHash = '',
        public string $title = '',
        public string $url = '',
        public string $heading = '',
        public float $score = 0.0
    ) {
    }

    /**
     * 去重键：同一分块只保留一次
     */
    public function key(): string
    {
        return $this->docId . ':' . $this->chunkId;
    }
}
