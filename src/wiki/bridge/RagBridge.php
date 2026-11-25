<?php
declare(strict_types=1);

namespace wise\wiki\bridge;

use think\facade\Db;
use wise\rag\dto\RetrievalQuery;
use wise\rag\exception\RagException;
use wise\rag\model\KnowledgeBase;
use wise\rag\service\IngestionService;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\tool\KnowledgeToolRegistrar;
use wise\wiki\exception\WikiException;

/**
 * 对 wise-rag 的**唯一**调用收口
 *
 * 存在理由（设计文档决策 #5）：wise-rag 升级若改动公开签名，本包只需改这一个文件。
 * 因此：
 *  - 全仓禁止在 bridge/ 之外出现 `wise\rag\*` 的调用（verify-contracts 会 grep 断言）；
 *  - 全仓禁止在 bridge/ 之外直接 `Db::name('ai_kb_*')`；
 *  - 上游异常一律包装为 WikiException，上层不感知 RagException。
 */
class RagBridge
{
    public function __construct(
        protected KnowledgeBaseService $kbService,
        protected RetrievalService $retrieval,
        protected IngestionService $ingestion
    ) {
    }

    /**
     * 依赖是否可用（未安装 wise-rag 时所有方法给可读错误而非 Class not found）
     */
    public static function available(): bool
    {
        return class_exists(KnowledgeBaseService::class) && interface_exists(\wise\rag\contract\VectorStoreInterface::class);
    }

    /**
     * 监听 wise-rag 的「文档索引完成」事件
     *
     * 放在 bridge 里而不是 Provider：ServiceProvider 不允许出现 `wise\rag\*` 的类型引用
     * （契约自检会 grep 断言只有 bridge/ 依赖上游），这里用类常量做守卫，wise-rag 缺失时返回 false。
     */
    public static function listenDocumentIndexed(callable $handler): bool
    {
        if (!class_exists(\wise\rag\event\DocumentIndexed::class) || !class_exists(\think\facade\Event::class)) {
            return false;
        }

        try {
            \think\facade\Event::listen(\wise\rag\event\DocumentIndexed::class, $handler);

            return true;
        } catch (\Throwable $e) {
            error_log('[wise-llm-wiki] 注册文档索引事件监听失败: ' . $e->getMessage());

            return false;
        }
    }

    public function sourceKb(int $kbId): ?KnowledgeBase
    {
        $this->assertAvailable();

        return $this->kbService->get($kbId);
    }

    public function findKbByKey(string $kbKey): ?KnowledgeBase
    {
        $this->assertAvailable();

        return $this->kbService->findByKey($kbKey);
    }

    /**
     * 知识库清单（后台「新建空间」的源库下拉）
     *
     * @return array<int, array{id:int, kb_key:string, name:string, embedding:string, dimension:int, doc_count:int}>
     */
    public function knowledgeBases(bool $onlyActive = true): array
    {
        $this->assertAvailable();

        $out = [];
        foreach ($this->kbService->all($onlyActive) as $kb) {
            $out[] = [
                'id'        => (int) $kb->id,
                'kb_key'    => (string) $kb->kb_key,
                'name'      => (string) $kb->name,
                'embedding' => (string) $kb->embedding,
                'dimension' => (int) $kb->dimension,
                'doc_count' => (int) $kb->doc_count,
            ];
        }

        return $out;
    }

    /**
     * 建影子知识库，返回知识库 ID
     *
     * @param array<string, mixed> $options name / description / visibility / owner_type / owner_id / embedding
     */
    public function createKb(string $kbKey, array $options = []): int
    {
        $this->assertAvailable();

        try {
            $kb = $this->kbService->create(['kb_key' => $kbKey] + $options);

            return (int) $kb->id;
        } catch (RagException $e) {
            throw WikiException::invalidArgument('创建影子知识库失败：' . $e->getMessage());
        }
    }

    /**
     * 删除知识库（含索引）
     */
    public function deleteKb(int $kbId): bool
    {
        $this->assertAvailable();

        return $this->kbService->delete($kbId, true);
    }

    /**
     * 检索（**kbIds 白名单**是本包自循环隔离的唯一实现手段）
     *
     * @param int[] $kbIds 必须显式传入；编译取料时只能是源库
     * @return \wise\rag\dto\ScoredChunk[]
     */
    public function retrieve(string $text, array $kbIds, int $topK): array
    {
        $this->assertAvailable();

        if ($kbIds === []) {
            throw WikiException::invalidArgument('retrieve() 必须显式指定 kbIds（禁止全库检索，避免读到 wiki 自身产物）');
        }

        try {
            return $this->retrieval->retrieve(new RetrievalQuery($text, null, $kbIds, $topK));
        } catch (RagException $e) {
            throw WikiException::invalidArgument('检索失败：' . $e->getMessage());
        }
    }

    /**
     * 把页面正文灌入影子知识库（幂等：external_id 为唯一键）
     *
     * @param array<string, mixed> $meta external_id / title / url
     */
    public function publishText(KnowledgeBase $kb, string $text, array $meta = []): int
    {
        $this->assertAvailable();

        try {
            return (int) $this->ingestion->ingestText($kb, $text, $meta);
        } catch (RagException $e) {
            throw WikiException::invalidArgument('发布到影子知识库失败：' . $e->getMessage());
        }
    }

    public function deleteDocument(int $docId): bool
    {
        $this->assertAvailable();

        return $this->ingestion->deleteDocument($docId);
    }

    /**
     * 批量取源文档内容 hash（增量重编译的判定依据）
     *
     * 说明：这是本包**唯一**读 `ai_kb_*` 表的地方。wise-rag 未暴露「按 id 批量取 content_hash」
     * 的服务方法，而 doc_hash 又是 R-02（--force 全量重编译）的唯一防线，因此在这里收口；
     * 契约自检会断言 `Db::name('ai_kb_` 只出现在 bridge/ 内。
     *
     * @param int[] $docIds
     * @return array<int, string> doc_id => content_hash
     */
    public function docHashes(array $docIds): array
    {
        if (!self::available()) {
            throw WikiException::ragUnavailable();
        }

        $docIds = array_values(array_unique(array_filter(array_map('intval', $docIds))));
        if ($docIds === []) {
            return [];
        }

        try {
            $rows = Db::name('ai_kb_documents')
                ->whereIn('id', $docIds)
                ->column('content_hash', 'id');
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] 读取文档 hash 失败: ' . $e->getMessage());

            return [];
        }

        $out = [];
        foreach ($rows as $id => $hash) {
            $out[(int) $id] = (string) $hash;
        }

        return $out;
    }

    /**
     * 影子文档快照（体检用：判断「文档还在不在」「内容是否与页面一致」）
     *
     * 说明：wise-rag 的 ingestText 不透传 `meta`，因此无法在文档上记录页面 rev；
     * 但它的 `content_hash` 是 `md5(trim(text))`（`IngestionService::doIngest` 第 153 行），
     * 本包可直接复算比对 —— 比「字符数相等」精确得多（等长不同内容也能检出）。
     *
     * @param int[] $docIds
     * @return array<int, array{char_count:int, content_hash:string, external_id:string, status:string}>
     */
    public function documentsSnapshot(array $docIds): array
    {
        if (!self::available()) {
            throw WikiException::ragUnavailable();
        }

        $docIds = array_values(array_unique(array_filter(array_map('intval', $docIds))));
        if ($docIds === []) {
            return [];
        }

        try {
            $rows = Db::name('ai_kb_documents')
                ->whereIn('id', $docIds)
                ->field('id,char_count,content_hash,external_id,status')
                ->select()
                ->toArray();
        } catch (\Exception $e) {
            throw WikiException::invalidArgument('读取影子文档失败：' . $e->getMessage());
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = [
                'char_count'   => (int) $row['char_count'],
                'content_hash' => (string) $row['content_hash'],
                'external_id'  => (string) $row['external_id'],
                'status'       => (string) $row['status'],
            ];
        }

        return $out;
    }

    /**
     * 取源知识库的高频检索词（选题用）
     *
     * 依赖 wise-rag 的 `ai_kb_retrieval_logs`（需 `retrieval.log_enabled = true` 才有数据；
     * 无数据时返回空数组，选题自然退化为「人工主题清单」）。
     *
     * @return array<int, array{query:string, hits:int}>
     */
    public function topQueries(int $kbId, int $days = 30, int $minHits = 5, int $limit = 20): array
    {
        if (!self::available() || $kbId <= 0) {
            return [];
        }

        try {
            $rows = Db::name('ai_kb_retrieval_logs')
                ->where('kb_id', $kbId)
                ->where('create_time', '>=', date('Y-m-d H:i:s', time() - max(1, $days) * 86400))
                ->field('query, COUNT(*) AS hits')
                ->group('query')
                ->having('hits >= ' . max(1, $minHits))
                ->order('hits', 'desc')
                ->limit(max(1, $limit))
                ->select()
                ->toArray();
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] 读取检索日志失败: ' . $e->getMessage());

            return [];
        }

        return array_map(
            static fn (array $r): array => ['query' => (string) $r['query'], 'hits' => (int) $r['hits']],
            $rows
        );
    }

    public function refreshStats(int $kbId): void
    {
        $this->assertAvailable();

        $this->kbService->refreshStats($kbId);
    }

    /**
     * 影子库增删后刷新 wise-agent 的工具缓存（未安装 wise-agent 时无副作用）
     */
    public function flushToolCache(): void
    {
        if (!class_exists(KnowledgeToolRegistrar::class)) {
            return;
        }

        KnowledgeToolRegistrar::flushToolCache();
    }

    private function assertAvailable(): void
    {
        if (!self::available()) {
            throw WikiException::ragUnavailable();
        }
    }
}
