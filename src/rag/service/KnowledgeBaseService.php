<?php
declare(strict_types=1);

namespace wise\rag\service;

use think\facade\Cache;
use think\facade\Db;
use wise\rag\client\EmbeddingFactory;
use wise\rag\exception\RagException;
use wise\rag\model\KnowledgeBase;
use wise\rag\support\KbCounter;
use wise\rag\tool\KnowledgeToolRegistrar;
use wise\rag\vector\VectorFactory;

/**
 * 知识库服务：CRUD、统计与索引编排
 */
class KnowledgeBaseService
{
    public function __construct(
        protected EmbeddingFactory $embeddingFactory,
        protected VectorFactory $vectorFactory
    ) {
    }

    /**
     * 创建知识库
     *
     * @param array<string, mixed> $data kb_key / name / description / owner_type / owner_id /
     *                                   visibility / embedding / vector_store / chunk_config / retrieval_config
     * @throws RagException
     */
    public function create(array $data): KnowledgeBase
    {
        $kbKey = trim((string) ($data['kb_key'] ?? ''));
        if ($kbKey === '') {
            throw new RagException('创建知识库需要 kb_key');
        }
        if ($this->findByKey($kbKey) !== null) {
            throw new RagException("知识库已存在：{$kbKey}");
        }

        $embeddingName = (string) ($data['embedding'] ?? $this->cfg('wise-rag.embedding.default', 'openai'));
        $client        = $this->embeddingFactory->make($embeddingName);

        $kb = new KnowledgeBase();
        $kb->save([
            'kb_key'           => $kbKey,
            'name'             => (string) ($data['name'] ?? $kbKey),
            'description'      => $data['description'] ?? null,
            'owner_type'       => (string) ($data['owner_type'] ?? 'admin'),
            'owner_id'         => (int) ($data['owner_id'] ?? 0),
            'visibility'       => (string) ($data['visibility'] ?? KnowledgeBase::VISIBILITY_PRIVATE),
            'embedding'        => $embeddingName,
            'embed_model'      => $client->getModel(),
            'dimension'        => $client->getDimension(),
            'vector_store'     => (string) ($data['vector_store'] ?? $this->cfg('wise-rag.vector.default', 'database')),
            'collection'       => '',
            'chunk_config'     => $this->encodeJson($data['chunk_config'] ?? null),
            'retrieval_config' => $this->encodeJson($data['retrieval_config'] ?? null),
            'status'           => KnowledgeBase::STATUS_ACTIVE,
        ]);

        $kb->save(['collection' => 'ai_kb_' . $kb->id]);

        KnowledgeToolRegistrar::flushToolCache();
        self::flushStatsCache();

        return $kb;
    }

    public function findByKey(string $kbKey): ?KnowledgeBase
    {
        $kb = KnowledgeBase::where('kb_key', $kbKey)->find();

        return $kb;
    }

    public function get(int $id): ?KnowledgeBase
    {
        if ($id <= 0) {
            return null;
        }

        $kb = KnowledgeBase::find($id);

        return $kb;
    }

    /**
     * 全部知识库
     *
     * @return KnowledgeBase[]
     */
    public function all(bool $onlyActive = true): array
    {
        $query = KnowledgeBase::order('id', 'asc');
        if ($onlyActive) {
            $query->where('status', KnowledgeBase::STATUS_ACTIVE);
        }

        return $query->select()->all();
    }

    /**
     * 删除知识库（可选连带清空向量）
     */
    public function delete(int $id, bool $withIndex = true): bool
    {
        $kb = $this->get($id);
        if ($kb === null) {
            return false;
        }

        if ($withIndex) {
            try {
                $this->vectorFactory->make($kb->vector_store)->dropCollection($kb->collection);
            } catch (\Exception $e) {
            }
        }

        Db::name('ai_kb_sources')->where('kb_id', $id)->delete();
        try {
            Db::name('ai_kb_retrieval_logs')->where('kb_id', $id)->delete();
        } catch (\Exception $e) {
        }

        $paths = Db::name('ai_kb_documents')
            ->where('kb_id', $id)
            ->where('file_path', '<>', '')
            ->column('file_path');
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }

        Db::name('ai_kb_documents')->where('kb_id', $id)->delete();

        $deleted = (bool) $kb->delete();

        KnowledgeToolRegistrar::flushToolCache();
        self::flushStatsCache();

        return $deleted;
    }

    /**
     * 刷新文档 / 分块 / 已向量化计数
     *
     * 其中 `vector_count` 落库后，统计概览无需再对 `ai_kb_chunks.vector`
     * 这个 binary 大列做全表扫描（见 {@see self::overview()}）。
     */
    public function refreshStats(int $kbId): void
    {
        if ($this->get($kbId) === null) {
            return;
        }

        KbCounter::syncAndFlush($kbId);
    }

    /**
     * 统计概览
     *
     * - `vectors` 取知识库维度的 `vector_count` 求和，避免扫描 `vector` 大列；
     * - 整个结果按 `wise-rag.stats.cache_ttl` 缓存（默认 60s，0 = 不缓存），
     *   避免后台首屏 `bases → stats → sources` 连续请求重复聚合；
     * - 计数变更（refreshStats / create / delete）会主动失效该缓存。
     *
     * @return array<string, int>
     */
    public function overview(): array
    {
        $ttl    = max(0, (int) $this->cfg('wise-rag.stats.cache_ttl', 60));
        $cached = $this->statsCacheGet();

        if ($cached !== null) {
            return $cached;
        }

        $data = [
            'bases'   => (int) Db::name('ai_kb_knowledge_bases')->count(),
            'sources' => (int) Db::name('ai_kb_sources')->count(),
            'docs'    => (int) Db::name('ai_kb_documents')->count(),
            'chunks'  => (int) Db::name('ai_kb_chunks')->count(),
            'failed'  => (int) Db::name('ai_kb_documents')->where('status', 'error')->count(),
            'vectors' => (int) Db::name('ai_kb_knowledge_bases')->sum('vector_count'),
        ];

        if ((bool) $this->cfg('wise-rag.retrieval.log_enabled', false)) {
            try {
                $data['retrievals_24h'] = (int) Db::name('ai_kb_retrieval_logs')
                    ->where('create_time', '>=', date('Y-m-d H:i:s', time() - 86400))
                    ->count();
            } catch (\Exception $e) {
                $data['retrievals_24h'] = 0;
            }
        }

        if ($ttl > 0) {
            $this->statsCacheSet($data, $ttl);
        }

        return $data;
    }

    /**
     * 失效统计概览缓存
     *
     * 缓存键与写入统一由 {@see KbCounter} 提供（向量驱动等基础设施层也会写入/失效）。
     */
    public static function flushStatsCache(): void
    {
        KbCounter::flushStatsCache();
    }

    /**
     * @return array<string, int>|null
     */
    protected function statsCacheGet(): ?array
    {
        if (max(0, (int) $this->cfg('wise-rag.stats.cache_ttl', 60)) === 0 || !class_exists(Cache::class)) {
            return null;
        }

        try {
            $cached = Cache::get(KbCounter::STATS_CACHE_KEY);
        } catch (\Exception $e) {
            return null;
        }

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string, int> $data
     */
    protected function statsCacheSet(array $data, int $ttl): void
    {
        if (!class_exists(Cache::class)) {
            return;
        }

        try {
            Cache::set(KbCounter::STATS_CACHE_KEY, $data, $ttl);
        } catch (\Exception $e) {
        }
    }

    protected function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return function_exists('config') ? config($key, $default) : $default;
    }
}
