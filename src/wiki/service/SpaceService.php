<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use wise\wiki\bridge\RagBridge;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiSpace;
use wise\wiki\support\WikiCounter;

/**
 * 空间服务：建 / 查 / 暂停 / 删
 *
 * 建空间是本包唯一会**创建知识库**的地方：同时建影子库并双向绑定，
 * 任一步失败都要回滚已建的库，否则会留下孤儿知识库（wise:rag:prune 清不掉）。
 */
class SpaceService
{
    /** 影子库 kb_key 前缀（`wiki_{space_key}`，合计 ≤ 64，故 space_key ≤ 48） */
    public const SHADOW_PREFIX = 'wiki_';

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,47}$/';

    public function __construct(protected RagBridge $rag)
    {
    }

    /**
     * 建空间（含影子知识库）
     *
     * @param array<string, mixed> $data space_key / name / description / source_kb_id / visibility / owner_type /
     *                                   owner_id / compile_policy / model_provider / model_name / budget_tokens_month
     * @throws WikiException
     */
    public function create(array $data): WikiSpace
    {
        $spaceKey = trim((string) ($data['space_key'] ?? ''));
        if (preg_match(self::KEY_PATTERN, $spaceKey) !== 1) {
            throw WikiException::invalidArgument(
                'space_key 非法：需小写字母开头，仅含小写字母/数字/下划线，长度 2~48。'
            );
        }

        if ($this->findByKey($spaceKey) !== null) {
            throw WikiException::invalidArgument("空间已存在：{$spaceKey}");
        }

        $sourceKbId = (int) ($data['source_kb_id'] ?? 0);
        $sourceKb   = $this->rag->sourceKb($sourceKbId);
        if ($sourceKb === null) {
            throw WikiException::sourceKbMissing($sourceKbId);
        }

        $shadowKbId = 0;

        try {
            $embedding = (string) ($data['embedding'] ?? '');
            if ($embedding === '') {
                $embedding = (string) $sourceKb->embedding;
            }

            $shadowKbId = $this->rag->createKb(self::SHADOW_PREFIX . $spaceKey, [
                'name'        => 'Wiki：' . (string) ($data['name'] ?? $spaceKey),
                'description' => '由 wise-llm-wiki 维护的影子知识库（派生数据，可由页面重建）',
                'visibility'  => (string) ($data['visibility'] ?? WikiSpace::VISIBILITY_PRIVATE),
                'owner_type'  => (string) ($data['owner_type'] ?? 'admin'),
                'owner_id'    => (int) ($data['owner_id'] ?? 0),
                'embedding'   => $embedding,
            ]);

            $now = date('Y-m-d H:i:s');

            $spaceId = (int) Db::name('ai_wiki_spaces')->insertGetId([
                'space_key'            => $spaceKey,
                'name'                 => (string) ($data['name'] ?? $spaceKey),
                'description'          => (string) ($data['description'] ?? ''),
                'source_kb_id'         => $sourceKbId,
                'shadow_kb_id'         => $shadowKbId,
                'owner_type'           => (string) ($data['owner_type'] ?? 'admin'),
                'owner_id'             => (int) ($data['owner_id'] ?? 0),
                'visibility'           => (string) ($data['visibility'] ?? WikiSpace::VISIBILITY_PRIVATE),
                'compile_policy'       => isset($data['compile_policy'])
                    ? json_encode($data['compile_policy'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'model_provider'       => (string) ($data['model_provider'] ?? ''),
                'model_name'           => (string) ($data['model_name'] ?? ''),
                'prompt_version'       => (string) config('wise-rag.wiki.compile.prompt_version', 'v1'),
                'budget_tokens_month'  => (int) ($data['budget_tokens_month'] ?? 0),
                'tokens_used_month'    => 0,
                'page_count'           => 0,
                'dirty_count'          => 0,
                'status'               => WikiSpace::STATUS_ACTIVE,
                'create_time'          => $now,
                'update_time'          => $now,
            ]);
        } catch (\Throwable $e) {
            if ($shadowKbId > 0) {
                try {
                    $this->rag->deleteKb($shadowKbId);
                } catch (\Throwable $ignore) {
                    error_log('[wise-llm-wiki] 回滚影子知识库失败: ' . $ignore->getMessage());
                }
            }

            if ($e instanceof WikiException) {
                throw $e;
            }

            throw WikiException::invalidArgument('创建空间失败：' . $e->getMessage());
        }

        $this->rag->flushToolCache();

        $space = $this->get($spaceId);
        if ($space === null) {
            throw WikiException::spaceNotFound($spaceId);
        }

        return $space;
    }

    /**
     * 更新空间（不碰源库 / 影子库绑定：换源库等于换知识域，应新建空间）
     *
     * @param array<string, mixed> $data name / description / visibility / owner_type / owner_id /
     *                                   compile_policy / model_provider / model_name / budget_tokens_month / status
     * @throws WikiException
     */
    public function update(int $spaceId, array $data): WikiSpace
    {
        $space = $this->get($spaceId);
        if ($space === null) {
            throw WikiException::spaceNotFound($spaceId);
        }

        $allow = [
            'name', 'description', 'visibility', 'owner_type', 'owner_id',
            'model_provider', 'model_name', 'budget_tokens_month', 'status',
        ];

        $payload = [];
        foreach ($allow as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }

        if (isset($data['compile_policy'])) {
            $payload['compile_policy'] = is_array($data['compile_policy'])
                ? json_encode($data['compile_policy'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $data['compile_policy'];
        }

        if (isset($payload['status']) && !in_array($payload['status'], [
            WikiSpace::STATUS_ACTIVE, WikiSpace::STATUS_PAUSED, WikiSpace::STATUS_DISABLED,
        ], true)) {
            throw WikiException::invalidArgument('非法状态：' . (string) $payload['status']);
        }

        if ($payload !== []) {
            $payload['update_time'] = date('Y-m-d H:i:s');
            Db::name('ai_wiki_spaces')->where('id', $spaceId)->update($payload);
        }

        $fresh = $this->get($spaceId);
        if ($fresh === null) {
            throw WikiException::spaceNotFound($spaceId);
        }

        return $fresh;
    }

    /**
     * 按 id 或 space_key 取空间
     */
    public function get(int|string $key): ?WikiSpace
    {
        $query = WikiSpace::query();

        if (is_int($key) || ctype_digit((string) $key)) {
            $model = $query->where('id', (int) $key)->find();
        } else {
            $model = $query->where('space_key', (string) $key)->find();
        }

        return $model instanceof WikiSpace ? $model : null;
    }

    public function findByKey(string $spaceKey): ?WikiSpace
    {
        $model = WikiSpace::query()->where('space_key', $spaceKey)->find();

        return $model instanceof WikiSpace ? $model : null;
    }

    /**
     * @return WikiSpace[]
     */
    public function all(bool $onlyActive = false): array
    {
        $query = WikiSpace::query();
        if ($onlyActive) {
            $query->where('status', WikiSpace::STATUS_ACTIVE);
        }

        return $query->order('id', 'asc')->select()->all();
    }

    /**
     * 暂停（预算用尽 / 源库丢失时调用，不静默停摆）
     */
    public function pause(WikiSpace $space, string $reason = ''): void
    {
        $space->save(['status' => WikiSpace::STATUS_PAUSED]);
        WikiCounter::syncSpace((int) $space->id);

        if ($reason !== '') {
            error_log(sprintf('[wise-llm-wiki] 空间 %s 已暂停：%s', (string) $space->space_key, $reason));
        }
    }

    /**
     * 删除空间（级联：页面 / 版本 / 依赖 / 链接 / 任务 + 影子知识库）
     *
     * 影子库整库删除，因此不必逐个撤回影子文档（比逐页 withdraw 快一个量级）。
     */
    public function delete(WikiSpace $space, bool $withShadowKb = true): bool
    {
        $shadowKbId = (int) $space->shadow_kb_id;
        $spaceId    = (int) $space->id;

        $pageIds = array_map('intval', Db::name('ai_wiki_pages')->where('space_id', $spaceId)->column('id'));
        if ($pageIds === []) {
            $pageIds = [0];
        }

        Db::transaction(static function () use ($spaceId, $pageIds): void {
            Db::name('ai_wiki_links')->where('space_id', $spaceId)->delete();
            Db::name('ai_wiki_dependencies')->whereIn('page_id', $pageIds)->delete();
            Db::name('ai_wiki_page_revisions')->whereIn('page_id', $pageIds)->delete();
            Db::name('ai_wiki_pages')->where('space_id', $spaceId)->delete();
            Db::name('ai_wiki_compile_jobs')->where('space_id', $spaceId)->delete();
            Db::name('ai_wiki_spaces')->where('id', $spaceId)->delete();
        });

        $ok = true;

        if ($withShadowKb && $shadowKbId > 0) {
            try {
                $this->rag->deleteKb($shadowKbId);
            } catch (\Exception $e) {
                error_log('[wise-llm-wiki] 删除影子知识库失败: ' . $e->getMessage());
                $ok = false;
            }
        }

        if ($ok) {
            $this->rag->flushToolCache();
        }

        return $ok;
    }
}
