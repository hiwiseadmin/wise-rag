<?php
declare(strict_types=1);

namespace wise\rag\service;

use think\facade\Db;

/**
 * 损坏向量修复
 *
 * 背景：`ai_kb_chunks.vector` 曾被建为**定长** `BINARY(255)`（Phinx 的 `binary` 类型默认
 * limit=255），写入的 float32 序列化结果会被 0x00 补齐（维度 ≤ 63）或被截断（维度 > 63），
 * `unpack()` 出的分量数与 `dimension` 记录不符，检索侧因此拿不到有效相似度。
 *
 * 列类型已修正为 `BLOB`，但历史数据无法自动还原，只能：
 *   1. 识别「序列化长度 ≠ dimension × 4」的行（{@see self::BROKEN_PREDICATE}）；
 *   2. 置空其 vector / dimension —— 检索侧的 `whereNotNull('vector')` 会自动跳过；
 *   3. 把受影响文档标记为 error。
 *
 * 第 3 步不可省略：`IngestionService::doIngest()` 的幂等判断是
 * 「content_hash 相同 **且** status=indexed 则跳过」，若不改状态，重建索引会被跳过，
 * 向量永远补不回来。
 *
 * 另：修复会减少「有向量的分块数」，因此**必须同时刷新受影响知识库的计数**，
 * 否则统计概览的 vectors（取自 `knowledge_bases.vector_count` 求和）会持续虚高。
 */
class VectorRepairService
{
    /** 判定「向量不可用」的 SQL 片段：长度与维度不符，或维度缺失 */
    public const BROKEN_PREDICATE = '(dimension IS NULL OR OCTET_LENGTH(vector) <> dimension * 4)';

    /** 受影响文档的标记原因 */
    public const REPAIR_REASON = '向量长度与维度不符（历史定长列类型导致），已清空向量，请重建索引';

    public function __construct(protected KnowledgeBaseService $kbService)
    {
    }

    /**
     * 扫描损坏向量（只读，供预览）
     *
     * @param int|null $kbId 限定知识库（null = 全库）
     * @return array{chunks:int, cache:int, documents:int, kbs:int}
     */
    public function inspect(?int $kbId = null): array
    {
        return [
            'chunks'    => $this->countBrokenChunks($kbId),
            'cache'     => $this->countBrokenCache(),
            'documents' => count($this->brokenDocIds($kbId)),
            'kbs'       => count($this->brokenKbIds($kbId)),
        ];
    }

    /**
     * 清空损坏向量 + 标记受影响文档 + 刷新受影响知识库计数
     *
     * @param int|null $kbId 限定知识库（null = 全库）
     * @return array{chunks:int, cache:int, documents:int, kbs:int}
     */
    public function repair(?int $kbId = null): array
    {
        $now    = date('Y-m-d H:i:s');
        $docIds = $this->brokenDocIds($kbId);
        $kbIds  = $this->brokenKbIds($kbId);
        $chunks = $this->countBrokenChunks($kbId);

        if ($chunks > 0) {
            $query = Db::name('ai_kb_chunks')->whereNotNull('vector')->whereRaw(self::BROKEN_PREDICATE);
            if ($kbId !== null) {
                $query->where('kb_id', $kbId);
            }

            $query->update(['vector' => null, 'dimension' => null, 'update_time' => $now]);
        }

        $documents = 0;
        foreach (array_chunk($docIds, 500) as $ids) {
            $documents += (int) Db::name('ai_kb_documents')->whereIn('id', $ids)->update([
                'status'      => 'error',
                'error'       => self::REPAIR_REASON,
                'update_time' => $now,
            ]);
        }

        $cache = $this->countBrokenCache();
        if ($cache > 0) {
            try {
                Db::name('ai_kb_embedding_cache')
                    ->whereNotNull('vector')
                    ->whereRaw(self::BROKEN_PREDICATE)
                    ->delete();
            } catch (\Exception $e) {
                $cache = 0;
            }
        }

        foreach ($kbIds as $affectedKbId) {
            $this->kbService->refreshStats($affectedKbId);
        }

        return ['chunks' => $chunks, 'cache' => $cache, 'documents' => $documents, 'kbs' => count($kbIds)];
    }

    /**
     * 受影响文档 ID（排除 doc_id=0 的游离分块）
     *
     * @return int[]
     */
    protected function brokenDocIds(?int $kbId): array
    {
        try {
            $query = Db::name('ai_kb_chunks')
                ->whereNotNull('vector')
                ->whereRaw(self::BROKEN_PREDICATE)
                ->where('doc_id', '>', 0);

            if ($kbId !== null) {
                $query->where('kb_id', $kbId);
            }

            return array_values(array_unique(array_map('intval', $query->column('doc_id'))));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 受影响知识库 ID（用于计数刷新）
     *
     * @return int[]
     */
    protected function brokenKbIds(?int $kbId): array
    {
        try {
            $query = Db::name('ai_kb_chunks')
                ->whereNotNull('vector')
                ->whereRaw(self::BROKEN_PREDICATE)
                ->where('kb_id', '>', 0);

            if ($kbId !== null) {
                $query->where('kb_id', $kbId);
            }

            return array_values(array_unique(array_map('intval', $query->column('kb_id'))));
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function countBrokenChunks(?int $kbId): int
    {
        try {
            $query = Db::name('ai_kb_chunks')->whereNotNull('vector')->whereRaw(self::BROKEN_PREDICATE);
            if ($kbId !== null) {
                $query->where('kb_id', $kbId);
            }

            return (int) $query->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function countBrokenCache(): int
    {
        try {
            return (int) Db::name('ai_kb_embedding_cache')
                ->whereNotNull('vector')
                ->whereRaw(self::BROKEN_PREDICATE)
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
