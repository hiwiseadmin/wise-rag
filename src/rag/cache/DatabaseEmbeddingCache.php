<?php
declare(strict_types=1);

namespace wise\rag\cache;

use think\facade\Db;
use wise\rag\contract\EmbeddingCacheInterface;
use wise\rag\vector\VectorMath;

/**
 * 向量缓存驱动：数据库（默认）
 *
 * 表 `ai_kb_embedding_cache`，以 (hash, model) 唯一键存 float32 向量；
 * 命中时累加 `hits` 便于观察收益。
 *
 * ⚠️ 常驻进程（Swoole / 常驻队列 worker）注意：容器会长期持有本实例（`Container::make()`
 * 会把解析结果写入 `$instances`），因此**进程内 memo 必须有上限**，否则会随处理过的
 * 文本数无限增长。上限由 `wise-rag.cache.drivers.database.memo_limit` 控制（默认 500）。
 */
class DatabaseEmbeddingCache implements EmbeddingCacheInterface
{
    protected array $config;

    /**
     * 进程内已读取过的向量（避免同一文本重复查库）
     *
     * @var array<string, float[]|null>
     */
    protected array $memo = [];

    /** memo 条目上限（防常驻进程内存无限增长） */
    protected int $memoLimit;

    public function __construct(array $config = [])
    {
        $this->config    = $config;
        $this->memoLimit = max(1, (int) ($config['memo_limit'] ?? 500));
    }

    public function get(string $hash, string $model): ?array
    {
        $key = $model . ':' . $hash;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        try {
            $row = Db::name('ai_kb_embedding_cache')
                ->where('hash', $hash)
                ->where('model', $model)
                ->field('id,vector')
                ->find();
        } catch (\Exception $e) {
            return null;
        }

        if (!is_array($row)) {
            return $this->remember($key, null);
        }

        $vector = VectorMath::unpack((string) ($row['vector'] ?? ''));
        if ($vector === []) {
            return $this->remember($key, null);
        }

        try {
            Db::name('ai_kb_embedding_cache')->where('id', (int) $row['id'])->inc('hits')->update();
        } catch (\Exception $e) {
        }

        return $this->remember($key, $vector);
    }

    public function put(string $hash, string $model, array $vector): void
    {
        if ($vector === []) {
            return;
        }

        $this->remember($model . ':' . $hash, $vector);

        $now  = date('Y-m-d H:i:s');
        $data = [
            'hash'        => $hash,
            'model'       => $model,
            'dimension'   => count($vector),
            'vector'      => VectorMath::pack($vector),
            'update_time' => $now,
        ];

        try {
            $exists = Db::name('ai_kb_embedding_cache')
                ->where('hash', $hash)
                ->where('model', $model)
                ->value('id');

            if ($exists) {
                Db::name('ai_kb_embedding_cache')->where('id', (int) $exists)->update($data);

                return;
            }

            $data['hits']        = 0;
            $data['create_time'] = $now;
            Db::name('ai_kb_embedding_cache')->insert($data);
        } catch (\Exception $e) {
            error_log('[wise-rag] 向量缓存写入失败: ' . $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'database';
    }

    /**
     * 写入进程内 memo，并按上限做 FIFO 淘汰
     *
     * @param float[]|null $vector
     * @return float[]|null
     */
    protected function remember(string $key, ?array $vector): ?array
    {
        if (!array_key_exists($key, $this->memo) && count($this->memo) >= $this->memoLimit) {
            array_shift($this->memo);
        }

        $this->memo[$key] = $vector;

        return $vector;
    }
}
