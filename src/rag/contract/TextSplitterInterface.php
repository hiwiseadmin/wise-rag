<?php
declare(strict_types=1);

namespace wise\rag\contract;

use wise\rag\dto\Chunk;
use wise\rag\dto\ParsedDocument;

/**
 * 文本分块契约
 *
 * 把解析后的文档切分为可向量化的块。V1 内置 structure / fixed / recursive 三种，
 * 由配置 `wise-rag.chunk.splitter` 选择（纯内部策略，不引入扩展点）。
 */
interface TextSplitterInterface
{
    /**
     * 分块
     *
     * @param ParsedDocument $doc     解析结果
     * @param array          $options 分块参数（size / overlap / min_chars 等）
     * @return Chunk[]
     */
    public function split(ParsedDocument $doc, array $options = []): array;

    /**
     * 驱动名称（用于配置与调试）
     */
    public function getName(): string;
}
