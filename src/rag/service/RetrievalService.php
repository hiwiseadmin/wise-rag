<?php
declare(strict_types=1);

namespace wise\rag\service;

use think\facade\Db;
use wise\rag\client\EmbeddingFactory;
use wise\rag\dto\RetrievalQuery;
use wise\rag\dto\ScoredChunk;
use wise\rag\event\RetrievalPerformed;
use wise\rag\exception\RagException;
use wise\rag\keyword\KeywordDriverFactory;
use wise\rag\model\KnowledgeBase;
use wise\rag\rerank\RerankerFactory;
use wise\rag\support\ActorContext;
use wise\rag\support\EventBus;
use wise\rag\vector\VectorFactory;

/**
 * 检索服务
 *
 * 流程：向量召回 +（可选）关键词召回 → 融合（RRF / 加权）→ 低分过滤 →（可选）重排
 *      → 去重 → 引用溯源 → 上下文预算截断 → 事件 +（可选）检索日志。
 *
 * 配置见 `wise-rag.retrieval`（hybrid / fusion / rrf_k / vector_weight / keyword_weight /
 * min_score / max_context_chars / rerank / log_enabled）。
 */
class RetrievalService
{
    public function __construct(
        protected EmbeddingFactory $embeddingFactory,
        protected VectorFactory $vectorFactory,
        protected KnowledgeBaseService $kbService,
        protected ?KeywordDriverFactory $keywordFactory = null,
        protected ?RerankerFactory $rerankerFactory = null
    ) {
        $this->keywordFactory ??= new KeywordDriverFactory([]);
        $this->rerankerFactory ??= new RerankerFactory([]);
    }

    /**
     * @return ScoredChunk[]
     * @throws RagException
     */
    public function retrieve(RetrievalQuery $query): array
    {
        $text = trim($query->text);
        if ($text === '' && ($query->vector === null || $query->vector === [])) {
            return [];
        }

        $startedAt = microtime(true);
        $config    = $this->retrievalConfig();

        $topK      = $query->topK > 0 ? $query->topK : (int) ($config['top_k'] ?? 8);
        $minScore  = (float) ($config['min_score'] ?? 0.25);
        $maxChars  = (int) ($config['max_context_chars'] ?? 6000);
        $hybrid    = $query->hybrid && !empty($config['hybrid']);
        $fusion    = (string) ($config['fusion'] ?? 'rrf');

        $vectorSequence  = [];
        $keywordSequence = [];

        $queryVectors = [];

        foreach ($this->resolveKnowledgeBases($query) as $kb) {
            $client = $this->embeddingFactory->make($kb->embedding);
            $this->assertDimension($kb, $client->getDimension());

            if ($query->vector !== null && $query->vector !== []) {
                $vector = $query->vector;
            } else {
                $cacheKey = (string) $kb->embedding;
                $vector   = $queryVectors[$cacheKey] ??= ($client->embed([$text])[0] ?? []);
            }

            if ($vector !== []) {
                try {
                    $store = $this->vectorFactory->make($kb->vector_store);
                    foreach ($store->search($kb->collection, $vector, $topK * 2, $query->filter) as $hit) {
                        $hit->meta['kb_id']  = (int) $kb->id;
                        $hit->meta['kb_key'] = $kb->kb_key;
                        $vectorSequence[]    = $hit;
                    }
                } catch (\Exception $e) {
                    throw new RagException("知识库[{$kb->kb_key}]向量检索失败：" . $e->getMessage(), 0, $e);
                }
            }

            if ($hybrid && $text !== '') {
                foreach ($this->keywordHits($kb, $text, $topK * 2, $query->filter, $config) as $hit) {
                    $hit->meta['kb_id']  = (int) $kb->id;
                    $hit->meta['kb_key'] = $kb->kb_key;
                    $keywordSequence[]   = $hit;
                }
            }
        }

        $candidates = $this->fuse(
            $vectorSequence,
            $keywordSequence,
            $fusion,
            (float) ($config['vector_weight'] ?? 0.7),
            (float) ($config['keyword_weight'] ?? 0.3),
            (int) ($config['rrf_k'] ?? 60)
        );

        $candidates = array_values(array_filter(
            $candidates,
            static fn (ScoredChunk $c): bool => $c->score >= $minScore
        ));

        if (!empty($config['dedup'])) {
            $candidates = $this->dedup($candidates);
        }

        $candidates = $this->rerank($text, $candidates, $topK, $config);

        $this->attachCitations($candidates);

        $result = [];
        $used   = 0;
        foreach ($candidates as $hit) {
            $length = mb_strlen($hit->content);
            if ($used > 0 && $used + $length > $maxChars) {
                break;
            }
            $used    += $length;
            $result[] = $hit;
            if (count($result) >= $topK) {
                break;
            }
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        EventBus::trigger(new RetrievalPerformed(
            $text,
            $query->kbIds,
            count($result),
            $result === [] ? null : (float) $result[0]->score,
            $latencyMs
        ));

        if (!empty($config['log_enabled'])) {
            $this->logRetrieval($text, $result, $latencyMs);
        }

        return $result;
    }

    /**
     * 关键词召回（配置驱动；不可用 / 无命中时回退回退驱动）
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $config
     * @return ScoredChunk[]
     */
    protected function keywordHits(KnowledgeBase $kb, string $text, int $topK, array $filter, array $config): array
    {
        $driverName = (string) ($config['driver'] ?? 'mysql_fulltext');
        $fallback   = (string) ($config['fallback'] ?? 'like');

        try {
            $driver = $this->keywordFactory->make($driverName);
            if ($driver->isAvailable()) {
                $hits = $driver->search($kb->collection, $text, $topK, $filter);
                if ($hits !== []) {
                    return $hits;
                }
            }
        } catch (\Exception $e) {
        }

        if ($fallback === '' || $fallback === $driverName) {
            return [];
        }

        try {
            return $this->keywordFactory->make($fallback)->search($kb->collection, $text, $topK, $filter);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 融合多路召回
     *
     * - `rrf`（默认）：倒数排名融合，按最大得分归一化到 (0,1]，使 min_score 仍可用；
     * - `weighted`：各路得分按各自最大值归一化后加权求和；
     * - 仅向量（无关键词命中）：保留向量原始相似度。
     *
     * @param ScoredChunk[] $vectorSequence
     * @param ScoredChunk[] $keywordSequence
     * @return ScoredChunk[]
     */
    protected function fuse(
        array $vectorSequence,
        array $keywordSequence,
        string $fusion,
        float $vectorWeight,
        float $keywordWeight,
        int $rrfK
    ): array {
        $chunks = [];

        foreach ($vectorSequence as $hit) {
            $chunks[$this->keyOf($hit)] = $hit;
        }
        foreach ($keywordSequence as $hit) {
            $key = $this->keyOf($hit);
            if (!isset($chunks[$key])) {
                $chunks[$key] = $hit;
            }
        }

        if ($chunks === []) {
            return [];
        }

        if ($keywordSequence === []) {
            $list = array_values($chunks);
            usort($list, static fn (ScoredChunk $a, ScoredChunk $b): int => $b->score <=> $a->score);

            return $list;
        }

        if ($fusion === 'weighted') {
            $vectorNorm  = $this->normalizeByMax($vectorSequence);
            $keywordNorm = $this->normalizeByMax($keywordSequence);

            foreach ($chunks as $key => $chunk) {
                $chunk->score = $vectorWeight * ($vectorNorm[$key] ?? 0.0)
                    + $keywordWeight * ($keywordNorm[$key] ?? 0.0);
            }
        } else {
            $rrf = [];
            foreach ($vectorSequence as $rank => $hit) {
                $key       = $this->keyOf($hit);
                $rrf[$key] = ($rrf[$key] ?? 0.0) + 1.0 / ($rrfK + $rank + 1);
            }
            foreach ($keywordSequence as $rank => $hit) {
                $key       = $this->keyOf($hit);
                $rrf[$key] = ($rrf[$key] ?? 0.0) + 1.0 / ($rrfK + $rank + 1);
            }

            $max = max($rrf);
            foreach ($chunks as $key => $chunk) {
                $chunk->score = $max > 0.0 ? ($rrf[$key] ?? 0.0) / $max : 0.0;
            }
        }

        $list = array_values($chunks);
        usort($list, static fn (ScoredChunk $a, ScoredChunk $b): int => $b->score <=> $a->score);

        return $list;
    }

    /**
     * @param ScoredChunk[] $hits
     * @return array<string, float>
     */
    protected function normalizeByMax(array $hits): array
    {
        $max = 0.0;
        foreach ($hits as $hit) {
            $max = max($max, $hit->score);
        }

        $out = [];
        foreach ($hits as $hit) {
            $out[$this->keyOf($hit)] = $max > 0.0 ? $hit->score / $max : 0.0;
        }

        return $out;
    }

    /**
     * 重排（可选）
     *
     * @param ScoredChunk[]        $candidates
     * @param array<string, mixed> $config
     * @return ScoredChunk[]
     */
    protected function rerank(string $query, array $candidates, int $topK, array $config): array
    {
        $rerankConfig = (array) ($config['rerank'] ?? []);
        if (empty($rerankConfig['enabled']) || $candidates === [] || $query === '') {
            return $candidates;
        }

        try {
            $reranker = $this->rerankerFactory->make((string) ($rerankConfig['provider'] ?? ''));
            if ($reranker === null) {
                return $candidates;
            }

            $topN = max($topK, (int) ($rerankConfig['top_n'] ?? 4));

            return $reranker->rerank($query, $candidates, $topN);
        } catch (\Exception $e) {
            error_log('[wise-rag] 重排失败，保留融合结果: ' . $e->getMessage());

            return $candidates;
        }
    }

    /**
     * 去重：内容完全相同的分块只保留得分最高的一条
     *
     * 入参需已按分数降序排列（保留首条即最高分）。
     *
     * @param ScoredChunk[] $hits
     * @return ScoredChunk[]
     */
    protected function dedup(array $hits): array
    {
        $seen = [];
        $out  = [];

        foreach ($hits as $hit) {
            $key = md5(trim($hit->content));
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = $hit;
        }

        return $out;
    }

    protected function keyOf(ScoredChunk $chunk): string
    {
        return (int) ($chunk->meta['kb_id'] ?? 0) . ':' . $chunk->chunkId;
    }

    /**
     * 写入检索观测日志（可选，失败静默）
     *
     * @param ScoredChunk[] $result
     */
    protected function logRetrieval(string $query, array $result, int $latencyMs): void
    {
        try {
            $actor    = ActorContext::current();
            $userType = $actor['type'];
            $userId   = $actor['id'];

            $now = date('Y-m-d H:i:s');

            Db::name('ai_kb_retrieval_logs')->insert([
                'kb_id'       => (int) ($result[0]->meta['kb_id'] ?? 0),
                'query'       => mb_substr($query, 0, 1000),
                'hit_count'   => count($result),
                'top_score'   => $result === [] ? null : round((float) $result[0]->score, 4),
                'latency_ms'  => $latencyMs,
                'user_type'   => $userType,
                'user_id'     => $userId,
                'session_id'  => null,
                'create_time' => $now,
            ]);
        } catch (\Exception $e) {
        }
    }

    /**
     * 解析待检索的知识库集合
     *
     * @return KnowledgeBase[]
     */
    protected function resolveKnowledgeBases(RetrievalQuery $query): array
    {
        $visible = fn (KnowledgeBase $kb): bool => $this->isVisibleToCurrentActor($kb);

        if ($query->kbIds === []) {
            return array_values(array_filter($this->kbService->all(true), $visible));
        }

        $kbs = [];
        foreach ($query->kbIds as $id) {
            $kb = $this->kbService->get((int) $id);
            if ($kb !== null && $kb->status === KnowledgeBase::STATUS_ACTIVE && $visible($kb)) {
                $kbs[] = $kb;
            }
        }

        return $kbs;
    }

    /**
     * 可见性判定：private 仅归属者可检索；tenant / public 放行
     *
     * 修复前 `visibility` 与 `owner_*` 从未参与召回过滤，全库检索会把
     * 私有知识库内容返回给任意调用方（MCP / Agent 工具 / wise_rag_search）。
     */
    protected function isVisibleToCurrentActor(KnowledgeBase $kb): bool
    {
        $visibility = (string) ($kb->visibility ?: KnowledgeBase::VISIBILITY_PRIVATE);
        if ($visibility !== KnowledgeBase::VISIBILITY_PRIVATE) {
            return true;
        }

        if ((int) $kb->owner_id <= 0) {
            return true;
        }

        $actor     = ActorContext::current();
        $actorType = $actor['type'];
        $actorId   = $actor['id'];

        return $actorId > 0
            && $actorType === (string) $kb->owner_type
            && $actorId === (int) $kb->owner_id;
    }

    /**
     * 组装引用溯源信息（文档标题 / URL / 页码 / 标题路径）
     *
     * @param ScoredChunk[] $hits
     */
    protected function attachCitations(array $hits): void
    {
        if ($hits === []) {
            return;
        }

        $docIds = array_values(array_unique(array_map(
            static fn (ScoredChunk $h): int => (int) $h->docId,
            $hits
        )));

        $docs = Db::name('ai_kb_documents')
            ->whereIn('id', $docIds)
            ->column('title,url', 'id');

        foreach ($hits as $hit) {
            $doc = $docs[(int) $hit->docId] ?? [];

            $hit->meta['cite'] = [
                'doc_id'  => (int) $hit->docId,
                'kb_key'  => (string) ($hit->meta['kb_key'] ?? ''),
                'title'   => (string) ($doc['title'] ?? ''),
                'url'     => (string) ($doc['url'] ?? ''),
                'page'    => $hit->meta['page'] ?? null,
                'heading' => (string) ($hit->meta['heading'] ?? ''),
            ];
        }
    }

    /**
     * 维度一致性校验
     */
    protected function assertDimension(KnowledgeBase $kb, int $current): void
    {
        $expected = (int) $kb->dimension;
        if ($expected > 0 && $current > 0 && $expected !== $current) {
            throw new RagException(
                "知识库[{$kb->kb_key}]向量维度不匹配（库={$expected}，当前={$current}），请重建索引"
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function retrievalConfig(): array
    {
        return function_exists('config') ? (array) config('wise-rag.retrieval', []) : [];
    }
}
