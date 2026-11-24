<?php
declare(strict_types=1);

namespace wise\rag\service;

use think\facade\Db;
use wise\rag\client\EmbeddingFactory;
use wise\rag\dto\Chunk;
use wise\rag\dto\DocEntry;
use wise\rag\dto\IngestionContext;
use wise\rag\dto\ParsedDocument;
use wise\rag\dto\VectorPoint;
use wise\rag\event\DocumentIndexed;
use wise\rag\event\IndexFailed;
use wise\rag\exception\RagException;
use wise\rag\model\KnowledgeBase;
use wise\rag\parser\ParserRegistry;
use wise\rag\source\SourceFactory;
use wise\rag\splitter\SplitterFactory;
use wise\rag\support\EventBus;
use wise\rag\vector\VectorFactory;

/**
 * 摄取服务：拉取 → 解析 → 分块 → 向量化 → 入库（幂等 / 增量）
 */
class IngestionService
{
    public function __construct(
        protected ParserRegistry $parsers,
        protected SplitterFactory $splitterFactory,
        protected EmbeddingFactory $embeddingFactory,
        protected VectorFactory $vectorFactory,
        protected KnowledgeBaseService $kbService,
        protected SourceFactory $sourceFactory
    ) {
    }

    /**
     * 直接灌入纯文本，返回文档 ID
     */
    public function ingestText(KnowledgeBase $kb, string $text, array $meta = []): int
    {
        $entry = new DocEntry(
            (string) ($meta['external_id'] ?? ('text:' . md5($text))),
            (string) ($meta['title'] ?? ''),
            $text,
            null,
            $meta
        );

        return (int) $this->ingest($kb, $entry)['doc_id'];
    }

    /**
     * 摄取一个条目（文本或文件）—— 事件包装层
     *
     * @return array{doc_id:int, chunk_count:int, skipped:bool}
     * @throws RagException
     */
    public function ingest(KnowledgeBase $kb, DocEntry $entry): array
    {
        try {
            $result = $this->doIngest($kb, $entry);

            if (empty($result['skipped'])) {
                EventBus::trigger(new DocumentIndexed(
                    (int) $kb->id,
                    (int) $result['doc_id'],
                    (string) $entry->externalId,
                    (int) $result['chunk_count']
                ));

                if (empty($entry->meta['source_id'])) {
                    $this->kbService->refreshStats((int) $kb->id);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->markFailed($kb, $entry, $e);

            EventBus::trigger(new IndexFailed(
                (int) $kb->id,
                (string) $entry->externalId,
                'ingest',
                $e->getMessage()
            ));

            throw $e;
        }
    }

    /**
     * 写入失败状态与原因
     *
     * 独立 try 保护：落库失败不得掩盖原始异常。
     * 文档记录尚不存在时（解析阶段即失败）更新影响 0 行，属预期行为。
     */
    protected function markFailed(KnowledgeBase $kb, DocEntry $entry, \Throwable $e): void
    {
        try {
            Db::name('ai_kb_documents')
                ->where('kb_id', (int) $kb->id)
                ->where('external_id', $entry->externalId)
                ->update([
                    'status'      => 'error',
                    'error'       => mb_substr($e->getMessage(), 0, 500),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $ignore) {
            error_log('[wise-rag] 写入失败状态异常: ' . $ignore->getMessage());
        }
    }

    /**
     * 实际摄取逻辑：拉取 → 解析 → 分块 → 向量化 → 入库
     *
     * @return array{doc_id:int, chunk_count:int, skipped:bool}
     * @throws RagException
     */
    protected function doIngest(KnowledgeBase $kb, DocEntry $entry): array
    {
        $client = $this->embeddingFactory->make($kb->embedding);
        $this->assertDimension($kb, $client->getDimension());

        $title = $entry->title;
        if ($entry->text !== null) {
            $text = $entry->text;
        } elseif ($entry->filePath !== null) {
            $parsed = $this->parsers->parse($entry->filePath, (string) ($entry->meta['mime'] ?? ''));
            $text   = $parsed->text;
            if ($title === '') {
                $title = $parsed->title;
            }
        } else {
            throw new RagException('DocEntry 必须提供 text 或 filePath');
        }

        $text = trim($text);
        if ($text === '') {
            throw new RagException('文档内容为空，无法索引');
        }

        $hash = md5($text);
        $now  = date('Y-m-d H:i:s');

        $existing = Db::name('ai_kb_documents')
            ->where('kb_id', (int) $kb->id)
            ->where('external_id', $entry->externalId)
            ->find();

        if (is_array($existing)
            && (string) $existing['content_hash'] === $hash
            && (string) $existing['status'] === 'indexed'
        ) {
            return [
                'doc_id'      => (int) $existing['id'],
                'chunk_count' => (int) $existing['chunk_count'],
                'skipped'     => true,
            ];
        }

        $ownerType = (string) ($entry->meta['owner_type'] ?? $kb->owner_type);
        $ownerId   = (int) ($entry->meta['owner_id'] ?? $kb->owner_id);

        $chunkOptions = $this->chunkOptions($kb, (array) ($entry->meta['chunk'] ?? []));
        $splitter     = $this->splitterFactory->make((string) ($chunkOptions['splitter'] ?? ''));
        $chunks       = $splitter->split(new ParsedDocument($text, $title), $chunkOptions);

        $docData = [
            'kb_id'        => (int) $kb->id,
            'source_id'    => (int) ($entry->meta['source_id'] ?? 0),
            'external_id'  => $entry->externalId,
            'title'        => $title !== '' ? $title : mb_substr($text, 0, 60),
            'mime'         => (string) ($entry->meta['mime'] ?? ''),
            'file_path'    => $entry->filePath,
            'url'          => (string) ($entry->meta['url'] ?? ''),
            'content_hash' => $hash,
            'char_count'   => mb_strlen($text),
            'owner_type'   => $ownerType,
            'owner_id'     => $ownerId,
            'status'       => $chunks === [] ? 'indexed' : 'parsed',
            'chunk_count'  => count($chunks),
            'update_time'  => $now,
        ];

        $docId = Db::transaction(function () use ($existing, $docData, $now, $chunks, $kb, $ownerType, $ownerId) {
            if (is_array($existing)) {
                $docId = (int) $existing['id'];
                Db::name('ai_kb_documents')->where('id', $docId)->update($docData);
            } else {
                $docData['create_time'] = $now;
                $docId                  = (int) Db::name('ai_kb_documents')->insertGetId($docData);
            }

            Db::name('ai_kb_chunks')->where('doc_id', $docId)->delete();

            foreach (array_chunk($chunks, 500) as $batch) {
                Db::name('ai_kb_chunks')->insertAll(
                    $this->buildChunkRows($kb, $docId, $batch, $ownerType, $ownerId, $now)
                );
            }

            return $docId;
        });

        if ($chunks === []) {
            return ['doc_id' => $docId, 'chunk_count' => 0, 'skipped' => false];
        }

        $idMap         = Db::name('ai_kb_chunks')->where('doc_id', $docId)->column('id', 'chunk_index');
        $store         = $this->vectorFactory->make($kb->vector_store);
        $realDimension = 0;
        $skippedChunks = 0;

        foreach (array_chunk($chunks, 64) as $slice) {
            $vectors = $client->embed(array_map(static fn (Chunk $c): string => $c->content, $slice));

            if ($realDimension === 0 && isset($vectors[0]) && $vectors[0] !== []) {
                $realDimension = count($vectors[0]);
                $this->assertRealDimension($kb, $realDimension);
            }

            $points = [];
            foreach ($slice as $offset => $chunk) {
                $vector = $vectors[$offset] ?? null;
                if ($vector === null || $vector === []) {
                    $skippedChunks++;
                    continue;
                }

                $chunkId = $idMap[$chunk->index] ?? $idMap[(string) $chunk->index] ?? null;
                if ($chunkId === null) {
                    $skippedChunks++;
                    continue;
                }

                $points[] = new VectorPoint((string) $chunkId, $vector, [
                    'kb_id'      => (int) $kb->id,
                    'doc_id'     => $docId,
                    'owner_type' => $ownerType,
                    'owner_id'   => $ownerId,
                ]);
            }

            if ($points !== []) {
                $store->upsert($kb->collection, $points);
            }
        }

        Db::transaction(static function () use ($docId, $chunks): void {
            Db::name('ai_kb_documents')->where('id', $docId)->update([
                'status'      => 'indexed',
                'chunk_count' => count($chunks),
                'error'       => null,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        });

        if ($skippedChunks > 0) {
            error_log(sprintf(
                '[wise-rag] 文档 #%d 有 %d/%d 个分块未写入向量（上游返回缺失），检索将跳过这些分块',
                $docId,
                $skippedChunks,
                count($chunks)
            ));
        }

        return ['doc_id' => $docId, 'chunk_count' => count($chunks), 'skipped' => false];
    }

    /**
     * 删除文档及其分块向量
     */
    public function deleteDocument(int $docId): bool
    {
        $doc = Db::name('ai_kb_documents')->where('id', $docId)->find();
        if (!is_array($doc)) {
            return false;
        }

        $chunkIds = array_map('intval', Db::name('ai_kb_chunks')->where('doc_id', $docId)->column('id'));
        $kb       = $this->kbService->get((int) $doc['kb_id']);

        if ($kb !== null && $chunkIds !== []) {
            try {
                $this->vectorFactory->make($kb->vector_store)->delete($kb->collection, $chunkIds);
            } catch (\Exception $e) {
                error_log(sprintf(
                    '[wise-rag] 清理外部向量失败（kb=%d, doc=%d）：%s',
                    (int) $kb->id,
                    $docId,
                    $e->getMessage()
                ));
            }
        }

        Db::name('ai_kb_chunks')->where('doc_id', $docId)->delete();
        Db::name('ai_kb_documents')->where('id', $docId)->delete();

        $filePath = (string) ($doc['file_path'] ?? '');
        if ($filePath !== '' && is_file($filePath)) {
            @unlink($filePath);
        }

        $this->kbService->refreshStats((int) $doc['kb_id']);

        return true;
    }

    /**
     * 索引单个知识源（拉取 → 逐条摄取 → 更新水位）
     *
     * @return array{source_id:int, source_key:string, documents:int, chunks:int}
     * @throws RagException
     */
    public function ingestSource(int $sourceId, bool $force = false): array
    {
        $row = Db::name('ai_kb_sources')->where('id', $sourceId)->find();
        if (!is_array($row)) {
            throw new RagException("知识源不存在：{$sourceId}");
        }

        $kb = $this->kbService->get((int) $row['kb_id']);
        if ($kb === null) {
            throw new RagException("知识源所属知识库不存在：{$row['kb_id']}");
        }

        $source    = $this->sourceFactory->make($row);
        $checkpoint = (string) ($row['checkpoint'] ?? '');

        $ctx = new IngestionContext(
            (int) $row['kb_id'],
            (string) $row['source_key'],
            $force || $checkpoint === '' ? null : $checkpoint,
            $this->batchSize(),
            ['source_id' => $sourceId]
        );

        $documents = 0;
        $chunks    = 0;

        foreach ($source->fetch($ctx) as $entry) {
            if (!$entry instanceof DocEntry) {
                continue;
            }

            $entry->meta['source_id'] = $sourceId;
            $result                   = $this->ingest($kb, $entry);

            $documents++;
            $chunks += (int) $result['chunk_count'];
        }

        Db::name('ai_kb_sources')->where('id', $sourceId)->update([
            'checkpoint'  => (string) ($source->getCheckpoint() ?? ''),
            'last_sync'   => time(),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->kbService->refreshStats((int) $kb->id);

        return [
            'source_id'  => $sourceId,
            'source_key' => (string) $row['source_key'],
            'documents'  => $documents,
            'chunks'     => $chunks,
        ];
    }

    /**
     * 索引知识库下的全部启用知识源
     *
     * @return array{kb_id:int, sources:int, details:array<int, array<string, mixed>>}
     */
    public function ingestKnowledgeBase(int $kbId, bool $force = false): array
    {
        $rows = Db::name('ai_kb_sources')
            ->where('kb_id', $kbId)
            ->where('status', 'active')
            ->select()
            ->toArray();

        $details = [];
        foreach ($rows as $row) {
            $details[] = $this->ingestSource((int) $row['id'], $force);
        }

        $this->kbService->refreshStats($kbId);

        return ['kb_id' => $kbId, 'sources' => count($details), 'details' => $details];
    }

    /**
     * 单批拉取条数
     */
    protected function batchSize(): int
    {
        $size = (int) (function_exists('config') ? config('wise-rag.ingestion.batch_size', 500) : 500);

        return max(1, $size);
    }

    /**
     * 合并分块参数：全局默认 ← 知识库覆盖 ← 来源覆盖
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function chunkOptions(KnowledgeBase $kb, array $extra = []): array
    {
        $defaults = function_exists('config') ? (array) config('wise-rag.chunk', []) : [];

        return array_merge(
            ['size' => 500, 'overlap' => 80, 'min_chars' => 20, 'splitter' => 'structure'],
            $defaults,
            $kb->getChunkConfig(),
            $extra
        );
    }

    /**
     * 构造分块写入行（批量 insertAll 用）
     *
     * 单批传入（而非一次性构造全部分块行），使峰值内存只与本批大小相关。
     *
     * @param Chunk[] $chunks
     * @return array<int, array<string, mixed>>
     */
    protected function buildChunkRows(
        KnowledgeBase $kb,
        int $docId,
        array $chunks,
        string $ownerType,
        int $ownerId,
        string $now
    ): array {
        $rows = [];
        foreach ($chunks as $chunk) {
            $rows[] = [
                'kb_id'        => (int) $kb->id,
                'doc_id'       => $docId,
                'chunk_index'  => $chunk->index,
                'content'      => $chunk->content,
                'token_count'  => mb_strlen($chunk->content),
                'heading'      => $chunk->meta['heading'] ?? null,
                'page'         => $chunk->meta['page'] ?? null,
                'content_hash' => md5($chunk->content),
                'vector'       => null,
                'dimension'    => null,
                'owner_type'   => $ownerType,
                'owner_id'     => $ownerId,
                'create_time'  => $now,
                'update_time'  => $now,
            ];
        }

        return $rows;
    }

    /**
     * 维度一致性校验（避免模型 / 维度变更后写入脏向量）
     */
    protected function assertDimension(KnowledgeBase $kb, int $current): void
    {
        $expected = (int) $kb->dimension;
        if ($expected > 0 && $current > 0 && $expected !== $current) {
            throw new RagException(
                "知识库[{$kb->kb_key}]向量维度不匹配（库={$expected}，当前={$current}），请重建索引或统一 embedding 模型"
            );
        }
    }

    /**
     * 用 embedding 的**真实返回维度**二次校验
     *
     * assertDimension() 比较的是「库维度 vs 配置维度」，两者口径相同必然通过；
     * 当 base_url 指向的模型维度与配置不符时（换模型未改 dimension、复用 openai provider 等），
     * 只有用真实返回值才能发现，否则会把错误维度的向量写入库中（检索侧由
     * DatabaseVectorStore 的 dimension 过滤兜底，但会让该文档全部不可检索）。
     */
    protected function assertRealDimension(KnowledgeBase $kb, int $real): void
    {
        $expected = (int) $kb->dimension;
        if ($expected > 0 && $real > 0 && $expected !== $real) {
            throw new RagException(
                "Embedding 实际维度({$real}) 与知识库[{$kb->kb_key}]声明维度({$expected}) 不一致，"
                . '请修正 wise-rag.embedding.providers 的 dimension 配置后重建索引'
            );
        }
    }
}
