<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use wise\wiki\model\WikiPage;

/**
 * 保留策略（版本与任务日志）
 *
 * 规则：
 *  - 版本：每页保留最近 `retention.keep_revisions` 个，**人工版本（editor_type=admin）永久保留**；
 *  - 任务日志：超过 `retention.keep_jobs_days` 天的记录清除。
 *
 * 实现按页分批（默认 200 页/批）而非一条大 SQL：语义清晰、可随时中断，
 * 且避免相关子查询在 MySQL 上退化为全表扫描。
 */
class RetentionService
{
    /**
     * 清理超期任务日志
     */
    public function pruneJobs(int $keepDays): int
    {
        if ($keepDays <= 0) {
            return 0;
        }

        $before = date('Y-m-d H:i:s', time() - $keepDays * 86400);

        return (int) Db::name('ai_wiki_compile_jobs')
            ->where('create_time', '<', $before)
            ->delete();
    }

    /**
     * 试算：超出保留数的版本条数（不删除）
     */
    public function countExcessRevisions(int $keep, int $pageBatch = 200): int
    {
        return count($this->excessRevisionIds($keep, $pageBatch));
    }

    /**
     * 清理超出保留数的版本（人工版本永久保留）
     */
    public function pruneRevisions(int $keep, int $pageBatch = 200): int
    {
        $ids = $this->excessRevisionIds($keep, $pageBatch);
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += (int) Db::name('ai_wiki_page_revisions')->whereIn('id', $chunk)->delete();
        }

        return $deleted;
    }

    /**
     * @return int[]
     */
    protected function excessRevisionIds(int $keep, int $pageBatch): array
    {
        $keep = max(1, $keep);

        $pageIds = Db::name('ai_wiki_pages')->order('id', 'asc')->limit(max(1, $pageBatch))->column('id');
        if ($pageIds === []) {
            return [];
        }

        if (count($pageIds) >= $pageBatch) {
            error_log(sprintf(
                '[wise-llm-wiki] 版本清理触及上限 %d 页，其余页面本轮未清理，请分批执行',
                $pageBatch
            ));
        }

        $ids = [];
        foreach (array_chunk(array_map('intval', $pageIds), 200) as $chunk) {
            $rows = Db::name('ai_wiki_page_revisions')
                ->whereIn('page_id', $chunk)
                ->order('page_id', 'asc')
                ->order('rev_no', 'desc')
                ->field('id,page_id,editor_type')
                ->select()
                ->toArray();

            $counter = [];
            foreach ($rows as $row) {
                $pageId = (int) $row['page_id'];
                $index  = $counter[$pageId] ?? 0;
                $counter[$pageId] = $index + 1;

                if ($index < $keep) {
                    continue;
                }

                if ((string) $row['editor_type'] === 'admin') {
                    continue;
                }

                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * 清理归档页面的残留链接（页面删除已级联处理；这里兜住历史脏数据）
     */
    public function pruneOrphanLinks(): int
    {
        $pageIds = array_map('intval', Db::name('ai_wiki_pages')->column('id'));

        if ($pageIds === []) {
            return 0;
        }

        $from = (int) Db::name('ai_wiki_links')->whereNotIn('from_page_id', $pageIds)->delete();
        $to   = (int) Db::name('ai_wiki_links')->whereNotIn('to_page_id', $pageIds)->delete();

        return $from + $to;
    }

    /**
     * 清理非 published 页面的影子文档（例如人工改回 draft 后残留的索引）
     */
    public function withdrawUnpublished(): int
    {
        $rows = Db::name('ai_wiki_pages')
            ->where('shadow_doc_id', '>', 0)
            ->where('status', '<>', WikiPage::STATUS_PUBLISHED)
            ->field('id,shadow_doc_id')
            ->select()
            ->toArray();

        $count = 0;
        foreach ($rows as $row) {
            try {
                app(\wise\wiki\compile\ShadowPublisher::class)->withdraw((int) $row['id']);
                $count++;
            } catch (\Exception $e) {
                error_log('[wise-llm-wiki] 撤回非发布页影子文档失败: ' . $e->getMessage());
            }
        }

        return $count;
    }
}
