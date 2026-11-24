<?php
declare(strict_types=1);

namespace wise\rag\contract;

use wise\rag\dto\RetrievalQuery;
use wise\rag\dto\ScoredChunk;

/**
 * 检索编排契约（核心逻辑，V1 内部实现，不对外开放注册）
 */
interface RetrieverInterface
{
    /**
     * 执行检索
     *
     * @return ScoredChunk[]
     */
    public function retrieve(RetrievalQuery $query): array;

    /**
     * 驱动名称（用于配置与调试）
     */
    public function getName(): string;
}
