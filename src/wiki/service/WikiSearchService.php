<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use wise\wiki\bridge\RagBridge;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;

/**
 * 检索编排（Agent 工具 / MCP / 后台调试共用）
 *
 * 两种策略（`search.strategy`）：
 *  - `page_first`（默认）：先查影子库（编译后的结论）。最高分 ≥ `search.threshold` 就直接返回页面，
 *    **不再查源库** —— 让 LLM 读结论而不是读碎片，省 token；
 *  - `both_merged`：影子库与源库都查，分成 `pages` / `chunks` 两段返回，由调用方决定怎么用。
 *
 * 页面命中要把「影子库文档」反查成「wiki 页面」：优先用 `external_id = wiki:{page_id}`
 * （发布时写入的幂等键），取不到再退化为解析 `cite.url` 里的 id。
 */
class WikiSearchService
{
    public const STRATEGY_PAGE_FIRST  = 'page_first';
    public const STRATEGY_BOTH_MERGED = 'both_merged';

    public function __construct(protected RagBridge $rag)
    {
    }

    /**
     * @param string[] $spaceKeys 限定空间（空 = 全部 active 空间）
     * @return array<string, mixed>
     * @throws WikiException
     */
    public function search(string $query, array $spaceKeys = [], int $topK = 5, ?string $strategy = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw WikiException::invalidArgument('query 不能为空');
        }

        $strategy  = $strategy ?: (string) config('wise-rag.wiki.search.strategy', self::STRATEGY_PAGE_FIRST);
        $strategy  = in_array($strategy, [self::STRATEGY_PAGE_FIRST, self::STRATEGY_BOTH_MERGED], true)
            ? $strategy
            : self::STRATEGY_PAGE_FIRST;
        $threshold = (float) config('wise-rag.wiki.search.threshold', 0.45);
        $topK      = min(20, max(1, $topK));
        $spaces    = $this->resolveSpaces($spaceKeys);

        $result = [
            'query'    => $query,
            'strategy' => $strategy,
            'space'    => array_map(static fn (WikiSpace $s): string => (string) $s->space_key, $spaces),
            'threshold'=> $threshold,
            'fallback' => false,
            'count'    => 0,
            'pages'    => [],
            'chunks'   => [],
        ];

        if ($spaces === []) {
            return $result;
        }

        $shadowIds = [];
        $sourceIds = [];
        foreach ($spaces as $space) {
            if ((int) $space->shadow_kb_id > 0) {
                $shadowIds[] = (int) $space->shadow_kb_id;
            }
            if ((int) $space->source_kb_id > 0) {
                $sourceIds[] = (int) $space->source_kb_id;
            }
        }

        $pages = [];
        if ($shadowIds !== []) {
            try {
                $pages = $this->mapPages($this->rag->retrieve($query, $shadowIds, $topK));
            } catch (WikiException $e) {
                error_log('[wise-llm-wiki] 影子库检索失败: ' . $e->getMessage());
            }
        }

        $topPageScore = $pages === [] ? 0.0 : (float) $pages[0]['score'];

        if ($strategy === self::STRATEGY_PAGE_FIRST && $pages !== [] && $topPageScore >= $threshold) {
            $result['pages'] = $pages;
            $result['count'] = count($pages);

            return $result;
        }

        $chunks = [];
        if ($sourceIds !== []) {
            $chunks = $this->mapChunks($this->rag->retrieve($query, $sourceIds, $topK));
        }

        $result['pages']    = $pages;
        $result['chunks']   = $chunks;
        $result['count']    = count($pages) + count($chunks);
        $result['fallback'] = $strategy === self::STRATEGY_PAGE_FIRST;

        return $result;
    }

    /**
     * 取单页（供 wiki.get 工具）
     *
     * @return array<string, mixed>
     * @throws WikiException
     */
    public function getPage(?string $spaceKey, ?string $slug, int $pageId = 0): array
    {
        $page = null;

        if ($pageId > 0) {
            $page = Db::name('ai_wiki_pages')->where('id', $pageId)->find();
        } elseif ($slug !== null && $slug !== '') {
            $space = $spaceKey !== null && $spaceKey !== ''
                ? app(SpaceService::class)->findByKey($spaceKey)
                : null;

            $query = Db::name('ai_wiki_pages')->where('slug', $slug);
            if ($space !== null) {
                $query->where('space_id', (int) $space->id);
            }
            $page = $query->find();
        }

        if (!is_array($page)) {
            throw WikiException::pageNotFound($slug ?? $pageId);
        }

        if ((string) $page['status'] !== WikiPage::STATUS_PUBLISHED) {
            throw WikiException::pageNotFound($slug ?? $pageId);
        }

        $outline = json_decode((string) ($page['outline'] ?? ''), true);

        return [
            'id'          => (int) $page['id'],
            'space_id'    => (int) $page['space_id'],
            'slug'        => (string) $page['slug'],
            'title'       => (string) $page['title'],
            'kind'        => (string) $page['kind'],
            'status'      => (string) $page['status'],
            'rev_no'      => (int) $page['rev_no'],
            'summary'     => (string) $page['summary'],
            'staleness'   => (int) $page['staleness'],
            'quality'     => (int) $page['quality_score'],
            'updated_at'  => (string) ($page['update_time'] ?? ''),
            'url'         => '/admin/rag/wiki/page?id=' . (int) $page['id'],
            'content_md'  => (string) $page['content_md'],
            'citations'   => is_array($outline)
                ? array_values(array_unique(array_merge(...array_map(
                    static fn (array $o): array => (array) ($o['cite_ids'] ?? []),
                    $outline
                )))) : [],
            'searchable'  => (string) $page['status'] === WikiPage::STATUS_PUBLISHED,
        ];
    }

    /**
     * 批量取页面正文（评测等只读场景，避免逐页 N+1）
     *
     * 说明：这里刻意不做 published 校验 —— 调用方是评测器，输入来自影子库命中（本身只含已发布页），
     * 且需要一次拿全用于指标计算。对外读取（wiki.get / MCP）请走 getPage()。
     *
     * @param int[] $pageIds
     * @return array<int, string> page_id => content_md
     */
    public function bodies(array $pageIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds))));
        if ($ids === []) {
            return [];
        }

        $rows = Db::name('ai_wiki_pages')->whereIn('id', $ids)->field('id,content_md')->select()->toArray();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) $row['content_md'];
        }

        return $out;
    }

    /**
     * @param string[] $spaceKeys
     * @return WikiSpace[]
     */
    protected function resolveSpaces(array $spaceKeys): array
    {
        $keys = array_values(array_filter(array_map(static fn ($k): string => trim((string) $k), $spaceKeys)));

        $query = WikiSpace::query()->where('status', WikiSpace::STATUS_ACTIVE);
        if ($keys !== []) {
            $query->whereIn('space_key', $keys);
        }

        return $query->order('id', 'asc')->select()->all();
    }

    /**
     * 影子库命中 → 页面信息（批量补全，避免 N+1）
     *
     * @param array<int, object> $hits
     * @return array<int, array<string, mixed>>
     */
    protected function mapPages(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $docIds = [];
        foreach ($hits as $hit) {
            if ((int) ($hit->docId ?? 0) > 0) {
                $docIds[] = (int) $hit->docId;
            }
        }

        $snapshot = [];
        try {
            $snapshot = $this->rag->documentsSnapshot($docIds);
        } catch (WikiException $e) {
        }

        $resolved = [];
        foreach ($hits as $hit) {
            $docId = (int) ($hit->docId ?? 0);
            $pageId = 0;

            $externalId = (string) ($snapshot[$docId]['external_id'] ?? '');
            if (str_starts_with($externalId, 'wiki:')) {
                $pageId = (int) substr($externalId, 5);
            }

            if ($pageId <= 0) {
                $url = (string) ($hit->meta['cite']['url'] ?? '');
                if (preg_match('/[?&]id=(\d+)/', $url, $m) === 1) {
                    $pageId = (int) $m[1];
                }
            }

            $resolved[] = ['hit' => $hit, 'page_id' => $pageId];
        }

        $pageIds = array_values(array_unique(array_filter(array_column($resolved, 'page_id'))));
        $pages   = [];
        if ($pageIds !== []) {
            $rows = Db::name('ai_wiki_pages')
                ->whereIn('id', $pageIds)
                ->field('id,slug,title,status,rev_no,summary,staleness,quality_score,update_time')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $pages[(int) $row['id']] = $row;
            }
        }

        $out = [];
        foreach ($resolved as $item) {
            $hit    = $item['hit'];
            $pageId = (int) $item['page_id'];

            if ($pageId <= 0) {
                continue;
            }

            $row = $pages[$pageId] ?? null;

            $out[] = [
                'page_id'   => $pageId,
                'slug'      => (string) ($row['slug'] ?? ''),
                'title'     => (string) ($row['title'] ?? ($hit->meta['cite']['title'] ?? '')),
                'status'    => (string) ($row['status'] ?? ''),
                'rev_no'    => (int) ($row['rev_no'] ?? 0),
                'summary'   => (string) ($row['summary'] ?? ''),
                'staleness' => (int) ($row['staleness'] ?? 0),
                'quality'   => (int) ($row['quality_score'] ?? 0),
                'score'     => round((float) $hit->score, 4),
                'url'       => '/admin/rag/wiki/page?id=' . $pageId,
                'updated_at'=> (string) ($row['update_time'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 源库命中 → 碎片信息
     *
     * @param array<int, object> $hits
     * @return array<int, array<string, mixed>>
     */
    protected function mapChunks(array $hits): array
    {
        return array_map(static function (object $hit): array {
            $cite = (array) ($hit->meta['cite'] ?? []);

            return [
                'doc_id'  => (int) ($hit->docId ?? 0),
                'chunk_id'=> (int) ($hit->chunkId ?? 0),
                'content' => (string) ($hit->content ?? ''),
                'score'   => round((float) ($hit->score ?? 0), 4),
                'source'  => [
                    'kb_key'  => (string) ($cite['kb_key'] ?? ''),
                    'title'   => (string) ($cite['title'] ?? ''),
                    'url'     => (string) ($cite['url'] ?? ''),
                    'heading' => (string) ($cite['heading'] ?? ''),
                ],
            ];
        }, $hits);
    }
}
