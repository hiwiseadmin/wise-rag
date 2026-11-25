<?php
declare(strict_types=1);

namespace wise\rag\job;

use wise\rag\exception\RagException;
use wise\rag\service\IngestionService;

/**
 * 知识索引异步任务（think-queue）
 *
 * 负载：['source_id' => int] 或 ['kb_id' => int]，可选 'force' => bool。
 * 失败按 `wise-rag.ingestion.max_retry` 重试，超限丢弃并记录。
 */
class IngestionJob
{
    /**
     * 由 think-queue worker 调用
     *
     * 说明：这里以 `object $job` 声明（而非 think\queue\Job），
     * 以便在未安装 think-queue 的环境下本类仍可被加载 / 单测。
     *
     * @param object               $job
     * @param array<string, mixed> $data
     */
    public function fire(object $job, array $data): void
    {
        try {
            $service = app(IngestionService::class);
            $force   = (bool) ($data['force'] ?? false);

            if (!empty($data['source_id'])) {
                $service->ingestSource((int) $data['source_id'], $force);
            } elseif (!empty($data['kb_id'])) {
                $service->ingestKnowledgeBase((int) $data['kb_id'], $force);
            } else {
                throw new RagException('索引任务缺少 source_id / kb_id');
            }

            if (method_exists($job, 'delete')) {
                $job->delete();
            }
        } catch (\Throwable $e) {
            error_log('[wise-rag] 索引任务失败: ' . $e->getMessage());

            $maxRetry = (int) (function_exists('config') ? config('wise-rag.ingestion.max_retry', 3) : 3);
            $attempts = method_exists($job, 'attempts') ? (int) $job->attempts() : 0;

            if ($attempts >= $maxRetry) {
                if (method_exists($job, 'delete')) {
                    $job->delete();
                }
                return;
            }

            if (method_exists($job, 'release')) {
                $job->release(10);
            }
        }
    }
}
