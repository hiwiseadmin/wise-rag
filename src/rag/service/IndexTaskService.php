<?php
declare(strict_types=1);

namespace wise\rag\service;

use think\facade\Queue;
use wise\rag\exception\RagException;
use wise\rag\job\IngestionJob;

/**
 * 索引任务编排：同步执行 / 入队异步
 *
 * 命令、后台与定时任务统一经此触发，避免各处重复判断 async 开关。
 */
class IndexTaskService
{
    public function __construct(protected IngestionService $ingestion)
    {
    }

    /**
     * 触发索引
     *
     * @param int|null  $kbId     知识库 ID（与 $sourceId 至少给一个）
     * @param int|null  $sourceId 知识源 ID（优先）
     * @param bool      $async    是否入队（think-queue 不可用时自动降级为同步）
     * @param bool      $force    忽略增量水位，全量重建
     * @return array{async:bool, result?:mixed}
     * @throws RagException
     */
    public function run(?int $kbId, ?int $sourceId = null, bool $async = false, bool $force = false): array
    {
        $data = [];
        if ($sourceId !== null && $sourceId > 0) {
            $data['source_id'] = $sourceId;
        } elseif ($kbId !== null && $kbId > 0) {
            $data['kb_id'] = $kbId;
        } else {
            throw new RagException('必须指定 kbId 或 sourceId');
        }
        $data['force'] = $force;

        if ($async && class_exists(Queue::class)) {
            try {
                Queue::push(
                    IngestionJob::class,
                    $data,
                    (string) $this->cfg('wise-rag.ingestion.queue', 'default'),
                    (string) $this->cfg('wise-rag.ingestion.connection', 'database')
                );

                return ['async' => true];
            } catch (\Exception $e) {
                error_log('[wise-rag] 入队失败，降级为同步执行: ' . $e->getMessage());
            }
        }

        $result = isset($data['source_id'])
            ? $this->ingestion->ingestSource((int) $data['source_id'], $force)
            : $this->ingestion->ingestKnowledgeBase((int) $data['kb_id'], $force);

        return ['async' => false, 'result' => $result];
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return function_exists('config') ? config($key, $default) : $default;
    }
}
