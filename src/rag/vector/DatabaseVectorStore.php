<?php
declare(strict_types=1);

namespace wise\rag\vector;

use think\facade\Db;
use wise\rag\contract\VectorStoreInterface;
use wise\rag\dto\ScoredChunk;
use wise\rag\dto\VectorPoint;
use wise\rag\support\Identifier;
use wise\rag\support\KbCounter;

/**
 * 数据库向量存储（默认驱动）
 *
 * 向量以 float32 二进制存于 `ai_kb_chunks.vector`，检索时在 PHP 内做**暴力余弦**。
 * 零外部依赖、开箱即用；适用于 ≤ 5 万 chunk 的规模，超出后再切 pgvector / redis。
 *
 * 说明：`collection` 对应知识库的 `collection` 字段，本驱动据此解析 kb_id
 * （`ai_kb_chunks` 按 kb_id 归属）。
 */
class DatabaseVectorStore implements VectorStoreInterface
{
    protected array $config;

    /**
     * collection → kb_id 缓存
     *
     * 常驻进程（Swoole / 队列 worker）下本实例会被容器长期持有，因此这是**进程级**缓存；
     * 规模受知识库数量约束（collection 由自增 id 生成、不会被复用），可安全常驻，
     * 与「请求作用域」无关。
     *
     * @var array<string, int>
     */
    protected array $kbIdCache = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function upsert(string $collection, array $points): int
    {
        $kbId = $this->resolveKbId($collection);
        if ($kbId <= 0) {
            return 0;
        }

        $count = 0;
        $now   = date('Y-m-d H:i:s');
        foreach ($points as $point) {
            if (!$point instanceof VectorPoint) {
                continue;
            }

            $affected = Db::name('ai_kb_chunks')
                ->where('id', (int) $point->chunkId)
                ->where('kb_id', $kbId)
                ->update([
                    'vector'      => VectorMath::pack($point->vector),
                    'dimension'   => count($point->vector),
                    'update_time' => $now,
                ]);

            $count += (int) $affected;
        }

        return $count;
    }

    public function delete(string $collection, array $chunkIds): int
    {
        $kbId     = $this->resolveKbId($collection);
        $chunkIds = array_map('intval', $chunkIds);
        if ($kbId <= 0 || $chunkIds === []) {
            return 0;
        }

        return (int) Db::name('ai_kb_chunks')->where('kb_id', $kbId)->whereIn('id', $chunkIds)->delete();
    }

    public function deleteByMeta(string $collection, array $filter): int
    {
        $kbId = $this->resolveKbId($collection);
        if ($kbId <= 0) {
            return 0;
        }

        $query = Db::name('ai_kb_chunks')->where('kb_id', $kbId);
        $this->applyFilter($query, $filter);

        $deleted = (int) $query->delete();

        if ($deleted > 0) {
            KbCounter::syncAndFlush($kbId);
        }

        return $deleted;
    }

    public function search(string $collection, array $vector, int $topK, array $filter = []): array
    {
        $kbId = $this->resolveKbId($collection);
        if ($kbId <= 0 || $vector === []) {
            return [];
        }

        $query = Db::name('ai_kb_chunks')
            ->where('kb_id', $kbId)
            ->whereNotNull('vector')
            ->where('dimension', count($vector));
        $this->applyFilter($query, $filter);

        $limit    = max(1, $topK);
        $pageSize = max(100, (int) ($this->config['scan_page_size'] ?? 1000));

        $best   = [];
        $lastId = 0;

        while (true) {
            $page = (clone $query)
                ->where('id', '>', $lastId)
                ->order('id', 'asc')
                ->limit($pageSize)
                ->field('id,doc_id,vector')
                ->select()
                ->toArray();

            if ($page === []) {
                break;
            }

            foreach ($page as $row) {
                $lastId = (int) $row['id'];

                $stored = VectorMath::unpack((string) ($row['vector'] ?? ''));
                if ($stored === []) {
                    continue;
                }

                $score = VectorMath::cosine($vector, $stored);

                if (count($best) === $limit) {
                    if ($score <= $best[$limit - 1]->score) {
                        continue;
                    }

                    $best[$limit - 1] = new ScoredChunk((string) $row['id'], (string) $row['doc_id'], '', $score, $score, 0.0);
                    usort($best, static fn (ScoredChunk $a, ScoredChunk $b): int => $b->score <=> $a->score);
                    continue;
                }

                $best[] = new ScoredChunk((string) $row['id'], (string) $row['doc_id'], '', $score, $score, 0.0);

                if (count($best) === $limit) {
                    usort($best, static fn (ScoredChunk $a, ScoredChunk $b): int => $b->score <=> $a->score);
                }
            }
        }

        if ($best !== []) {
            usort($best, static fn (ScoredChunk $a, ScoredChunk $b): int => $b->score <=> $a->score);
        }

        return $this->hydrate($best);
    }

    /**
     * 按 top-K 的 ID 回查正文与元数据
     *
     * 与「打分阶段不取 content」配套：命中数量恒为 top-K，一次 IN 查询即可。
     *
     * @param ScoredChunk[] $hits 已按分数降序
     * @return ScoredChunk[]
     */
    protected function hydrate(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $ids  = array_map(static fn (ScoredChunk $h): int => (int) $h->chunkId, $hits);
        $rows = Db::name('ai_kb_chunks')
            ->whereIn('id', $ids)
            ->field('id,content,heading,page,owner_type,owner_id')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        $out = [];
        foreach ($hits as $hit) {
            $row = $map[(int) $hit->chunkId] ?? null;
            if ($row === null) {
                continue;
            }

            $hit->content = (string) $row['content'];
            $hit->meta    = array_merge($hit->meta, [
                'heading'    => $row['heading'],
                'page'       => $row['page'] !== null ? (int) $row['page'] : null,
                'owner_type' => $row['owner_type'],
                'owner_id'   => (int) $row['owner_id'],
            ]);

            $out[] = $hit;
        }

        return $out;
    }

    public function dropCollection(string $collection): void
    {
        $kbId = $this->resolveKbId($collection);
        if ($kbId > 0) {
            Db::name('ai_kb_chunks')->where('kb_id', $kbId)->delete();
        }
    }

    public function capabilities(): array
    {
        return [
            'type'     => 'brute',
            'ann'      => false,
            'hybrid'   => false,
            'driver'   => 'database',
        ];
    }

    public function getName(): string
    {
        return 'database';
    }

    /**
     * 解析 collection → kb_id
     */
    protected function resolveKbId(string $collection): int
    {
        if ($collection === '') {
            return 0;
        }

        if (!isset($this->kbIdCache[$collection])) {
            $this->kbIdCache[$collection] = (int) Db::name('ai_kb_knowledge_bases')
                ->where('collection', $collection)
                ->value('id');
        }

        return $this->kbIdCache[$collection];
    }

    /**
     * 应用元数据过滤（kb_id 由集合隐式确定，忽略同名过滤项）
     */
    protected function applyFilter(object $query, array $filter): void
    {
        foreach ($filter as $field => $value) {
            if ($field === 'kb_id' || $value === null || !Identifier::isColumn((string) $field)) {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }
    }
}
