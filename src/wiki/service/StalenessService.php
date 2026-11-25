<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use wise\wiki\bridge\RagBridge;
use wise\wiki\compile\ShadowPublisher;
use wise\wiki\model\WikiPage;
use wise\wiki\support\WikiCounter;

/**
 * 页面腐烂度（staleness）
 *
 * ```
 * staleness = 100 * (0.5 * 源变更比例 + 0.3 * min(1, 距上次编译天数/90) + 0.2 * 引用文档缺失率)
 * ```
 *
 * 三项权重对应三种「结论过期」的成因：源事实变了、时间久了没人维护、引用的文档被删了。
 *
 * 治理动作＼�：超过 `quality.max_staleness_publish` 的已发布页面**从影子库撤下**并标脏，
 * 下次重编译成功后再重新进入检索 —— **宁可检索不到，也不返回过期结论**。
 */
class StalenessService
{
    /** 距上次编译多少天算「完全陈旧」（公式里的 90 天） */
    public const AGE_FULL_DAYS = 90;

    public function __construct(
        protected RagBridge $rag,
        protected ShadowPublisher $publisher
    ) {
    }

    /**
     * 重算并落库（只写 staleness 列，**不动 update_time** —— 它决定 age，动了会自我漂移）
     *
     * @return array{pages:int, updated:int}
     */
    public function refresh(?int $spaceId = null, int $limit = 2000): array
    {
        $query = Db::name('ai_wiki_pages')->field('id,space_id,status,update_time,staleness');
        if ($spaceId !== null && $spaceId > 0) {
            $query->where('space_id', $spaceId);
        }

        $pages = $query->order('id', 'asc')->limit(max(1, $limit))->select()->toArray();

        if (count($pages) >= $limit) {
            error_log(sprintf(
                '[wise-llm-wiki] staleness.refresh 触及上限 %d 页（space=%s），其余页面本轮未计算，请分批执行',
                $limit,
                $spaceId === null ? 'all' : (string) $spaceId
            ));
        }

        $now     = time();
        $updated = 0;

        foreach ($pages as $page) {
            $deps   = Db::name('ai_wiki_dependencies')
                ->where('page_id', (int) $page['id'])
                ->field('doc_id,doc_hash')
                ->select()
                ->toArray();
            $docIds = array_values(array_unique(array_map(static fn (array $d): int => (int) $d['doc_id'], $deps)));

            $hashes = [];
            if ($docIds !== []) {
                try {
                    $hashes = $this->rag->docHashes($docIds);
                } catch (\Exception $e) {
                    $hashes = [];
                }
            }

            $value = $this->computeValue($deps, $hashes, (string) $page['update_time'], $now);

            if ($value !== (int) $page['staleness']) {
                Db::name('ai_wiki_pages')->where('id', (int) $page['id'])->update(['staleness' => $value]);
                $updated++;
            }
        }

        return ['pages' => count($pages), 'updated' => $updated];
    }

    /**
     * 计算单页腐烂度
     *
     * @param array<int, array{doc_id:mixed, doc_hash:mixed}> $deps
     * @param array<int, string>                              $currentHashes doc_id => 当前内容 hash（缺失 = 文档已删）
     */
    public function computeValue(array $deps, array $currentHashes, string $updateTime, ?int $now = null): int
    {
        if ($deps === []) {
            return 50;
        }

        $byDoc = [];
        foreach ($deps as $dep) {
            $byDoc[(int) $dep['doc_id']][] = (string) $dep['doc_hash'];
        }

        $changed = 0;
        $missing = 0;

        foreach ($byDoc as $docId => $recorded) {
            $current = (string) ($currentHashes[$docId] ?? '');

            if ($current === '') {
                $missing++;
                $changed++;
                continue;
            }

            if (!in_array($current, $recorded, true)) {
                $changed++;
            }
        }

        $total        = max(1, count($byDoc));
        $changeRatio  = $changed / $total;
        $missingRatio = $missing / $total;

        $now      = $now ?? time();
        $updated  = strtotime($updateTime) ?: $now;
        $ageDays  = max(0, ($now - $updated) / 86400);
        $ageRatio = min(1.0, $ageDays / self::AGE_FULL_DAYS);

        return (int) round(100 * (0.5 * $changeRatio + 0.3 * $ageRatio + 0.2 * $missingRatio));
    }

    /**
     * 撤下过期页面（幂等）
     *
     * @return array{withdrawn:int, pages:array<int,int>}
     */
    public function withdrawStale(?int $spaceId = null, ?int $threshold = null): array
    {
        $threshold = $threshold ?? (int) config('wise-rag.wiki.quality.max_staleness_publish', 60);

        $query = Db::name('ai_wiki_pages')
            ->where('status', WikiPage::STATUS_PUBLISHED)
            ->where('staleness', '>', $threshold)
            ->where('shadow_doc_id', '>', 0)
            ->field('id,space_id,slug');

        if ($spaceId !== null && $spaceId > 0) {
            $query->where('space_id', $spaceId);
        }

        $rows      = $query->select()->toArray();
        $withdrawn = 0;
        $pages     = [];
        $spaces    = [];

        foreach ($rows as $row) {
            try {
                $this->publisher->withdraw((int) $row['id']);
                Db::name('ai_wiki_pages')->where('id', (int) $row['id'])->update([
                    'dirty_reason' => WikiPage::DIRTY_QUALITY,
                ]);
                $withdrawn++;
                $pages[]           = (int) $row['id'];
                $spaces[(int) $row['space_id']] = true;
            } catch (\Exception $e) {
                error_log('[wise-llm-wiki] 撤下过期页面失败: ' . $e->getMessage());
            }
        }

        foreach (array_keys($spaces) as $sid) {
            WikiCounter::syncSpace((int) $sid);
        }

        return ['withdrawn' => $withdrawn, 'pages' => $pages];
    }

    /**
     * 腐烂度分布（0-20 / 21-40 / 41-60 / 61-80 / 81-100）
     *
     * @return array{avg:float, max:int, buckets:array<string,int>, over_threshold:int}
     */
    public function distribution(?int $spaceId = null): array
    {
        $query = Db::name('ai_wiki_pages')->field('staleness,status');
        if ($spaceId !== null && $spaceId > 0) {
            $query->where('space_id', $spaceId);
        }

        $rows = $query->select()->toArray();

        $buckets = ['0-20' => 0, '21-40' => 0, '41-60' => 0, '61-80' => 0, '81-100' => 0];
        $sum     = 0;
        $max     = 0;
        $over    = 0;
        $limit   = (int) config('wise-rag.wiki.quality.max_staleness_publish', 60);

        foreach ($rows as $row) {
            $value = max(0, min(100, (int) $row['staleness']));
            $sum  += $value;
            $max   = max($max, $value);

            if ($value <= 20) {
                $buckets['0-20']++;
            } elseif ($value <= 40) {
                $buckets['21-40']++;
            } elseif ($value <= 60) {
                $buckets['41-60']++;
            } elseif ($value <= 80) {
                $buckets['61-80']++;
            } else {
                $buckets['81-100']++;
            }

            if ($value > $limit && (string) $row['status'] === WikiPage::STATUS_PUBLISHED) {
                $over++;
            }
        }

        return [
            'avg'            => $rows === [] ? 0.0 : round($sum / count($rows), 2),
            'max'            => $max,
            'buckets'        => $buckets,
            'over_threshold' => $over,
        ];
    }
}
