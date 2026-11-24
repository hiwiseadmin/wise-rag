<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use think\facade\Queue;
use wise\wiki\bridge\RagBridge;
use wise\wiki\dto\CompileInput;
use wise\wiki\job\CompilePageJob;
use wise\wiki\model\WikiPage;
use wise\wiki\support\WikiCounter;

/**
 * 失效与重编译调度＼�
 *
 * 三条铁律：
 *  1. **事件里不调 LLM**：只做「查依赖 → 比对 doc_hash → 标 dirty → 入队」，毫秒级返回；
 *  2. **事件到 ≠ 内容变**：`--force` 重索引会对全库重放 DocumentIndexed，
 *     必须用依赖表里记录的 `doc_hash` 与当前 `ai_kb_documents.content_hash` 比对，
 *     相同就跳过（否则一晚烧穿整月预算，R-02）；
 *  3. **幂等**：已 dirty 的页面不重复标、不重复入队；已 clean 的页面才标。
 */
class InvalidationService
{
    public function __construct(
        protected RagBridge $rag,
        protected PageService $pages
    ) {
    }

    /**
     * 源文档变化 → 标记受影响页面
     *
     * @return array{spaces:int, pages:int, dirty:int, unchanged:int, queued:int}
     */
    public function byDocument(int $kbId, int $docId, string $reason = WikiPage::DIRTY_DOC_CHANGED): array
    {
        $result = ['spaces' => 0, 'pages' => 0, 'dirty' => 0, 'unchanged' => 0, 'queued' => 0];

        if ($kbId <= 0 || $docId <= 0) {
            return $result;
        }

        $spaceIds = array_map('intval', Db::name('ai_wiki_spaces')
            ->where('source_kb_id', $kbId)
            ->column('id'));

        if ($spaceIds === []) {
            return $result;
        }
        $result['spaces'] = count($spaceIds);

        $pageIds = Db::name('ai_wiki_pages')
            ->whereIn('space_id', $spaceIds)
            ->column('id');
        if ($pageIds === []) {
            return $result;
        }
        $pageIds = array_map('intval', $pageIds);

        $deps = Db::name('ai_wiki_dependencies')
            ->whereIn('page_id', $pageIds)
            ->where('doc_id', $docId)
            ->where('cited', 1)
            ->field('page_id, doc_hash')
            ->select()
            ->toArray();

        if ($deps === []) {
            return $result;
        }

        $current = (string) ($this->rag->docHashes([$docId])[$docId] ?? '');

        $byPage = [];
        foreach ($deps as $dep) {
            $byPage[(int) $dep['page_id']][] = (string) $dep['doc_hash'];
        }
        $result['pages'] = count($byPage);

        foreach ($byPage as $pageId => $hashes) {
            $hashes = array_values(array_unique($hashes));

            if ($current !== '' && $hashes === [$current]) {
                $result['unchanged']++;
                continue;
            }

            if (!$this->pages->markDirty((int) $pageId, $reason)) {
                continue;
            }

            $result['dirty']++;

            if ($this->enqueue((int) $pageId, $reason)) {
                $result['queued']++;
            }
        }

        foreach ($spaceIds as $spaceId) {
            WikiCounter::syncSpace($spaceId);
        }

        return $result;
    }

    /**
     * prompt 版本漂移 → 全空间标脏（prompt 变了，产物必然不同）
     *
     * @return int 标脏的页面数
     */
    public function byPromptVersion(int $spaceId, ?string $targetVersion = null): int
    {
        if ($spaceId <= 0) {
            return 0;
        }

        $target  = $targetVersion ?? (string) config('wise-rag.wiki.compile.prompt_version', 'v1');
        $dirty   = Db::name('ai_wiki_pages')
            ->where('space_id', $spaceId)
            ->where('status', '<>', WikiPage::STATUS_ARCHIVED)
            ->where('dirty_reason', '')
            ->update(['dirty_reason' => WikiPage::DIRTY_PROMPT_CHANGED, 'update_time' => date('Y-m-d H:i:s')]);

        Db::name('ai_wiki_spaces')->where('id', $spaceId)->update([
            'prompt_version' => $target,
            'update_time'    => date('Y-m-d H:i:s'),
        ]);

        WikiCounter::syncSpace($spaceId);

        return (int) $dirty;
    }

    /**
     * 巡检用：哪些空间存在 prompt 版本漂移
     *
     * @return array<int, array{id:int, space_key:string, from:string, to:string}>
     */
    public function promptDrifts(): array
    {
        $target = (string) config('wise-rag.wiki.compile.prompt_version', 'v1');

        $rows = Db::name('ai_wiki_spaces')
            ->where('status', '<>', 'disabled')
            ->field('id,space_key,prompt_version')
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $row) {
            if ((string) $row['prompt_version'] !== $target) {
                $out[] = [
                    'id'        => (int) $row['id'],
                    'space_key' => (string) $row['space_key'],
                    'from'      => (string) $row['prompt_version'],
                    'to'        => $target,
                ];
            }
        }

        return $out;
    }

    /**
     * 入队一次页面重编译
     *
     * 队列不可用（未安装 think-queue / 入队失败）时返回 false —— 页面仍是 dirty，
     * 由 `wiki:compile --dirty` 兜底，不会丢更新。
     */
    public function enqueue(int $pageId, string $trigger = CompileInput::TRIGGER_DOC_CHANGED): bool
    {
        if (!(bool) config('wise-rag.wiki.queue.enabled', true)) {
            return false;
        }

        if (!class_exists(Queue::class)) {
            return false;
        }

        $page = Db::name('ai_wiki_pages')
            ->where('id', $pageId)
            ->field('id,space_id,title,slug,kind')
            ->find();

        if (!is_array($page)) {
            return false;
        }

        $connection = (string) config('wise-rag.wiki.queue.connection', '');
        $queueName  = (string) config('wise-rag.wiki.queue.queue', 'wise-wiki');

        try {
            Queue::push(
                CompilePageJob::class,
                [
                    'space_id' => (int) $page['space_id'],
                    'page_id'  => $pageId,
                    'topic'    => (string) $page['title'],
                    'slug'     => (string) $page['slug'],
                    'kind'     => (string) $page['kind'],
                    'trigger'  => $trigger,
                ],
                $queueName !== '' ? $queueName : null,
                $connection !== '' ? $connection : null
            );

            return true;
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] 重编译入队失败: ' . $e->getMessage());

            return false;
        }
    }
}
