<?php
declare(strict_types=1);

namespace wise\wiki\controller\admin;

use think\facade\Db;
use wise\wiki\bridge\RagBridge;
use wise\wiki\dto\CompileInput;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiPage;
use wise\wiki\service\CompileService;
use wise\wiki\service\PageService;
use wise\wiki\service\SpaceService;

/**
 * Wiki 后台管理（包内控制器 + 包内视图）
 *
 * 正规链路（与 wise-rag / wise-health-check 一致）：
 *  - 继承宿主 BaseController，复用后台通用模板变量与 success()/error() 响应；
 *  - initialize() 中 ViewScope::activate('wise-rag')，使 $this->fetch() 兜底到本包视图
 *    （`packages/wiseadmin/wise-rag/view/admin/wiki/*`，宿主 app/admin/view 仍可放同名文件覆盖）；
 *  - 路由前缀 admin/rag/wiki，默认挂 RbacMiddleware，权限由 MenuProvider 派生
 *    （admin.rag.wiki.*，如 admin.rag.wiki.index / admin.rag.wiki.page_save）。
 *
 * 长耗时动作（编译）只做「单次触发」：批量请走 `php think wiki:compile` 或队列，
 * 避免一个 HTTP 请求里跑完整编译管线。
 */
class WikiController extends \app\admin\controller\BaseController
{
    /**
     * 视图 scope = 包标识（ViewScope 按 packages/wiseadmin/{scope}/view 自动发现）
     *
     * 注意不是 `rag.wiki`：那是菜单 / 权限 / 扩展点里的子包标识，不是目录名，
     * 用它做 scope 会解析不到 view 目录而抛 TemplateNotFoundException。
     */
    protected const VIEW_SCOPE = 'wise-rag';

    protected function initialize()
    {
        parent::initialize();

        try {
            if (class_exists(\app\common\view\ViewScope::class)) {
                \app\common\view\ViewScope::activate(self::VIEW_SCOPE);
            } else {
                $this->request->view_scope = self::VIEW_SCOPE;
            }
        } catch (\Exception $e) {
        }
    }

    public function index()
    {
        $this->assign([
            'counts'     => $this->counters(),
            'compilable' => function_exists('wise_wiki_compilable') ? wise_wiki_compilable() : false,
        ]);

        return $this->fetch('admin/wiki/index');
    }


    /**
     * 概览（页面顶部卡片 + 预算条）
     */
    public function stats()
    {
        $budgetLimit = (int) config('wise-rag.wiki.budget.tokens_per_month', 0);
        $used        = (int) Db::name('ai_wiki_spaces')->sum('tokens_used_month');

        return $this->success('ok', $this->counters() + [
            'budget' => [
                'limit'     => $budgetLimit,
                'used'      => $used,
                'ratio'     => $budgetLimit > 0 ? round(min(1, $used / $budgetLimit), 4) : 0,
                'exhausted' => $budgetLimit > 0 && $used >= $budgetLimit,
            ],
        ]);
    }


    public function spaces()
    {
        $rows = Db::name('ai_wiki_spaces')->order('id', 'asc')->select()->toArray();

        $kbNames = [];
        try {
            foreach (app(RagBridge::class)->knowledgeBases(false) as $kb) {
                $kbNames[(int) $kb['id']] = $kb['name'];
            }
        } catch (\Exception $e) {
        }

        foreach ($rows as &$row) {
            $row['source_kb_name'] = $kbNames[(int) $row['source_kb_id']] ?? '';
            $row['budget_ratio']   = (int) $row['budget_tokens_month'] > 0
                ? round(min(1, (int) $row['tokens_used_month'] / (int) $row['budget_tokens_month']), 4)
                : 0;
        }
        unset($row);

        return $this->success('ok', ['list' => $rows, 'total' => count($rows)]);
    }

    /**
     * 源知识库下拉
     */
    public function kbList()
    {
        try {
            return $this->success('ok', ['list' => app(RagBridge::class)->knowledgeBases()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function spaceSave()
    {
        $id   = (int) $this->request->param('id/d', 0);
        $data = [
            'name'                 => trim((string) $this->request->param('name', '')),
            'description'          => (string) $this->request->param('description', ''),
            'visibility'           => (string) $this->request->param('visibility/s', 'private'),
            'budget_tokens_month'  => (int) $this->request->param('budget_tokens_month/d', 0),
            'model_provider'       => trim((string) $this->request->param('model_provider', '')),
            'model_name'           => trim((string) $this->request->param('model_name', '')),
            'compile_policy'       => $this->request->param('compile_policy', null),
        ];

        if ($data['name'] === '') {
            return $this->error('空间名称不能为空');
        }

        try {
            $service = app(SpaceService::class);

            if ($id > 0) {
                $space = $service->update($id, $data);

                return $this->success('已保存', ['id' => (int) $space->id]);
            }

            $spaceKey = trim((string) $this->request->param('space_key/s', ''));
            if ($spaceKey === '') {
                return $this->error('空间标识 space_key 不能为空');
            }

            $space = $service->create([
                'space_key'    => $spaceKey,
                'source_kb_id' => (int) $this->request->param('source_kb_id/d', 0),
            ] + $data);

            return $this->success('已创建（影子知识库已建立）', [
                'id'            => (int) $space->id,
                'shadow_kb_id'  => (int) $space->shadow_kb_id,
            ]);
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    public function spaceDelete()
    {
        $id = (int) $this->request->param('id/d', 0);

        $space = app(SpaceService::class)->get($id);
        if ($space === null) {
            return $this->error('空间不存在');
        }

        try {
            $ok = app(SpaceService::class)->delete($space, true);

            return $ok
                ? $this->success('已删除（含影子知识库）')
                : $this->error('空间已删除，但影子知识库清理失败，请执行 wiki:doctor --apply');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }


    public function pages()
    {
        $filters = [
            'space_id' => (int) $this->request->param('space_id/d', 0),
            'status'   => (string) $this->request->param('status/s', ''),
            'dirty'    => (int) $this->request->param('dirty/d', 0),
            'keyword'  => (string) $this->request->param('keyword/s', ''),
        ];

        $page  = max(1, (int) $this->request->param('page/d', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit/d', 20)));

        return $this->success('ok', app(PageService::class)->paginate($filters, $page, $limit));
    }

    /**
     * 页面详情（正文 + 版本列表 + 候选版本）
     */
    public function page()
    {
        $id = (int) $this->request->param('id/d', 0);

        try {
            return $this->success('ok', app(PageService::class)->detail($id));
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 取某个版本全文（修订对比）
     */
    public function pageRevision()
    {
        $id    = (int) $this->request->param('id/d', 0);
        $revNo = (int) $this->request->param('rev_no/d', 0);

        $row = app(PageService::class)->revision($id, $revNo);

        return $row === null ? $this->error('版本不存在') : $this->success('ok', $row);
    }

    /**
     * 人工保存（乐观锁：必须带 rev_no）
     */
    public function pageSave()
    {
        $id          = (int) $this->request->param('id/d', 0);
        $expectedRev = (int) $this->request->param('rev_no/d', 0);

        if ($expectedRev <= 0) {
            return $this->error('缺少 rev_no（或非法），请刷新页面后重试');
        }

        $data = [
            'title'      => (string) $this->request->param('title', ''),
            'content_md' => (string) $this->request->param('content_md', ''),
            'summary'    => (string) $this->request->param('summary', ''),
            'status'     => (string) $this->request->param('status/s', ''),
        ];

        if ($data['status'] === '') {
            unset($data['status']);
        }

        try {
            $result = app(PageService::class)->saveManual($id, $data, $expectedRev, $this->editorId());

            return $this->success('已保存（rev' . $result['rev_no'] . '）', $result);
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    public function pagePublish()
    {
        $id = (int) $this->request->param('id/d', 0);

        try {
            $result = app(PageService::class)->publish($id, $this->editorId());

            return $result['published']
                ? $this->success('已发布，已进入检索', $result)
                : $this->error('已标记发布，但影子库写入失败，请执行 wiki:doctor --apply');
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('发布失败：' . $e->getMessage());
        }
    }

    public function pageArchive()
    {
        $id = (int) $this->request->param('id/d', 0);

        try {
            return $this->success('已归档', app(PageService::class)->archive($id, $this->editorId()));
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('归档失败：' . $e->getMessage());
        }
    }

    public function pageDelete()
    {
        $id = (int) $this->request->param('id/d', 0);

        $ok = app(PageService::class)->delete($id);

        return $ok ? $this->success('已删除') : $this->error('页面不存在');
    }

    /**
     * 应用候选版本（LLM 重编译结果写回正文）
     */
    public function pageApply()
    {
        $id = (int) $this->request->param('id/d', 0);

        try {
            $result = app(PageService::class)->applyCandidate($id, $this->editorId());

            return $this->success('已应用候选版本（rev' . $result['rev_no'] . '）', $result);
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('应用失败：' . $e->getMessage());
        }
    }


    /**
     * 触发编译（单主题 / 单页）
     */
    public function compile()
    {
        $spaceId = (int) $this->request->param('space_id/d', 0);
        $topic   = trim((string) $this->request->param('topic', ''));
        $pageId  = (int) $this->request->param('page_id/d', 0);
        $kind    = (string) $this->request->param('kind/s', 'topic');

        if ($spaceId <= 0) {
            return $this->error('缺少 space_id');
        }
        if ($topic === '' && $pageId <= 0) {
            return $this->error('请提供 topic 或 page_id');
        }

        try {
            $result = app(CompileService::class)->compile(
                new CompileInput($spaceId, $topic, CompileInput::TRIGGER_MANUAL, $pageId, '', $kind)
            );

            if (($result['ok'] ?? false) === false) {
                error_log('[wise-llm-wiki] 编译未通过：' . json_encode($result, JSON_UNESCAPED_UNICODE));

                return $this->error('编译未通过：' . implode('；', (array) ($result['errors'] ?? [])));
            }

            if (!empty($result['skipped'])) {
                return $this->success('已跳过：' . (string) ($result['reason'] ?? ''), $result);
            }

            return $this->success('编译完成', $result);
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('编译失败：' . $e->getMessage());
        }
    }

    public function jobs()
    {
        $spaceId = (int) $this->request->param('space_id/d', 0);
        $status  = (string) $this->request->param('status/s', '');
        $limit   = min(200, max(1, (int) $this->request->param('limit/d', 50)));

        $query = Db::name('ai_wiki_compile_jobs');
        if ($spaceId > 0) {
            $query->where('space_id', $spaceId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->order('id', 'desc')->limit($limit)->select()->toArray();

        return $this->success('ok', ['list' => $rows, 'total' => count($rows)]);
    }

    /**
     * 重试失败任务（按任务快照重建一次编译请求）
     */
    public function jobRetry()
    {
        $id = (int) $this->request->param('id/d', 0);

        try {
            $result = app(CompileService::class)->retryJob($id);

            return ($result['ok'] ?? false) === false
                ? $this->error('重试仍未通过：' . implode('；', (array) ($result['errors'] ?? [])))
                : $this->success('重试完成', $result);
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('重试失败：' . $e->getMessage());
        }
    }


    /**
     * 检索调试：影子库（页面结论）与源库（原始碎片）分开展示
     *
     * 页面优先策略的吼�这里只做「看得见」的对照。
     */
    public function testRetrieval()
    {
        $spaceId = (int) $this->request->param('space_id/d', 0);
        $query   = trim((string) $this->request->param('query', ''));
        $topK    = min(20, max(1, (int) $this->request->param('top_k/d', 5)));

        if ($query === '') {
            return $this->error('请输入查询内容');
        }

        $space = app(SpaceService::class)->get($spaceId);
        if ($space === null) {
            return $this->error('空间不存在');
        }

        try {
            $bridge = app(RagBridge::class);
            $pages  = [];
            $chunks = [];

            if ((int) $space->shadow_kb_id > 0) {
                foreach ($bridge->retrieve($query, [(int) $space->shadow_kb_id], $topK) as $hit) {
                    $pages[] = $this->hitToArray($hit);
                }
            }

            if ((int) $space->source_kb_id > 0) {
                foreach ($bridge->retrieve($query, [(int) $space->source_kb_id], $topK) as $hit) {
                    $chunks[] = $this->hitToArray($hit);
                }
            }

            return $this->success('ok', [
                'pages'  => $pages,
                'chunks' => $chunks,
                'space'  => ['id' => (int) $space->id, 'space_key' => (string) $space->space_key],
            ]);
        } catch (WikiException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('检索失败：' . $e->getMessage());
        }
    }


    /**
     * @return array<string, mixed>
     */
    protected function counters(): array
    {
        return [
            'spaces'      => (int) Db::name('ai_wiki_spaces')->count(),
            'pages'       => (int) Db::name('ai_wiki_pages')->count(),
            'published'   => (int) Db::name('ai_wiki_pages')->where('status', WikiPage::STATUS_PUBLISHED)->count(),
            'review'      => (int) Db::name('ai_wiki_pages')->where('status', WikiPage::STATUS_REVIEW)->count(),
            'draft'       => (int) Db::name('ai_wiki_pages')->where('status', WikiPage::STATUS_DRAFT)->count(),
            'dirty'       => (int) Db::name('ai_wiki_pages')->where('dirty_reason', '<>', '')->count(),
            'jobs'        => (int) Db::name('ai_wiki_compile_jobs')->count(),
            'jobs_failed' => (int) Db::name('ai_wiki_compile_jobs')->where('status', 'failed')->count(),
        ];
    }

    /**
     * 当前后台用户 ID（作为人工编辑的 editor_id）
     */
    protected function editorId(): int
    {
        try {
            $admin = $this->request->admin ?? null;
            if (is_object($admin) && isset($admin->id)) {
                return (int) $admin->id;
            }

            $session = $this->request->session();
            if (is_object($session)) {
                $id = $session->get('admin_id') ?? $session->get('admin.id') ?? 0;

                return (int) $id;
            }
        } catch (\Exception $e) {
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function hitToArray(object $hit): array
    {
        return [
            'chunk_id' => (string) ($hit->chunkId ?? ''),
            'doc_id'   => (string) ($hit->docId ?? ''),
            'content'  => mb_substr((string) ($hit->content ?? ''), 0, 500),
            'score'    => round((float) ($hit->score ?? 0), 4),
            'cite'     => (array) ($hit->meta['cite'] ?? []),
        ];
    }
}
