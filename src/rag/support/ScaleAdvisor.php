<?php
declare(strict_types=1);

namespace wise\rag\support;

use think\facade\Db;

/**
 * 规模化建议（暴力向量检索的容量护栏）
 *
 * `database` 向量驱动在 PHP 内做全量余弦，适用规模约 ≤ 5 万分块（见 DatabaseVectorStore 类注释）。
 * 本类统一判定「是否需要提示切换带 ANN 索引的驱动」，供 stats / prune 等命令复用，
 * 避免每个命令各写一份阈值判断。
 */
final class ScaleAdvisor
{
    /** 默认告警阈值（分块总数） */
    public const DEFAULT_THRESHOLD = 50000;

    /** 唯一需要规模提示的驱动（其余为外部 ANN 索引） */
    public const BRUTE_DRIVER = 'database';

    /**
     * 当前配置的告警阈值（0 = 关闭提示）
     */
    public static function threshold(): int
    {
        if (!function_exists('config')) {
            return self::DEFAULT_THRESHOLD;
        }

        return max(0, (int) config('wise-rag.vector.brute_warn_threshold', self::DEFAULT_THRESHOLD));
    }

    /**
     * 当前生效的向量驱动
     */
    public static function driver(): string
    {
        if (!function_exists('config')) {
            return self::BRUTE_DRIVER;
        }

        return (string) config('wise-rag.vector.default', self::BRUTE_DRIVER);
    }

    /**
     * 分块总数
     *
     * 取知识库计数列求和（已由 KnowledgeBaseService::refreshStats 维护），
     * 避免为了打印一行提示而对 ai_kb_chunks 做全表 count。
     */
    public static function totalChunks(): int
    {
        try {
            return (int) Db::name('ai_kb_knowledge_bases')->sum('chunk_count');
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 是否应提示切换驱动（阈值内 / 已关闭 / 已切到 ANN 驱动时均为 false）
     */
    public static function advises(int $chunkCount): bool
    {
        $threshold = self::threshold();

        return $threshold > 0
            && $chunkCount > $threshold
            && self::driver() === self::BRUTE_DRIVER;
    }

    /**
     * 提示文案
     */
    public static function hint(int $chunkCount): string
    {
        return sprintf(
            '分块总数 %d 已超过暴力检索阈值 %d（database 驱动在 PHP 内做全量余弦）。'
            . '建议切换带 ANN 索引的驱动：在 config/wise-rag.php 设置 vector.default'
            . '（pgvector / redis / qdrant），再用 php think wise:rag:index --force 重建索引；'
            . '或将 vector.brute_warn_threshold 调大 / 置 0 关闭本提示。',
            $chunkCount,
            self::threshold()
        );
    }
}
