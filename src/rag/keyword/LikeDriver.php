<?php
declare(strict_types=1);

namespace wise\rag\keyword;

use think\facade\Db;
use wise\rag\contract\KeywordDriverInterface;
use wise\rag\dto\ScoredChunk;
use wise\rag\support\Identifier;

/**
 * 关键词检索驱动：LIKE 全表扫描（回退用）
 *
 * FULLTEXT 不可用（引擎不支持 / 短查询 / 未建索引）时使用。中文友好、零依赖，
 * 代价是全表扫描 —— 通过 kb_id 与作用域过滤先缩小候选集，并限制拼词数量。
 */
class LikeDriver implements KeywordDriverInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function search(string $collection, string $query, int $topK, array $filter = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $kbId = $this->resolveKbId($collection);
        if ($kbId <= 0) {
            return [];
        }

        $maxTerms = max(1, (int) ($this->config['max_terms'] ?? 8));
        $terms    = $this->terms($query, $maxTerms);
        if ($terms === []) {
            return [];
        }

        $db = Db::name('ai_kb_chunks')->where('kb_id', $kbId);
        foreach ($filter as $field => $value) {
            if ($field === 'kb_id' || $value === null || !Identifier::isColumn((string) $field)) {
                continue;
            }
            if (is_array($value)) {
                $db->whereIn($field, $value);
            } else {
                $db->where($field, $value);
            }
        }

        $db->where(function ($sub) use ($terms): void {
            foreach ($terms as $term) {
                $sub->whereOr('content', 'like', '%' . $this->escape($term) . '%');
            }
        });

        $rows = $db->field('id,doc_id,content,heading,page,owner_type,owner_id')
            ->limit(max(1, $topK))
            ->select()
            ->toArray();

        $hits = [];
        foreach ($rows as $rank => $row) {
            $score = 1.0 / ($rank + 1);
            $hits[] = new ScoredChunk(
                (string) $row['id'],
                (string) $row['doc_id'],
                (string) $row['content'],
                $score,
                0.0,
                $score,
                [
                    'heading'    => $row['heading'],
                    'page'       => $row['page'] !== null ? (int) $row['page'] : null,
                    'owner_type' => $row['owner_type'],
                    'owner_id'   => (int) $row['owner_id'],
                ]
            );
        }

        return $hits;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'like';
    }

    /**
     * @return string[]
     */
    protected function terms(string $query, int $maxTerms): array
    {
        $tokens = preg_split('/\s+/u', $query) ?: [];

        $tokens = array_filter(array_map('trim', $tokens), static fn (string $t): bool => $t !== '');

        return array_slice(array_values($tokens), 0, $maxTerms);
    }

    protected function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    protected function resolveKbId(string $collection): int
    {
        if ($collection === '') {
            return 0;
        }

        return (int) Db::name('ai_kb_knowledge_bases')->where('collection', $collection)->value('id');
    }
}
