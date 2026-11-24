<?php
declare(strict_types=1);

namespace wise\wiki\compile;

use think\facade\Db;
use wise\wiki\contract\PageWriterInterface;
use wise\wiki\dto\CompileOutput;
use wise\wiki\dto\Evidence;
use wise\wiki\dto\ValidationResult;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;
use wise\wiki\support\WikiCounter;

/**
 * 页面落库（单事务）
 *
 * 三件事必须同时成立，缺一即数据错乱：
 *  1. 页面正文与版本号在**同一事务**里递增（否则 rev_no 与 revisions 对不上）；
 *  2. 依赖表按页重建（否则增量重编译会命中已删除的引用）；
 *  3. 有人工编辑痕迹且已发布时，**只写候选版本，不动正文**８先）。
 *
 * 发布到影子库不在这里（ShadowPublisher），因为 embedding 是外部调用，不能占用事务。
 */
class PageWriter implements PageWriterInterface
{
    private const REL_TYPES = ['related', 'depends', 'contradicts', 'part_of'];

    /**
     * @param Evidence[]           $evidence
     * @param array<string, mixed> $meta status / change_reason / prompt_version / model / tokens_in / tokens_out / quality_score
     * @return array{page_id:int, rev_no:int, status:string, deferred:bool, candidate_rev:int}
     */
    public function write(WikiSpace $space, CompileOutput $output, array $evidence, array $meta = []): array
    {
        $spaceId     = (int) $space->id;
        $sourceKbId  = (int) $space->source_kb_id;
        $status      = (string) ($meta['status'] ?? ValidationResult::STATUS_DRAFT);
        $fingerprint = $this->fingerprint($evidence);
        $now         = date('Y-m-d H:i:s');

        return Db::transaction(function () use ($space, $spaceId, $sourceKbId, $output, $evidence, $meta, $status, $fingerprint, $now): array {
            $existing = Db::name('ai_wiki_pages')
                ->where('space_id', $spaceId)
                ->where('slug', $output->slug)
                ->find();

            if (is_array($existing)
                && (string) $existing['editor_type'] === 'admin'
                && (string) $existing['status'] === WikiPage::STATUS_PUBLISHED
            ) {
                $candidateRev = (int) $existing['rev_no'] + 1;

                $this->upsertRevision((int) $existing['id'], $candidateRev, $output, $meta, $now, 'rebuild_from_sources');

                Db::name('ai_wiki_pages')->where('id', (int) $existing['id'])->update([
                    'source_fingerprint' => $fingerprint,
                    'dirty_reason'       => '',
                ]);

                return [
                    'page_id'       => (int) $existing['id'],
                    'rev_no'        => (int) $existing['rev_no'],
                    'status'        => (string) $existing['status'],
                    'deferred'      => true,
                    'candidate_rev' => $candidateRev,
                ];
            }

            $outline = $this->extractOutline($output->markdown);

            $payload = [
                'space_id'           => $spaceId,
                'slug'               => $output->slug,
                'title'              => $output->title,
                'kind'               => $output->kind,
                'status'             => $status,
                'content_md'         => $output->markdown,
                'summary'            => $output->summary,
                'outline'            => json_encode($outline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_fingerprint' => $fingerprint,
                'staleness'          => 0,
                'quality_score'      => max(0, min(100, (int) ($meta['quality_score'] ?? 0))),
                'dirty_reason'       => '',
                'editor_type'        => 'system',
                'editor_id'          => 0,
                'update_time'        => $now,
            ];

            if ($status === WikiPage::STATUS_PUBLISHED) {
                $payload['published_at'] = $now;
            }

            if (is_array($existing)) {
                $pageId   = (int) $existing['id'];
                $revNo    = (int) $existing['rev_no'] + 1;
                $affected = Db::name('ai_wiki_pages')
                    ->where('id', $pageId)
                    ->where('rev_no', (int) $existing['rev_no'])
                    ->update($payload + ['rev_no' => $revNo]);

                if ((int) $affected === 0) {
                    throw WikiException::locked((string) $existing['slug']);
                }
            } else {
                $pageId        = (int) Db::name('ai_wiki_pages')->insertGetId($payload + [
                    'rev_no'      => 1,
                    'locked_by'   => '',
                    'create_time' => $now,
                ]);
                $revNo         = 1;
            }

            $this->upsertRevision($pageId, $revNo, $output, $meta, $now, (string) ($meta['change_reason'] ?? 'compile'));
            $this->replaceDependencies($pageId, $sourceKbId, $evidence, $now);
            $this->replaceLinks($spaceId, $pageId, $output->links, $now);
            WikiCounter::syncSpace($spaceId);

            return [
                'page_id'       => $pageId,
                'rev_no'        => $revNo,
                'status'        => $status,
                'deferred'      => false,
                'candidate_rev' => $revNo,
            ];
        });
    }

    /**
     * 源依赖指纹：docHash 排序后 sha1
     *
     * 用 hash 而不是「文档 id 集合」：同一批文档内容变了也要触发重编译。
     *
     * @param Evidence[] $evidence
     */
    public function fingerprint(array $evidence): string
    {
        $parts = [];
        foreach ($evidence as $item) {
            $parts[] = $item->docId . ':' . $item->docHash;
        }
        sort($parts);

        return sha1(implode('|', $parts));
    }

    /**
     * @param array<string, mixed> $meta
     */
    protected function upsertRevision(int $pageId, int $revNo, CompileOutput $output, array $meta, string $now, string $reason): void
    {
        $data = [
            'page_id'        => $pageId,
            'rev_no'         => $revNo,
            'content_md'     => $output->markdown,
            'summary'        => $output->summary,
            'outline'        => json_encode($this->extractOutline($output->markdown), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'prompt_version' => (string) ($meta['prompt_version'] ?? $output->promptVersion),
            'model_name'     => (string) ($meta['model'] ?? $output->model),
            'tokens_in'      => (int) ($meta['tokens_in'] ?? $output->tokensIn),
            'tokens_out'     => (int) ($meta['tokens_out'] ?? $output->tokensOut),
            'cost'           => round((float) ($meta['cost'] ?? 0), 6),
            'change_reason'  => $reason,
            'diff_summary'   => mb_substr((string) ($meta['diff_summary'] ?? ''), 0, 500),
            'editor_type'    => 'system',
            'editor_id'      => 0,
            'create_time'    => $now,
        ];

        $exists = Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->where('rev_no', $revNo)->find();
        if (is_array($exists)) {
            Db::name('ai_wiki_page_revisions')->where('id', (int) $exists['id'])->update($data);
        } else {
            Db::name('ai_wiki_page_revisions')->insert($data);
        }
    }

    /**
     * 依赖按页重建（增量重编译的唯一查询入口）
     *
     * @param Evidence[] $evidence
     */
    protected function replaceDependencies(int $pageId, int $sourceKbId, array $evidence, string $now): void
    {
        Db::name('ai_wiki_dependencies')->where('page_id', $pageId)->delete();

        if ($evidence === []) {
            return;
        }

        $rows = [];
        foreach ($evidence as $item) {
            $rows[] = [
                'page_id'     => $pageId,
                'kb_id'       => $sourceKbId,
                'doc_id'      => $item->docId,
                'chunk_id'    => $item->chunkId,
                'doc_hash'    => $item->docHash,
                'cite_no'     => $item->no,
                'cited'       => 1,
                'create_time' => $now,
            ];
        }

        Db::name('ai_wiki_dependencies')->insertAll($rows);
    }

    /**
     * 重建自动链接（保留人工建立的关系）
     *
     * @param array<int, array{slug:string, rel:string}> $links
     */
    protected function replaceLinks(int $spaceId, int $pageId, array $links, string $now): void
    {
        Db::name('ai_wiki_links')->where('from_page_id', $pageId)->where('auto', 1)->delete();

        if ($links === []) {
            return;
        }

        $slugs = [];
        foreach ($links as $link) {
            $slug = (string) ($link['slug'] ?? '');
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        $slugs = array_values(array_unique($slugs));
        if ($slugs === []) {
            return;
        }

        $targets = [];
        foreach (Db::name('ai_wiki_pages')
            ->where('space_id', $spaceId)
            ->whereIn('slug', $slugs)
            ->field('id,slug')
            ->select()
            ->toArray() as $row) {
            $targets[(string) $row['slug']] = (int) $row['id'];
        }

        $rows  = [];
        $dedup = [];
        foreach ($links as $link) {
            $slug = (string) ($link['slug'] ?? '');
            $toId = (int) ($targets[$slug] ?? 0);

            if ($toId <= 0 || $toId === $pageId) {
                continue;
            }

            $rel = (string) ($link['rel'] ?? 'related');
            if (!in_array($rel, self::REL_TYPES, true)) {
                $rel = 'related';
            }

            $key = $toId . ':' . $rel;
            if (isset($dedup[$key])) {
                continue;
            }
            $dedup[$key] = true;

            $rows[] = [
                'space_id'      => $spaceId,
                'from_page_id'  => $pageId,
                'to_page_id'    => $toId,
                'rel_type'      => $rel,
                'weight'        => 1,
                'auto'          => 1,
                'create_time'   => $now,
            ];
        }

        if ($rows !== []) {
            Db::name('ai_wiki_links')->insertAll($rows);
        }
    }

    /**
     * 从正文提取「标题 → 引用编号」大纲（段落级溯源的基础数据）
     *
     * @return array<int, array{heading:string, cite_ids:int[]}>
     */
    public function extractOutline(string $markdown): array
    {
        $outline  = [];
        $current  = '';
        $cites    = [];

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^#{1,6}\s+(.+)$/u', $trimmed, $m) === 1) {
                if ($current !== '') {
                    $outline[] = ['heading' => $current, 'cite_ids' => array_values(array_unique($cites))];
                }
                $current = trim($m[1]);
                $cites   = [];
                continue;
            }

            if (preg_match_all('/\[(\d{1,3})\]/', $trimmed, $mm) > 0) {
                foreach ($mm[1] as $no) {
                    $cites[] = (int) $no;
                }
            }
        }

        if ($current !== '') {
            $outline[] = ['heading' => $current, 'cite_ids' => array_values(array_unique($cites))];
        }

        return $outline;
    }
}
