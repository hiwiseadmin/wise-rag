<?php
declare(strict_types=1);

namespace wise\wiki\support;

use think\facade\Db;

/**
 * 派生计数单点写入（仿 wise-rag 的 KbCounter）
 *
 * `ai_wiki_spaces.page_count` / `dirty_count` 是派生数据，只允许经本类修改，
 * 禁止在控制器 / 命令里直接 UPDATE —— 否则计数必然长期漂移。
 */
final class WikiCounter
{
    public static function syncSpace(int $spaceId): void
    {
        if ($spaceId <= 0) {
            return;
        }

        try {
            $pageCount  = (int) Db::name('ai_wiki_pages')
                ->where('space_id', $spaceId)
                ->where('status', '<>', 'archived')
                ->count();

            $dirtyCount = (int) Db::name('ai_wiki_pages')
                ->where('space_id', $spaceId)
                ->where('dirty_reason', '<>', '')
                ->count();

            Db::name('ai_wiki_spaces')
                ->where('id', $spaceId)
                ->update([
                    'page_count'  => $pageCount,
                    'dirty_count' => $dirtyCount,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] 刷新空间计数失败: ' . $e->getMessage());
        }
    }
}
