<?php
declare(strict_types=1);

namespace wise\rag\keyword;

use think\facade\Cache;
use think\facade\Db;
use wise\rag\contract\KeywordDriverInterface;
use wise\rag\dto\ScoredChunk;
use wise\rag\support\Identifier;

/**
 * 关键词检索驱动：MySQL FULLTEXT（默认）
 *
 * 中文依赖 `WITH PARSER ngram` 分词器（迁移已建索引）。第一次使用前会探测可用性
 * （执行一次 MATCH 查询），结果缓存；不可用时由 RetrievalService 回退 LIKE。
 *
 * 说明：FULLTEXT 的原始相关度仅用于排序，融合（RRF/加权）只依赖**名次**，
 * 故此处不额外选取相关度列，避免方言差异。
 */
class FulltextDriver implements KeywordDriverInterface
{
    protected array $config;

    /** 进程内可用性缓存 */
    protected static ?bool $available = null;

    /**
     * 进程内缓存写入时间戳
     *
     * 常驻进程（Swoole / 队列 worker）下静态值不会随请求重置，
     * 必须按 cache_ttl 判定过期，否则迁移后新增的 FULLTEXT 索引在进程重启前都不生效。
     */
    protected static int $availableAt = 0;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function search(string $collection, string $query, int $topK, array $filter = []): array
    {
        $query = trim($query);
        if ($query === '' || !$this->isAvailable()) {
            return [];
        }

        $minLength = (int) ($this->config['min_length'] ?? 2);
        if (mb_strlen($query) < $minLength) {
            return [];
        }

        $kbId = $this->resolveKbId($collection);
        if ($kbId <= 0) {
            return [];
        }

        $expression = $this->toExpression($query, (bool) ($this->config['boolean'] ?? true));
        if ($expression === '') {
            return [];
        }

        $rows = $this->baseQuery($kbId, $filter)
            ->whereRaw('MATCH(`content`) AGAINST (? IN BOOLEAN MODE)', [$expression])
            ->orderRaw('MATCH(`content`) AGAINST (? IN BOOLEAN MODE) DESC', [$expression])
            ->field('id,doc_id,content,heading,page,owner_type,owner_id')
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
        $ttl = max(0, (int) ($this->config['cache_ttl'] ?? 3600));

        if ($ttl > 0 && static::$available !== null && time() - static::$availableAt < $ttl) {
            return static::$available;
        }

        $table = $this->table();
        if ($table === '') {
            return $this->rememberAvailability(false, $ttl);
        }

        $cacheKey = 'wise_rag:ft_available:' . md5($table);

        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $this->rememberAvailability((bool) $cached, $ttl);
            }
        } catch (\Exception $e) {
        }

        $available = false;
        try {
            Db::query(
                "SELECT `id` FROM `{$table}` WHERE MATCH(`content`) AGAINST (? IN BOOLEAN MODE) LIMIT 1",
                ['probe']
            );
            $available = true;
        } catch (\Exception $e) {
            $available = false;
        }

        if ($ttl > 0) {
            try {
                Cache::set($cacheKey, $available, $ttl);
            } catch (\Exception $e) {
            }
        }

        return $this->rememberAvailability($available, $ttl);
    }

    /**
     * 写入进程内可用性缓存（记录写入时间供 TTL 判定）
     *
     * ttl = 0 表示「不缓存」，此时不写静态值，每次都会重新探测。
     */
    protected function rememberAvailability(bool $available, int $ttl): bool
    {
        if ($ttl > 0) {
            static::$available   = $available;
            static::$availableAt = time();
        }

        return $available;
    }

    public function getName(): string
    {
        return 'mysql_fulltext';
    }

    /**
     * 查询词 → BOOLEAN MODE 表达式（每个词加 `+`，剔除运算符避免语法错误）
     */
    protected function toExpression(string $query, bool $boolean): string
    {
        $tokens = preg_split('/\s+/u', $query) ?: [];

        $terms = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/[+\-><()~*"@,:;.\\\\\/]/u', ' ', $token) ?? '';
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $terms[] = $boolean ? '+' . $token : $token;
        }

        return implode(' ', array_slice($terms, 0, 8));
    }

    /**
     * @param array<string, mixed> $filter
     */
    protected function baseQuery(int $kbId, array $filter): object
    {
        $query = Db::name('ai_kb_chunks')->where('kb_id', $kbId);

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

        return $query;
    }

    protected function resolveKbId(string $collection): int
    {
        if ($collection === '') {
            return 0;
        }

        return (int) Db::name('ai_kb_knowledge_bases')->where('collection', $collection)->value('id');
    }

    protected function table(): string
    {
        try {
            return (string) Db::name('ai_kb_chunks')->getTable();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * 重置可用性探测缓存（迁移后 / 测试用）
     */
    public static function resetAvailability(): void
    {
        static::$available = null;
    }
}
