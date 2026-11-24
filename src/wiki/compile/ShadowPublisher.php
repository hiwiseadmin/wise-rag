<?php
declare(strict_types=1);

namespace wise\wiki\compile;

use think\facade\Db;
use wise\wiki\bridge\RagBridge;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;

/**
 * 发布到影子知识库（页面 → 可检索）
 *
 * 为什么单独一层：embedding 是外部 HTTP 调用，**不能在页面落库的事务里执行**
 * （R-10：占着事务等网络，锁表风险）。所以顺序固定为「先落库、后发布」，
 * 发布失败不回滚页面，只在 shadow_doc_id=0 上留痕，由 `wiki:doctor` 补发。
 *
 * external_id = `wiki:{page_id}` 命中 wise-rag 的 `uk_kb_ext`，
 * 因此重复发布是幂等的：正文没变就跳过 embedding，变了才重新索引。
 */
class ShadowPublisher
{
    public function __construct(protected RagBridge $rag)
    {
    }

    /**
     * @return array{published:bool, doc_id:int, reason:string}
     * @throws WikiException
     */
    public function publish(WikiSpace $space, int $pageId): array
    {
        $page = Db::name('ai_wiki_pages')->where('id', $pageId)->find();
        if (!is_array($page)) {
            throw WikiException::pageNotFound($pageId);
        }

        if ((string) $page['status'] !== WikiPage::STATUS_PUBLISHED) {
            return ['published' => false, 'doc_id' => 0, 'reason' => 'not_published'];
        }

        $shadowKbId = (int) $space->shadow_kb_id;
        $kb         = $this->rag->sourceKb($shadowKbId);
        if ($kb === null) {
            throw WikiException::shadowKbMissing($shadowKbId);
        }

        $docId = $this->rag->publishText($kb, (string) $page['content_md'], [
            'external_id' => 'wiki:' . $pageId,
            'title'       => (string) $page['title'],
            'url'         => '/admin/rag/wiki/page?id=' . $pageId,
        ]);

        Db::name('ai_wiki_pages')->where('id', $pageId)->update([
            'shadow_doc_id' => $docId,
            'update_time'   => date('Y-m-d H:i:s'),
        ]);

        $this->rag->refreshStats($shadowKbId);

        return ['published' => true, 'doc_id' => $docId, 'reason' => 'ok'];
    }

    /**
     * 从影子库撤回（页面过期 / 归档时调用）
     *
     * 宁可检索不到，也不返回过期结论＼�。
     */
    public function withdraw(int $pageId): bool
    {
        $page = Db::name('ai_wiki_pages')->where('id', $pageId)->find();
        if (!is_array($page) || (int) $page['shadow_doc_id'] <= 0) {
            return false;
        }

        $ok = $this->rag->deleteDocument((int) $page['shadow_doc_id']);

        Db::name('ai_wiki_pages')->where('id', $pageId)->update([
            'shadow_doc_id' => 0,
            'update_time'   => date('Y-m-d H:i:s'),
        ]);

        return $ok;
    }
}
