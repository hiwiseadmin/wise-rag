<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use wise\wiki\bridge\RagBridge;
use wise\wiki\compile\ShadowPublisher;
use wise\wiki\model\WikiPage;

/**
 * 运程体检（`wiki:doctor`）
 *
 * 覆盖 9 类不变量破坏 —— 都是「不体检就永远发现不了」的静默故障：
 *  1. 空间源库被删（页面永远不再更新）
 *  2. 已发布但未进影子库（检索不到）
 *  3. 已发布但影子文档被删（prune 误清 / 库重建）
 *  4. 已发布但影子文档与页面不一致（人工改过正文）
 *  5. 孤儿依赖（指向已删除的页面 / 文档）
 *  6. 残留编译锁（进程崩溃后 locked_until 已过期）
 *  7. 超期任务日志
 *  8. 超量版本
 *  9. prompt 版本漂移（改了 prompt 却没重新编译）
 *
 * 默认只报告；`--apply` 才修复（修复动作全部幂等）。
 */
class DoctorService
{
    public function __construct(
        protected RagBridge $rag,
        protected SpaceService $spaces,
        protected ShadowPublisher $publisher,
        protected InvalidationService $invalidation,
        protected RetentionService $retention,
        protected StalenessService $staleness
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(?int $spaceId = null): array
    {
        $now    = date('Y-m-d H:i:s');
        $report = [
            'spaces_missing_source' => [],
            'pages_unpublished'     => [],
            'pages_shadow_missing'  => [],
            'pages_shadow_stale'    => [],
            'orphan_deps'           => [],
            'stale_locks'           => [],
            'expired_jobs'          => 0,
            'excess_revisions'      => 0,
            'prompt_drifts'         => [],
            'stale_published'       => 0,
        ];

        $spaceQuery = Db::name('ai_wiki_spaces')->field('id,space_key,source_kb_id,status');
        if ($spaceId !== null && $spaceId > 0) {
            $spaceQuery->where('id', $spaceId);
        }
        $spaces = $spaceQuery->select()->toArray();

        $kbIds = null;
        try {
            $kbIds = array_flip(array_map(
                static fn (array $kb): int => (int) $kb['id'],
                $this->rag->knowledgeBases(false)
            ));
        } catch (\Exception $e) {
            $kbIds = null;
        }

        foreach ($spaces as $space) {
            $sourceKbId = (int) $space['source_kb_id'];
            $missing    = $sourceKbId <= 0;

            if (!$missing) {
                if ($kbIds !== null) {
                    $missing = !isset($kbIds[$sourceKbId]);
                } else {
                    try {
                        $missing = $this->rag->sourceKb($sourceKbId) === null;
                    } catch (\Exception $e) {
                        $missing = true;
                    }
                }
            }

            if ($missing) {
                $report['spaces_missing_source'][] = [
                    'space_id'     => (int) $space['id'],
                    'space_key'    => (string) $space['space_key'],
                    'source_kb_id' => $sourceKbId,
                ];
            }
        }

        $spaceIds = array_map(static fn (array $s): int => (int) $s['id'], $spaces);

        if ($spaceIds !== []) {
            $pages = Db::name('ai_wiki_pages')
                ->whereIn('space_id', $spaceIds)
                ->where('status', WikiPage::STATUS_PUBLISHED)
                ->field('id,space_id,slug,content_md,shadow_doc_id')
                ->select()
                ->toArray();

            $docIds   = array_map(static fn (array $p): int => (int) $p['shadow_doc_id'], $pages);
            $snapshot = $this->rag->documentsSnapshot($docIds);

            foreach ($pages as $page) {
                $docId = (int) $page['shadow_doc_id'];
                $row   = [
                    'page_id' => (int) $page['id'],
                    'space_id'=> (int) $page['space_id'],
                    'slug'    => (string) $page['slug'],
                ];

                if ($docId <= 0) {
                    $report['pages_unpublished'][] = $row;
                    continue;
                }

                if (!isset($snapshot[$docId])) {
                    $report['pages_shadow_missing'][] = $row + ['shadow_doc_id' => $docId];
                    continue;
                }

                $expectedHash = md5(trim((string) $page['content_md']));

                if ($snapshot[$docId]['content_hash'] !== $expectedHash) {
                    $report['pages_shadow_stale'][] = $row + [
                        'shadow_doc_id' => $docId,
                        'page_chars'    => mb_strlen((string) $page['content_md']),
                        'shadow_chars'  => (int) $snapshot[$docId]['char_count'],
                    ];
                }
            }
        }

        $deps = Db::name('ai_wiki_dependencies')->field('id,page_id,doc_id,chunk_id')->select()->toArray();
        if ($deps !== []) {
            $pageSet = array_flip(array_map('intval', Db::name('ai_wiki_pages')->column('id')));
            $docSet  = $this->rag->documentsSnapshot(array_map(static fn (array $d): int => (int) $d['doc_id'], $deps));

            foreach ($deps as $dep) {
                if (!isset($pageSet[(int) $dep['page_id']]) || !isset($docSet[(int) $dep['doc_id']])) {
                    $report['orphan_deps'][] = (int) $dep['id'];
                }
            }
        }

        $lockQuery = Db::name('ai_wiki_pages')
            ->where('locked_by', '<>', '')
            ->whereNotNull('locked_until')
            ->where('locked_until', '<', $now)
            ->field('id,slug,locked_by,locked_until');
        if ($spaceIds !== []) {
            $lockQuery->whereIn('space_id', $spaceIds);
        }
        $report['stale_locks'] = $lockQuery->select()->toArray();

        $keepDays = (int) config('wise-rag.wiki.retention.keep_jobs_days', 90);
        if ($keepDays > 0) {
            $report['expired_jobs'] = (int) Db::name('ai_wiki_compile_jobs')
                ->where('create_time', '<', date('Y-m-d H:i:s', time() - $keepDays * 86400))
                ->count();
        }

        $report['excess_revisions'] = $this->retention->countExcessRevisions(
            (int) config('wise-rag.wiki.retention.keep_revisions', 20)
        );

        $report['prompt_drifts'] = $this->invalidation->promptDrifts();

        if ($spaceId !== null && $spaceId > 0) {
            $report['prompt_drifts'] = array_values(array_filter(
                $report['prompt_drifts'],
                static fn (array $d): bool => (int) $d['id'] === $spaceId
            ));
        }

        $staleQuery = Db::name('ai_wiki_pages')
            ->where('status', WikiPage::STATUS_PUBLISHED)
            ->where('staleness', '>', (int) config('wise-rag.wiki.quality.max_staleness_publish', 60))
            ->where('shadow_doc_id', '>', 0);
        if ($spaceIds !== []) {
            $staleQuery->whereIn('space_id', $spaceIds);
        }
        $report['stale_published'] = (int) $staleQuery->count();

        return $report;
    }

    /**
     * 执行修复（幂等）
     *
     * @param array<string, mixed> $report inspect() 的结果
     * @return array<string, int>
     */
    public function fix(array $report): array
    {
        $fixed = [
            'spaces_paused'      => 0,
            'shadow_republished' => 0,
            'deps_pruned'        => 0,
            'locks_released'     => 0,
            'jobs_pruned'        => 0,
            'revisions_pruned'   => 0,
            'prompt_reapplied'   => 0,
            'stale_withdrawn'    => 0,
        ];

        foreach ((array) ($report['spaces_missing_source'] ?? []) as $row) {
            $space = $this->spaces->get((int) $row['space_id']);
            if ($space !== null) {
                $this->spaces->pause($space, '源知识库缺失（#' . (int) $row['source_kb_id'] . '）');
                $fixed['spaces_paused']++;
            }
        }

        $broken = array_merge(
            (array) ($report['pages_unpublished'] ?? []),
            (array) ($report['pages_shadow_missing'] ?? []),
            (array) ($report['pages_shadow_stale'] ?? [])
        );
        foreach ($broken as $row) {
            $space = $this->spaces->get((int) $row['space_id']);
            if ($space === null) {
                continue;
            }
            try {
                $result = $this->publisher->publish($space, (int) $row['page_id']);
                if (!empty($result['published'])) {
                    $fixed['shadow_republished']++;
                }
            } catch (\Exception $e) {
                error_log('[wise-llm-wiki] 补发影子文档失败: ' . $e->getMessage());
            }
        }

        $orphan = array_values(array_filter(array_map('intval', (array) ($report['orphan_deps'] ?? []))));
        foreach (array_chunk($orphan, 500) as $chunk) {
            $fixed['deps_pruned'] += (int) Db::name('ai_wiki_dependencies')->whereIn('id', $chunk)->delete();
        }

        $lockIds = array_map(static fn (array $r): int => (int) $r['id'], (array) ($report['stale_locks'] ?? []));
        foreach (array_chunk($lockIds, 500) as $chunk) {
            $fixed['locks_released'] += (int) Db::name('ai_wiki_pages')
                ->whereIn('id', $chunk)
                ->update(['locked_by' => '', 'locked_until' => null]);
        }

        $fixed['jobs_pruned'] = $this->retention->pruneJobs(
            (int) config('wise-rag.wiki.retention.keep_jobs_days', 90)
        );

        $fixed['revisions_pruned'] = $this->retention->pruneRevisions(
            (int) config('wise-rag.wiki.retention.keep_revisions', 20)
        );

        foreach ((array) ($report['prompt_drifts'] ?? []) as $drift) {
            $this->invalidation->byPromptVersion((int) $drift['id'], (string) $drift['to']);
            $fixed['prompt_reapplied']++;
        }

        if ((int) ($report['stale_published'] ?? 0) > 0) {
            $result = $this->staleness->withdrawStale();
            $fixed['stale_withdrawn'] = (int) $result['withdrawn'];
        }

        return $fixed;
    }

    /**
     * 报告是否「一切正常」
     *
     * @param array<string, mixed> $report
     */
    public function isHealthy(array $report): bool
    {
        foreach (['spaces_missing_source', 'pages_unpublished', 'pages_shadow_missing', 'pages_shadow_stale', 'orphan_deps', 'stale_locks', 'prompt_drifts'] as $key) {
            if (!empty($report[$key])) {
                return false;
            }
        }

        return (int) ($report['expired_jobs'] ?? 0) === 0
            && (int) ($report['excess_revisions'] ?? 0) === 0
            && (int) ($report['stale_published'] ?? 0) === 0;
    }
}
