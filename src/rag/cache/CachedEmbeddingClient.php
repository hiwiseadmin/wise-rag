<?php
declare(strict_types=1);

namespace wise\rag\cache;

use wise\rag\contract\EmbeddingCacheInterface;
use wise\rag\contract\EmbeddingInterface;

/**
 * 向量缓存装饰器
 *
 * 包装任意 `EmbeddingInterface`：命中缓存的文本直接返回，未命中的批量回源并写回缓存。
 * 以「文本 md5 + 模型」为键 —— 模型变更后自动失效（不会串味）。
 */
class CachedEmbeddingClient implements EmbeddingInterface
{
    protected int $hits = 0;

    protected int $misses = 0;

    public function __construct(
        protected EmbeddingInterface $inner,
        protected EmbeddingCacheInterface $cache
    ) {
    }

    /**
     * @param string[] $texts
     * @return array<int, float[]>
     */
    public function embed(array $texts, array $options = []): array
    {
        $texts = array_values($texts);
        if ($texts === []) {
            return [];
        }

        $model = $this->inner->getModel();

        $resolved = [];
        $pending = [];

        foreach ($texts as $index => $text) {
            if (!is_string($text) || $text === '') {
                $resolved[$index] = [];
                continue;
            }

            $cached = $this->cache->get(md5($text), $model);
            if ($cached !== null) {
                $this->hits++;
                $resolved[$index] = $cached;
                continue;
            }

            $pending[$index] = $text;
        }

        if ($pending !== []) {
            $vectors = $this->inner->embed(array_values($pending), $options);
            $offset  = 0;

            foreach ($pending as $index => $text) {
                $vector = $vectors[$offset] ?? [];
                $offset++;
                $this->misses++;

                $resolved[$index] = $vector;

                if ($vector !== []) {
                    $this->cache->put(md5($text), $model, $vector);
                }
            }
        }

        ksort($resolved);

        return array_values($resolved);
    }

    public function getDimension(): int
    {
        return $this->inner->getDimension();
    }

    public function getModel(): string
    {
        return $this->inner->getModel();
    }

    public function getName(): string
    {
        return $this->inner->getName() . '+cache';
    }

    /**
     * 本实例的命中 / 未命中计数（观测用）
     *
     * @return array{hits:int, misses:int}
     */
    public function stats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }

    /**
     * 被装饰的内层客户端
     */
    public function inner(): EmbeddingInterface
    {
        return $this->inner;
    }
}
