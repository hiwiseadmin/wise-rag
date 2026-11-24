<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use wise\wiki\compile\PageWriter;
use wise\wiki\compile\ShadowPublisher;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiPage;
use wise\wiki\support\WikiCounter;

/**
 * 页面服务：查 / 目录 / 人工编辑 / 发布归档 / 候选版本 / 删除 / 锁
 *
 * 三条不变式：
 *  1. 页面正文是唯一真源 —— 删除 / 归档 / 改回非发布态都必须**同时撤回影子文档**；
 *  2. 人工编辑必须带 `rev_no` 乐观锁６辑的唯一防线）；
 *  3. 人工编辑后 `editor_type=admin`，此后重编译只写候选版本、不覆盖正文＼�。
 */
class PageService
{
    public function __construct(
        protected ShadowPublisher $publisher,
        protected PageWriter $writer
    ) {
    }

    public function get(int $pageId): ?WikiPage
    {
        $model = WikiPage::query()->where('id', $pageId)->find();

        return $model instanceof WikiPage ? $model : null;
    }

    public function findBySlug(int $spaceId, string $slug): ?WikiPage
    {
        $model = WikiPage::query()->where('space_id', $spaceId)->where('slug', $slug)->find();

        return $model instanceof WikiPage ? $model : null;
    }

    /**
     * 列表（后台分页）
     *
     * @param array<string, mixed> $filters space_id / status / dirty / keyword
     * @return array{list: array<int, array<string,mixed>>, total: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $limit = 20): array
    {
        $query = Db::name('ai_wiki_pages');

        $spaceId = (int) ($filters['space_id'] ?? 0);
        if ($spaceId > 0) {
            $query->where('space_id', $spaceId);
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('status', $status);
        }

        if (!empty($filters['dirty'])) {
            $query->where('dirty_reason', '<>', '');
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword): void {
                $q->whereLike('title', '%' . $keyword . '%')
                    ->whereOr('slug', 'like', '%' . $keyword . '%');
            });
        }

        $total = (int) (clone $query)->count();
        $rows  = $query->order('id', 'desc')
            ->page(max(1, $page), max(1, $limit))
            ->select()
            ->toArray();

        $list = array_map(static function (array $row): array {
            unset($row['content_md'], $row['outline']);

            return $row;
        }, $rows);

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 页面目录（给 Prompt 用：避免重复建页）
     *
     * @return array<int, array{slug:string, title:string, status:string}>
     */
    public function directory(int $spaceId, int $limit = 200): array
    {
        $rows = Db::name('ai_wiki_pages')
            ->where('space_id', $spaceId)
            ->where('status', '<>', WikiPage::STATUS_ARCHIVED)
            ->field('slug,title,status')
            ->order('id', 'asc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();

        return array_map(static fn (array $r): array => [
            'slug'   => (string) $r['slug'],
            'title'  => (string) $r['title'],
            'status' => (string) $r['status'],
        ], $rows);
    }

    /**
     * 待重编译页面（dirty）
     *
     * @return WikiPage[]
     */
    public function dirty(int $spaceId, int $limit = 20): array
    {
        return WikiPage::query()
            ->where('space_id', $spaceId)
            ->where('dirty_reason', '<>', '')
            ->order('update_time', 'asc')
            ->limit(max(1, $limit))
            ->select()
            ->all();
    }

    /**
     * 页面详情：正文 + 版本列表 + 待应用候选版本
     *
     * @return array{page: array<string,mixed>, revisions: array<int, array<string,mixed>>, candidate: ?array<string,mixed>}
     */
    public function detail(int $pageId, int $revisionLimit = 20): array
    {
        $page = $this->get($pageId);
        if ($page === null) {
            throw WikiException::pageNotFound($pageId);
        }

        return [
            'page'      => $page->toArray(),
            'revisions' => $this->revisions($pageId, $revisionLimit),
            'candidate' => $this->candidate($pageId),
        ];
    }

    /**
     * 版本列表（新的在前；正文截断，对比时再单独取）
     *
     * @return array<int, array<string, mixed>>
     */
    public function revisions(int $pageId, int $limit = 20): array
    {
        $rows = Db::name('ai_wiki_page_revisions')
            ->where('page_id', $pageId)
            ->order('rev_no', 'desc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();

        return array_map(static function (array $row): array {
            $content = (string) ($row['content_md'] ?? '');
            unset($row['content_md']);
            $row['content_preview'] = mb_substr($content, 0, 300);
            $row['content_length']  = mb_strlen($content);

            return $row;
        }, $rows);
    }

    /**
     * 取某个版本全文（修订对比用）
     *
     * @return array<string, mixed>|null
     */
    public function revision(int $pageId, int $revNo): ?array
    {
        $row = Db::name('ai_wiki_page_revisions')
            ->where('page_id', $pageId)
            ->where('rev_no', $revNo)
            ->find();

        return is_array($row) ? $row : null;
    }

    /**
     * 待应用候选版本（重编译产生、尚未写回正文）
     *
     * 约定：候选版本占用 `page.rev_no + 1`，`change_reason = rebuild_from_sources`。
     *
     * @return array<string, mixed>|null
     */
    public function candidate(int $pageId): ?array
    {
        $page = $this->get($pageId);
        if ($page === null) {
            return null;
        }

        $row = Db::name('ai_wiki_page_revisions')
            ->where('page_id', $pageId)
            ->where('rev_no', (int) $page->rev_no + 1)
            ->where('change_reason', 'rebuild_from_sources')
            ->find();

        return is_array($row) ? $row : null;
    }

    /**
     * 人工保存（乐观锁）
     *
     * @param array<string, mixed> $data title / content_md / summary / status
     * @param int                  $expectedRev 前端持有的版本号；>0 时强校验
     * @return array{page_id:int, rev_no:int, status:string, published:bool}
     * @throws WikiException 版本冲突 / 参数非法
     */
    public function saveManual(int $pageId, array $data, int $expectedRev, int $editorId = 0): array
    {
        $page = $this->get($pageId);
        if ($page === null) {
            throw WikiException::pageNotFound($pageId);
        }

        $title   = trim((string) ($data['title'] ?? $page->title));
        $content = (string) ($data['content_md'] ?? $page->content_md);
        $summary = mb_substr(trim((string) ($data['summary'] ?? $page->summary)), 0, 500);
        $status  = (string) ($data['status'] ?? $page->status);

        if ($title === '') {
            throw WikiException::invalidArgument('标题不能为空');
        }
        if (trim($content) === '') {
            throw WikiException::invalidArgument('正文不能为空');
        }
        if (!in_array($status, [WikiPage::STATUS_DRAFT, WikiPage::STATUS_REVIEW, WikiPage::STATUS_PUBLISHED, WikiPage::STATUS_ARCHIVED], true)) {
            $status = (string) $page->status;
        }

        $newRev = $this->writeVersion($pageId, $expectedRev, [
            'title'      => $title,
            'content_md' => $content,
            'summary'    => $summary,
            'status'     => $status,
        ], 'manual', 'admin', $editorId);

        return $this->afterWrite($pageId, $newRev, $status);
    }

    /**
     * Agent 写入页面（决策 2：**只能写 draft**）
     *
     * 语义：
     *  - 页面不存在 → 新建（`status=draft`，`rev_no=1`）；
     *  - 页面存在且非人工发布 → 覆盖正文并**降级为 draft**（已发布的会从影子库撤下，
     *    避免未审核内容被检索到）；
     *  - 页面已被人工编辑并发布 → 拒绝（人工内容不可被 Agent 覆盖）。
     *
     * @param array<string, mixed> $data title / content_md / summary / slug / kind
     * @return array{page_id:int, slug:string, status:string, rev_no:int, created:bool}
     * @throws WikiException
     */
    public function saveAgent(int $spaceId, array $data, string $editorType = 'agent', int $editorId = 0): array
    {
        $space = \wise\wiki\model\WikiSpace::query()->where('id', $spaceId)->find();
        if (!$space instanceof \wise\wiki\model\WikiSpace) {
            throw WikiException::spaceNotFound($spaceId);
        }

        $title   = trim((string) ($data['title'] ?? ''));
        $content = (string) ($data['content_md'] ?? '');

        if ($title === '') {
            throw WikiException::invalidArgument('标题不能为空');
        }
        if (trim($content) === '') {
            throw WikiException::invalidArgument('正文不能为空');
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if (!\wise\wiki\support\Slugger::isValid($slug)) {
            $slug = \wise\wiki\support\Slugger::make($title);
        }

        $kind = (string) ($data['kind'] ?? 'topic');
        if (!in_array($kind, WikiPage::KINDS, true)) {
            $kind = 'topic';
        }

        $existing = $this->findBySlug($spaceId, $slug);

        if ($existing !== null && $existing->isHumanEdited() && (string) $existing->status === WikiPage::STATUS_PUBLISHED) {
            throw WikiException::invalidArgument(
                '该页面已被人工编辑并发布，Agent 不可覆盖；请由管理员在后台处理。'
            );
        }

        $attrs = [
            'title'      => $title,
            'content_md' => $content,
            'summary'    => mb_substr(trim((string) ($data['summary'] ?? '')), 0, 500),
            'status'     => WikiPage::STATUS_DRAFT,
        ];

        if ($existing === null) {
            $now    = date('Y-m-d H:i:s');
            $pageId = (int) Db::name('ai_wiki_pages')->insertGetId([
                'space_id'           => $spaceId,
                'slug'               => $slug,
                'title'              => $title,
                'kind'               => $kind,
                'status'             => WikiPage::STATUS_DRAFT,
                'content_md'         => $content,
                'summary'            => $attrs['summary'],
                'outline'            => json_encode(
                    $this->writer->extractOutline($content),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'rev_no'             => 1,
                'source_fingerprint' => '',
                'shadow_doc_id'      => 0,
                'staleness'          => 0,
                'quality_score'      => 0,
                'dirty_reason'       => '',
                'editor_type'        => $editorType,
                'editor_id'          => $editorId,
                'locked_by'          => '',
                'create_time'        => $now,
                'update_time'        => $now,
            ]);

            Db::name('ai_wiki_page_revisions')->insert([
                'page_id'        => $pageId,
                'rev_no'         => 1,
                'content_md'     => $content,
                'summary'        => $attrs['summary'],
                'outline'        => json_encode($this->writer->extractOutline($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'prompt_version' => '',
                'model_name'     => '',
                'tokens_in'      => 0,
                'tokens_out'     => 0,
                'cost'           => 0,
                'change_reason'  => 'agent_upsert',
                'diff_summary'   => '',
                'editor_type'    => $editorType,
                'editor_id'      => $editorId,
                'create_time'    => $now,
            ]);

            $revNo   = 1;
            $created = true;
        } else {
            $pageId = (int) $existing->id;
            $revNo  = $this->writeVersion($pageId, (int) $existing->rev_no, $attrs, 'agent_upsert', $editorType, $editorId);
            $created = false;
        }

        try {
            $this->publisher->withdraw($pageId);
        } catch (WikiException $e) {
            error_log('[wise-llm-wiki] Agent 写入后撤回影子文档失败: ' . $e->getMessage());
        }

        WikiCounter::syncSpace($spaceId);

        return [
            'page_id' => $pageId,
            'slug'    => $slug,
            'status'  => WikiPage::STATUS_DRAFT,
            'rev_no'  => $revNo,
            'created' => $created,
        ];
    }

    /**
     * 应用候选版本（把 LLM 重编译结果写回正文）
     *
     * 应用后 `source_fingerprint` 置空：候选版本的证据指纹没有单独存，
     * 置空会让下一次自动触发编译重算一次（多编译一次，胜过滤镜导致漏更新）。
     *
     * @return array{page_id:int, rev_no:int, status:string, published:bool}
     */
    public function applyCandidate(int $pageId, int $editorId = 0): array
    {
        $page      = $this->get($pageId);
        $candidate = $this->candidate($pageId);

        if ($page === null) {
            throw WikiException::pageNotFound($pageId);
        }
        if ($candidate === null) {
            throw WikiException::invalidArgument('没有待应用的候选版本');
        }

        $newRev = $this->writeVersion($pageId, (int) $page->rev_no, [
            'title'              => (string) $page->title,
            'content_md'         => (string) $candidate['content_md'],
            'summary'            => (string) $candidate['summary'],
            'status'             => WikiPage::STATUS_PUBLISHED,
            'source_fingerprint' => '',
        ], 'manual', 'admin', $editorId, (int) $candidate['rev_no']);

        return $this->afterWrite($pageId, $newRev, WikiPage::STATUS_PUBLISHED);
    }

    /**
     * 发布（review/draft → published，并进入影子库）
     *
     * @return array{published:bool, doc_id:int}
     */
    public function publish(int $pageId, int $editorId = 0): array
    {
        $page = $this->get($pageId);
        if ($page === null) {
            throw WikiException::pageNotFound($pageId);
        }

        $now = date('Y-m-d H:i:s');

        $previousStatus = (string) $page->status;
        $previousAt     = (string) $page->published_at;

        Db::name('ai_wiki_pages')->where('id', $pageId)->update([
            'status'       => WikiPage::STATUS_PUBLISHED,
            'published_at' => $previousAt !== '' ? $previousAt : $now,
            'dirty_reason' => '',
            'update_time'  => $now,
        ]);

        $space = $this->spaceOf($pageId);
        if ($space === null) {
            throw WikiException::spaceNotFound((int) $page->space_id);
        }

        try {
            $result = $this->publisher->publish($space, $pageId);
        } catch (\Exception $e) {
            Db::name('ai_wiki_pages')->where('id', $pageId)->update([
                'status'       => $previousStatus,
                'published_at' => $previousAt !== '' ? $previousAt : null,
                'update_time'  => (string) $page->update_time,
            ]);

            throw $e;
        }

        return ['published' => (bool) $result['published'], 'doc_id' => (int) $result['doc_id']];
    }

    /**
     * 归档（撤下影子文档，不再参与检索与编译）
     *
     * @return array{archived:bool, withdrawn:bool}
     */
    public function archive(int $pageId, int $editorId = 0): array
    {
        $page = $this->get($pageId);
        if ($page === null) {
            throw WikiException::pageNotFound($pageId);
        }

        Db::name('ai_wiki_pages')->where('id', $pageId)->update([
            'status'       => WikiPage::STATUS_ARCHIVED,
            'editor_type'  => $editorId > 0 ? 'admin' : (string) $page->editor_type,
            'editor_id'    => $editorId > 0 ? $editorId : (int) $page->editor_id,
            'dirty_reason' => '',
            'update_time'  => date('Y-m-d H:i:s'),
        ]);

        $withdrawn = $this->publisher->withdraw($pageId);
        WikiCounter::syncSpace((int) $page->space_id);

        return ['archived' => true, 'withdrawn' => $withdrawn];
    }

    /**
     * 删除页面（先撤影子库文档，再删库内数据）
     */
    public function delete(int $pageId): bool
    {
        $page = $this->get($pageId);
        if ($page === null) {
            return false;
        }

        $spaceId = (int) $page->space_id;

        try {
            $this->publisher->withdraw($pageId);
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] 撤回影子文档失败: ' . $e->getMessage());
        }

        Db::transaction(static function () use ($pageId): void {
            Db::name('ai_wiki_links')->where('from_page_id', $pageId)->whereOr('to_page_id', $pageId)->delete();
            Db::name('ai_wiki_dependencies')->where('page_id', $pageId)->delete();
            Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->delete();
            Db::name('ai_wiki_pages')->where('id', $pageId)->delete();
        });

        WikiCounter::syncSpace($spaceId);

        return true;
    }

    /**
     * 清除脏标记（指纹未变 / 编译完成后调用）
     *
     * 必须与「跳过」配套：否则 dirty 页永远不清，`--dirty` 每轮都会重复处理同一页。
     */
    public function clearDirty(int $pageId): void
    {
        Db::name('ai_wiki_pages')
            ->where('id', $pageId)
            ->where('dirty_reason', '<>', '')
            ->update(['dirty_reason' => '', 'update_time' => date('Y-m-d H:i:s')]);
    }

    /**
     * 标脏（幂等：已脏的页面保留原原因，不覆盖）
     *
     * @return bool 本次是否真的标脏（false = 原本已脏）
     */
    public function markDirty(int $pageId, string $reason): bool
    {
        $affected = Db::name('ai_wiki_pages')
            ->where('id', $pageId)
            ->where('dirty_reason', '')
            ->update(['dirty_reason' => $reason, 'update_time' => date('Y-m-d H:i:s')]);

        return (int) $affected > 0;
    }

    /**
     * 抢编译锁（短锁 + TTL，防止同一页被并发编译两次）
     *
     * @return bool 是否抢到
     */
    public function acquireLock(int $pageId, string $owner, int $ttlSeconds = 300): bool
    {
        $now   = time();
        $until = date('Y-m-d H:i:s', $now + max(30, $ttlSeconds));

        $affected = Db::name('ai_wiki_pages')
            ->where('id', $pageId)
            ->whereRaw('(`locked_until` IS NULL OR `locked_until` < ?)', [date('Y-m-d H:i:s', $now)])
            ->update(['locked_by' => $owner, 'locked_until' => $until]);

        return (int) $affected > 0;
    }

    public function releaseLock(int $pageId): void
    {
        Db::name('ai_wiki_pages')
            ->where('id', $pageId)
            ->update(['locked_by' => '', 'locked_until' => null]);
    }


    /**
     * 写入一个新版本（乐观锁 + 版本快照），返回新版本号
     *
     * 乐观锁用「`where rev_no = 期望值` 的原子 UPDATE」实现：
     * 先查后写会在并发下双写成功，唯一键冲突反而把请求打成 500。
     *
     * @param array<string, mixed> $attrs
     * @throws WikiException 版本冲突
     */
    protected function writeVersion(
        int $pageId,
        int $expectedRev,
        array $attrs,
        string $reason,
        string $editorType,
        int $editorId,
        ?int $revisionNo = null
    ): int {
        $page = $this->get($pageId);
        if ($page === null) {
            throw WikiException::pageNotFound($pageId);
        }

        $currentRev = (int) $page->rev_no;

        if ($expectedRev > 0 && $expectedRev !== $currentRev) {
            throw WikiException::invalidArgument(sprintf(
                '页面已被他人修改（你基于 rev%d，当前 rev%d），请刷新后重试。',
                $expectedRev,
                $currentRev
            ));
        }

        $newRev = $revisionNo ?? ($currentRev + 1);
        $now    = date('Y-m-d H:i:s');

        $payload = $attrs;
        $payload['rev_no']           = $newRev;
        $payload['editor_type']      = $editorType;
        $payload['editor_id']        = $editorId;
        $payload['dirty_reason']     = '';
        $payload['update_time']      = $now;
        $payload['outline']          = json_encode(
            $this->writer->extractOutline((string) ($attrs['content_md'] ?? '')),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (($attrs['status'] ?? '') === WikiPage::STATUS_PUBLISHED) {
            $payload['published_at'] = (string) $page->published_at !== '' ? $page->published_at : $now;
        }

        $affected = Db::name('ai_wiki_pages')
            ->where('id', $pageId)
            ->where('rev_no', $currentRev)
            ->update($payload);

        if ((int) $affected === 0) {
            throw WikiException::invalidArgument('页面已被他人修改，请刷新后重试。');
        }

        $revision = [
            'page_id'        => $pageId,
            'rev_no'         => $newRev,
            'content_md'     => (string) ($attrs['content_md'] ?? ''),
            'summary'        => (string) ($attrs['summary'] ?? ''),
            'outline'        => $payload['outline'],
            'prompt_version' => '',
            'model_name'     => '',
            'tokens_in'      => 0,
            'tokens_out'     => 0,
            'cost'           => 0,
            'change_reason'  => $reason,
            'diff_summary'   => '',
            'editor_type'    => $editorType,
            'editor_id'      => $editorId,
            'create_time'    => $now,
        ];

        $exists = Db::name('ai_wiki_page_revisions')->where('page_id', $pageId)->where('rev_no', $newRev)->find();
        if (is_array($exists)) {
            Db::name('ai_wiki_page_revisions')->where('id', (int) $exists['id'])->update($revision);
        } else {
            Db::name('ai_wiki_page_revisions')->insert($revision);
        }

        WikiCounter::syncSpace((int) $page->space_id);

        return $newRev;
    }

    /**
     * 写后同步影子库（发布态 → 发布；非发布态 → 撤回）
     *
     * @return array{page_id:int, rev_no:int, status:string, published:bool}
     */
    protected function afterWrite(int $pageId, int $newRev, string $status): array
    {
        $published = false;

        if ($status === WikiPage::STATUS_PUBLISHED) {
            $space = $this->spaceOf($pageId);
            if ($space !== null) {
                try {
                    $result    = $this->publisher->publish($space, $pageId);
                    $published = (bool) $result['published'];
                } catch (WikiException $e) {
                    error_log('[wise-llm-wiki] 人工保存后发布影子库失败: ' . $e->getMessage());
                }
            }
        } else {
            $this->publisher->withdraw($pageId);
        }

        return ['page_id' => $pageId, 'rev_no' => $newRev, 'status' => $status, 'published' => $published];
    }

    protected function spaceOf(int $pageId): ?\wise\wiki\model\WikiSpace
    {
        $spaceId = (int) Db::name('ai_wiki_pages')->where('id', $pageId)->value('space_id');
        if ($spaceId <= 0) {
            return null;
        }

        $model = \wise\wiki\model\WikiSpace::query()->where('id', $spaceId)->find();

        return $model instanceof \wise\wiki\model\WikiSpace ? $model : null;
    }
}
