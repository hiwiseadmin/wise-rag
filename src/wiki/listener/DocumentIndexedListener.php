<?php
declare(strict_types=1);

namespace wise\wiki\listener;

use wise\wiki\service\InvalidationService;

/**
 * 源文档索引完成 → 标记受影响页面（wise-rag `DocumentIndexed` 事件）
 *
 * 只做四件事，全部毫秒级：定位空间 → 查依赖 → 比对 doc_hash → 标脏 + 入队。
 * **绝不在这里调 LLM**：事件在摄取链路内触发，跑重活会拖垮上传/重建索引请求。
 */
class DocumentIndexedListener
{
    public function __construct(protected InvalidationService $invalidation)
    {
    }

    public function handle(object $event): void
    {
        try {
            $kbId  = (int) ($event->kbId ?? 0);
            $docId = (int) ($event->docId ?? 0);

            if ($kbId <= 0 || $docId <= 0) {
                return;
            }

            $result = $this->invalidation->byDocument($kbId, $docId);

            if ($result['dirty'] > 0) {
                error_log(sprintf(
                    '[wise-llm-wiki] 源文档 #%d 变化：标脏 %d 页（未变 %d，入队 %d）',
                    $docId,
                    $result['dirty'],
                    $result['unchanged'],
                    $result['queued']
                ));
            }
        } catch (\Throwable $e) {
            error_log('[wise-llm-wiki] 失效监听失败: ' . $e->getMessage());
        }
    }
}
