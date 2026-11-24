<?php
declare(strict_types=1);

namespace wise\rag\dto;

/**
 * 检索请求（RetrieverInterface 输入）
 */
final class RetrievalQuery
{
    /**
     * @param string     $text   查询文本
     * @param float[]|null $vector 预计算向量（为空则内部计算）
     * @param int[]      $kbIds  限定知识库；为空表示全部可见知识库
     * @param int        $topK   召回条数
     * @param array      $filter 元数据过滤（owner / source_key ...）
     * @param bool       $hybrid 是否混合检索（向量 + 关键词）
     */
    public function __construct(
        public string $text,
        public ?array $vector = null,
        public array $kbIds = [],
        public int $topK = 8,
        public array $filter = [],
        public bool $hybrid = true
    ) {
    }
}
