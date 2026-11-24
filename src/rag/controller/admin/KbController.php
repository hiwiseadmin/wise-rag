<?php
declare(strict_types=1);

namespace wise\rag\controller\admin;

use think\facade\Db;
use wise\rag\dto\DocEntry;
use wise\rag\dto\RetrievalQuery;
use wise\rag\dto\ScoredChunk;
use wise\rag\service\IndexTaskService;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\support\ErrorPresenter;

/**
 * 知识库后台管理（包内控制器 + 包内视图）
 *
 * 正规链路（与 wise-agent / wise-health-check 一致）：
 *  - 继承宿主 {@see \app\admin\controller\BaseController}，复用后台通用模板变量；
 *  - initialize() 中 ViewScope::activate('wise-rag')，使 $this->fetch() 兜底到本包
 *    `view/admin/kb/*.html`（宿主 app/admin/view 仍可放同名文件覆盖）；
 *  - 视图 {extend name="__WISE_VIEW__/base"} 复用框架「包共用壳」。
 *
 * 权限：URL 与菜单声明（MenuProvider）派生的 admin.kb.* 一一对应，
 * 由 admin 应用全局 RBAC 中间件统一校验，控制器内不重复判断。
 */
class KbController extends \app\admin\controller\BaseController
{
    /** 视图 scope */
    protected const VIEW_SCOPE = 'wise-rag';

    /** 允许上传的扩展名 → 解析用 mime（与 wise-rag.parser.mapping 对齐，mime 由扩展名推导而非信任客户端） */
    protected const UPLOAD_MIME_BY_EXT = [
        'txt'      => 'text/plain',
        'text'     => 'text/plain',
        'log'      => 'text/plain',
        'csv'      => 'text/csv',
        'md'       => 'text/markdown',
        'markdown' => 'text/markdown',
        'html'     => 'text/html',
        'htm'      => 'text/html',
        'pdf'      => 'application/pdf',
    ];

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

    /**
     * 知识库管理首页
     */
    public function index()
    {
        return $this->fetch('admin/kb/index');
    }


    /**
     * 知识库列表
     */
    public function bases()
    {
        $rows = Db::name('ai_kb_knowledge_bases')->order('id', 'asc')->select()->toArray();

        $sourceCounts = array_count_values(
            array_map('intval', Db::name('ai_kb_sources')->column('kb_id'))
        );

        foreach ($rows as &$row) {
            $row['id']         = (int) $row['id'];
            $row['doc_count']  = (int) $row['doc_count'];
            $row['chunk_count'] = (int) $row['chunk_count'];
            $row['dimension']  = (int) $row['dimension'];
            $row['source_count'] = (int) ($sourceCounts[(int) $row['id']] ?? 0);
            $row['created_at'] = (string) $row['create_time'];
        }
        unset($row);

        return $this->success('ok', ['list' => $rows, 'total' => count($rows)]);
    }

    /**
     * 新建 / 更新知识库
     */
    public function baseSave()
    {
        $service = app(KnowledgeBaseService::class);
        $id      = $this->request->param('id/d', 0);
        $kbKey   = trim((string) $this->request->param('kb_key/s', ''));
        $name    = trim((string) $this->request->param('name/s', ''));

        if ($name === '') {
            return $this->error('名称不能为空');
        }

        try {
            if ($id > 0) {
                $kb = $service->get($id);
                if ($kb === null) {
                    return $this->error('知识库不存在');
                }

                $kb->save([
                    'name'        => $name,
                    'description' => (string) $this->request->param('description/s', ''),
                    'visibility'  => (string) $this->request->param('visibility/s', 'private'),
                ]);

                return $this->success('已保存', ['id' => $id]);
            }

            $created = $service->create([
                'kb_key'      => $kbKey !== '' ? $kbKey : ('kb_' . date('YmdHis')),
                'name'        => $name,
                'description' => (string) $this->request->param('description/s', ''),
                'visibility'  => (string) $this->request->param('visibility/s', 'private'),
                'embedding'   => (string) $this->request->param('embedding/s', ''),
                'vector_store' => (string) $this->request->param('vector_store/s', ''),
            ]);

            return $this->success('已创建', ['id' => (int) $created->id]);
        } catch (\Exception $e) {
            return $this->error(ErrorPresenter::message($e));
        }
    }

    /**
     * 删除知识库（连带索引）
     */
    public function baseDelete()
    {
        $id = $this->request->param('id/d', 0);

        $ok = app(KnowledgeBaseService::class)->delete($id, true);

        return $ok ? $this->success('已删除') : $this->error('知识库不存在');
    }

    /**
     * 重建索引
     */
    public function baseReindex()
    {
        $id    = $this->request->param('id/d', 0);
        $async = (bool) $this->request->param('async/d', 0);
        $force = (bool) $this->request->param('force/d', 0);

        if (!$this->request->has('async')) {
            $async = (bool) config('wise-rag.ingestion.async', false);
        }

        try {
            $res = app(IndexTaskService::class)->run($id, null, $async, $force);

            return $this->success(
                !empty($res['async']) ? '已入队，等待 worker 处理' : '索引完成',
                $res
            );
        } catch (\Exception $e) {
            return $this->error(ErrorPresenter::message($e));
        }
    }


    /**
     * 文档列表（分页）
     */
    public function documents()
    {
        $kbId    = $this->request->param('kb_id/d', 0);
        $status  = (string) $this->request->param('status/s', '');
        $keyword = trim((string) $this->request->param('keyword/s', ''));
        $page    = max(1, $this->request->param('page/d', 1));
        $limit   = min(100, max(1, $this->request->param('limit/d', 15)));

        $query = Db::name('ai_kb_documents');
        if ($kbId > 0) {
            $query->where('kb_id', $kbId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($keyword !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $query->whereLike('title', '%' . $escaped . '%');
        }

        $total = (int) (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $limit)
            ->field('id,kb_id,title,mime,status,chunk_count,char_count,create_time,external_id')
            ->select()
            ->toArray();

        return $this->success('ok', ['list' => $rows, 'total' => $total]);
    }

    /**
     * 上传文件并索引
     */
    public function documentUpload()
    {
        $kbId = $this->request->param('kb_id/d', 0);
        $kb   = app(KnowledgeBaseService::class)->get($kbId);
        if ($kb === null) {
            return $this->error('知识库不存在');
        }

        $file = $this->request->file('file');
        if ($file === null) {
            return $this->error('未接收到上传文件');
        }

        $maxSize = (int) config('wise-rag.parser.max_file_size', 20 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            return $this->error('文件超过大小上限 ' . round($maxSize / 1048576, 1) . ' MB');
        }

        $ext = strtolower((string) $file->getOriginalExtension());
        if (!isset(self::UPLOAD_MIME_BY_EXT[$ext])) {
            return $this->error('不支持的文件类型：' . ($ext !== '' ? $ext : '未知'));
        }

        $dir = app()->getRuntimePath() . 'wise-rag' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->error('上传目录创建失败');
        }

        try {
            $saved = $file->move($dir);
            if ($saved === false) {
                return $this->error('文件保存失败');
            }

            $path = $saved->getPathname();
            $mime = self::UPLOAD_MIME_BY_EXT[$ext];
            $doc  = app(IngestionService::class)->ingest($kb, new DocEntry(
                'upload:' . md5_file($path),
                (string) $file->getOriginalName(),
                null,
                $path,
                ['mime' => $mime, 'title' => (string) $file->getOriginalName()]
            ));

            return $this->success('索引完成', $doc);
        } catch (\Exception $e) {
            return $this->error('索引失败：' . ErrorPresenter::message($e));
        }
    }

    /**
     * 直接灌入文本并索引
     */
    public function documentIngest()
    {
        $kbId = $this->request->param('kb_id/d', 0);
        $kb   = app(KnowledgeBaseService::class)->get($kbId);
        if ($kb === null) {
            return $this->error('知识库不存在');
        }

        $text  = (string) $this->request->param('content/s', '');
        $title = trim((string) $this->request->param('title/s', ''));
        if (trim($text) === '') {
            return $this->error('内容不能为空');
        }

        try {
            $docId = app(IngestionService::class)->ingestText($kb, $text, [
                'title'       => $title,
                'external_id' => 'text:' . md5($text),
            ]);

            return $this->success('索引完成', ['doc_id' => $docId]);
        } catch (\Exception $e) {
            return $this->error('索引失败：' . ErrorPresenter::message($e));
        }
    }

    /**
     * 删除文档
     */
    public function documentDelete()
    {
        $docId = $this->request->param('id/d', 0);

        $ok = app(IngestionService::class)->deleteDocument($docId);

        return $ok ? $this->success('已删除') : $this->error('文档不存在');
    }


    /**
     * 知识源列表
     */
    public function sourceList()
    {
        $kbId = $this->request->param('kb_id/d', 0);

        $query = Db::name('ai_kb_sources');
        if ($kbId > 0) {
            $query->where('kb_id', $kbId);
        }

        $rows = $query->order('id', 'asc')->select()->toArray();

        $kbKeys = Db::name('ai_kb_knowledge_bases')->column('kb_key', 'id');
        foreach ($rows as &$row) {
            $row['kb_key']   = (string) ($kbKeys[(int) $row['kb_id']] ?? '');
            $row['provider'] = (string) $row['provider'];
            $row['last_sync_label'] = $row['last_sync'] ? date('Y-m-d H:i:s', (int) $row['last_sync']) : '—';
        }
        unset($row);

        return $this->success('ok', ['list' => $rows, 'total' => count($rows)]);
    }

    /**
     * 同步单个知识源
     */
    public function sourceSync()
    {
        $sourceId = $this->request->param('id/d', 0);
        $async    = (bool) $this->request->param('async/d', 0);
        $force    = (bool) $this->request->param('force/d', 0);

        if (!$this->request->has('async')) {
            $async = (bool) config('wise-rag.ingestion.async', false);
        }

        try {
            $res = app(IndexTaskService::class)->run(null, $sourceId, $async, $force);

            return $this->success(!empty($res['async']) ? '已入队' : '同步完成', $res);
        } catch (\Exception $e) {
            return $this->error(ErrorPresenter::message($e));
        }
    }


    /**
     * 调试检索
     */
    public function testRetrieval()
    {
        $query = trim((string) $this->request->param('query/s', ''));
        $kbId  = $this->request->param('kb_id/d', 0);
        $topK  = min(20, max(1, $this->request->param('top_k/d', 5)));
        $hybrid = (bool) $this->request->param('hybrid/d', 1);

        if ($query === '') {
            return $this->error('请输入查询内容');
        }

        try {
            $hits = app(RetrievalService::class)->retrieve(new RetrievalQuery(
                $query,
                null,
                $kbId > 0 ? [$kbId] : [],
                $topK,
                [],
                $hybrid
            ));

            $list = array_map(static function (ScoredChunk $hit): array {
                $cite = (array) ($hit->meta['cite'] ?? []);

                return [
                    'chunk_id'     => $hit->chunkId,
                    'doc_id'       => $hit->docId,
                    'content'      => $hit->content,
                    'score'        => round($hit->score, 4),
                    'vector_score' => round($hit->vectorScore, 4),
                    'keyword_score' => round($hit->keywordScore, 4),
                    'cite'         => $cite,
                ];
            }, $hits);

            return $this->success('ok', ['list' => $list, 'total' => count($list)]);
        } catch (\Exception $e) {
            return $this->error('检索失败：' . ErrorPresenter::message($e));
        }
    }

    /**
     * 统计概览
     */
    public function stats()
    {
        return $this->success('ok', app(KnowledgeBaseService::class)->overview());
    }

}
