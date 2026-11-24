<?php
declare(strict_types=1);

use wise\rag\dto\RetrievalQuery;
use wise\rag\dto\ScoredChunk;
use wise\rag\exception\RagException;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;

/**
 * wise-rag 全局辅助函数
 *
 * 经 composer.json 的 autoload.files 自动加载，可在控制器 / 命令行 / 定时任务中直接调用。
 */

if (!function_exists('wise_rag_kb')) {
    /**
     * 知识库服务
     */
    function wise_rag_kb(): KnowledgeBaseService
    {
        return app(KnowledgeBaseService::class);
    }
}

if (!function_exists('wise_rag_ingest_service')) {
    /**
     * 摄取服务
     */
    function wise_rag_ingest_service(): IngestionService
    {
        return app(IngestionService::class);
    }
}

if (!function_exists('wise_rag_retrieval_service')) {
    /**
     * 检索服务
     */
    function wise_rag_retrieval_service(): RetrievalService
    {
        return app(RetrievalService::class);
    }
}

if (!function_exists('wise_rag_create')) {
    /**
     * 创建知识库，返回知识库 ID
     *
     * @param array<string, mixed> $options name / description / owner_type / owner_id /
     *                                      visibility / embedding / vector_store / chunk_config / retrieval_config
     */
    function wise_rag_create(string $kbKey, array $options = []): int
    {
        $kb = wise_rag_kb()->create(['kb_key' => $kbKey] + $options);

        return (int) $kb->id;
    }
}

if (!function_exists('wise_rag_ingest')) {
    /**
     * 直接灌入纯文本，返回文档 ID
     *
     * @param array<string, mixed> $meta external_id / title / url / owner_type / owner_id / source_id
     */
    function wise_rag_ingest(int $kbId, string $text, array $meta = []): int
    {
        $kb = wise_rag_kb()->get($kbId);
        if ($kb === null) {
            throw new RagException("知识库不存在：{$kbId}");
        }

        return wise_rag_ingest_service()->ingestText($kb, $text, $meta);
    }
}

if (!function_exists('wise_rag_search')) {
    /**
     * 检索，返回带引用溯源的数组
     *
     * @param string[] $kbKeys 限定知识库（空 = 全部启用中的知识库）
     * @return array<int, array<string, mixed>>
     */
    function wise_rag_search(string $query, array $kbKeys = [], int $topK = 5): array
    {
        $kbIds = [];
        if ($kbKeys !== []) {
            foreach ($kbKeys as $key) {
                $kb = wise_rag_kb()->findByKey((string) $key);
                if ($kb !== null) {
                    $kbIds[] = (int) $kb->id;
                }
            }
        }

        $hits = wise_rag_retrieval_service()->retrieve(new RetrievalQuery($query, null, $kbIds, $topK));

        return array_map(static fn (ScoredChunk $h): array => $h->toArray(), $hits);
    }
}

if (!function_exists('wise_rag_delete_document')) {
    /**
     * 删除文档及其分块
     */
    function wise_rag_delete_document(int $docId): bool
    {
        return wise_rag_ingest_service()->deleteDocument($docId);
    }
}
