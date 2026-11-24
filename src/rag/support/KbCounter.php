<?php
declare(strict_types=1);

namespace wise\rag\support;

use think\facade\Cache;
use think\facade\Db;

/**
 * 知识库计数同步（派生数据）
 *
 * `ai_kb_knowledge_bases` 的 `doc_count` / `chunk_count` / `vector_count` 都是
 * **派生数据**：凡改动 `ai_kb_documents` / `ai_kb_chunks` 的代码路径都必须调用本类同步，
 * 否则后台列表与统计概览会显示旧值（`overview()` 的 vectors 直接取 `SUM(vector_count)`）。
 *
 * 为什么放在 support 层而不是 KnowledgeBaseService：
 *   向量驱动等基础设施层也需要同步计数，但**不能**依赖领域服务 ——
 *   `KnowledgeBaseService` 的构造依赖 `VectorFactory`，而 `VectorFactory` 负责创建
 *   向量驱动，反向依赖会形成环（容器无法解析）。因此把「无依赖的计数写入」下沉到这里，
 *   由各层共用。
 */
final class KbCounter
{
    /** 统计概览缓存键（唯一来源，KnowledgeBaseService 亦引用此处） */
    public const STATS_CACHE_KEY = 'wise_rag:stats';

    /**
     * 重新计算并写回三个计数列
     *
     * 注意：写入的 `update_time` 会被刷新 —— 计数变化意味着知识库内容发生变化，
     * 这一点与用户编辑不同，但保持与 `KnowledgeBaseService::refreshStats()` 原行为一致。
     */
    public static function sync(int $kbId): void
    {
        if ($kbId <= 0) {
            return;
        }

        Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->update([
            'doc_count'    => (int) Db::name('ai_kb_documents')->where('kb_id', $kbId)->count(),
            'chunk_count'  => (int) Db::name('ai_kb_chunks')->where('kb_id', $kbId)->count(),
            'vector_count' => (int) Db::name('ai_kb_chunks')->where('kb_id', $kbId)->whereNotNull('vector')->count(),
            'update_time'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 同步计数并失效统计概览缓存
     */
    public static function syncAndFlush(int $kbId): void
    {
        self::sync($kbId);
        self::flushStatsCache();
    }

    /**
     * 失效统计概览缓存
     */
    public static function flushStatsCache(): void
    {
        if (!class_exists(Cache::class)) {
            return;
        }

        try {
            Cache::delete(self::STATS_CACHE_KEY);
        } catch (\Exception $e) {
        }
    }
}
