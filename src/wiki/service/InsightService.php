<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use wise\wiki\model\WikiPage;

/**
 * 观测报表（`wiki:stats`）
 *
 * 回答三个运维日常问题：
 *   1. 编译在正常工作吗？（任务成功率 / 最近一次编译）
 *   2. 花了多少钱？（token / 成本 / 预算占比）
 *   3. 页面还新鲜吗？（staleness 分布 / 超阈值页面数）
 */
class InsightService
{
    public function __construct(protected StalenessService $staleness)
    {
    }

    /**
     * 全局概览
     *
     * @return array<string, mixed>
     */
    public function overview(?int $spaceId = null): array
    {
        $pageQuery = Db::name('ai_wiki_pages');
        $jobQuery  = Db::name('ai_wiki_compile_jobs');
        $spaceQuery = Db::name('ai_wiki_spaces');

        if ($spaceId !== null && $spaceId > 0) {
            $pageQuery->where('space_id', $spaceId);
            $jobQuery->where('space_id', $spaceId);
            $spaceQuery->where('id', $spaceId);
        }

        $pages = [
            'total'     => (int) (clone $pageQuery)->count(),
            'published' => (int) (clone $pageQuery)->where('status', WikiPage::STATUS_PUBLISHED)->count(),
            'review'    => (int) (clone $pageQuery)->where('status', WikiPage::STATUS_REVIEW)->count(),
            'draft'     => (int) (clone $pageQuery)->where('status', WikiPage::STATUS_DRAFT)->count(),
            'archived'  => (int) (clone $pageQuery)->where('status', WikiPage::STATUS_ARCHIVED)->count(),
            'dirty'     => (int) (clone $pageQuery)->where('dirty_reason', '<>', '')->count(),
            'unpublished_shadow' => (int) (clone $pageQuery)
                ->where('status', WikiPage::STATUS_PUBLISHED)
                ->where('shadow_doc_id', 0)
                ->count(),
        ];

        $jobs = [
            'total'   => (int) (clone $jobQuery)->count(),
            'ok'      => (int) (clone $jobQuery)->where('status', 'ok')->count(),
            'failed'  => (int) (clone $jobQuery)->where('status', 'failed')->count(),
            'skipped' => (int) (clone $jobQuery)->where('status', 'skipped')->count(),
            'last_at' => (string) ((clone $jobQuery)->max('update_time') ?: ''),
        ];
        $jobs['success_rate'] = $jobs['total'] > 0
            ? round($jobs['ok'] / $jobs['total'], 4)
            : 0.0;

        $spaces = [
            'total'  => (int) (clone $spaceQuery)->count(),
            'active' => (int) (clone $spaceQuery)->where('status', 'active')->count(),
            'paused' => (int) (clone $spaceQuery)->where('status', 'paused')->count(),
        ];

        $tokensUsed   = (int) (clone $spaceQuery)->sum('tokens_used_month');
        $budgetTotal  = (int) (clone $spaceQuery)->sum('budget_tokens_month');
        $globalBudget = (int) config('wise-rag.wiki.budget.tokens_per_month', 0);

        $tokens = [
            'used'   => $tokensUsed,
            'budget' => $budgetTotal > 0 ? $budgetTotal : $globalBudget,
            'ratio'  => ($budgetTotal > 0 || $globalBudget > 0)
                ? round(min(1, $tokensUsed / max(1, $budgetTotal > 0 ? $budgetTotal : $globalBudget)), 4)
                : 0.0,
            'exhausted' => ($budgetTotal > 0 ? $tokensUsed >= $budgetTotal : ($globalBudget > 0 && $tokensUsed >= $globalBudget)),
        ];

        $cost = round((float) (clone $jobQuery)->sum('cost'), 6);

        return [
            'spaces'    => $spaces,
            'pages'     => $pages,
            'jobs'      => $jobs,
            'tokens'    => $tokens,
            'cost'      => $cost,
            'staleness' => $this->staleness->distribution($spaceId),
            'deps'      => (int) Db::name('ai_wiki_dependencies')->count(),
            'links'     => (int) Db::name('ai_wiki_links')->count(),
        ];
    }

    /**
     * 按空间的成本报表
     *
     * @return array<int, array<string, mixed>>
     */
    public function bySpace(): array
    {
        $spaces = Db::name('ai_wiki_spaces')
            ->field('id,space_key,name,status,tokens_used_month,budget_tokens_month,page_count,dirty_count')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if ($spaces === []) {
            return [];
        }

        $ids = array_map(static fn (array $s): int => (int) $s['id'], $spaces);

        $jobRows = Db::name('ai_wiki_compile_jobs')
            ->whereIn('space_id', $ids)
            ->field("space_id, COUNT(*) AS total, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed, SUM(cost) AS cost, SUM(tokens_in + tokens_out) AS tokens")
            ->group('space_id')
            ->select()
            ->toArray();

        $jobs = [];
        foreach ($jobRows as $row) {
            $jobs[(int) $row['space_id']] = [
                'total'  => (int) $row['total'],
                'failed' => (int) $row['failed'],
                'cost'   => round((float) $row['cost'], 6),
                'tokens' => (int) $row['tokens'],
            ];
        }

        $out = [];
        foreach ($spaces as $space) {
            $id     = (int) $space['id'];
            $budget = (int) $space['budget_tokens_month'];
            $used   = (int) $space['tokens_used_month'];

            $out[] = [
                'id'         => $id,
                'space_key'  => (string) $space['space_key'],
                'name'       => (string) $space['name'],
                'status'     => (string) $space['status'],
                'pages'      => (int) $space['page_count'],
                'dirty'      => (int) $space['dirty_count'],
                'tokens'     => $used,
                'budget'     => $budget,
                'ratio'      => $budget > 0 ? round(min(1, $used / $budget), 4) : 0.0,
                'jobs'       => $jobs[$id]['total'] ?? 0,
                'failed'     => $jobs[$id]['failed'] ?? 0,
                'cost'       => $jobs[$id]['cost'] ?? 0.0,
                'job_tokens' => $jobs[$id]['tokens'] ?? 0,
            ];
        }

        return $out;
    }
}
