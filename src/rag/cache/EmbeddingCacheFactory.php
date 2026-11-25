<?php
declare(strict_types=1);

namespace wise\rag\cache;

use wise\rag\contract\EmbeddingCacheInterface;
use wise\rag\exception\ProviderException;

/**
 * 向量缓存工厂
 *
 * 依 `wise-rag.cache`（enabled / driver / drivers.*）创建缓存驱动；
 * 未启用时 `make()` 返回 null（EmbeddingFactory 据此跳过装饰）。
 */
class EmbeddingCacheFactory
{
    /** @var array<string, string> 内置驱动 */
    protected const BUILTIN = [
        'database' => DatabaseEmbeddingCache::class,
    ];

    protected array $config;

    /** @var array<string, EmbeddingCacheInterface> */
    protected array $instances = [];

    /**
     * @param array<string, mixed> $config wise-rag.cache 配置节
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * @return EmbeddingCacheInterface|null null = 未启用
     * @throws ProviderException
     */
    public function make(?string $name = null): ?EmbeddingCacheInterface
    {
        if (!$this->enabled()) {
            return null;
        }

        $name = $name !== null && $name !== '' ? $name : (string) ($this->config['driver'] ?? 'database');

        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $configured = (array) ($this->config['drivers'][$name] ?? []);
        $class      = (string) ($configured['class'] ?? self::BUILTIN[$name] ?? '');

        if ($class === '' || !class_exists($class)) {
            throw new ProviderException("未知的向量缓存驱动：{$name}");
        }

        $instance = new $class($configured);
        if (!$instance instanceof EmbeddingCacheInterface) {
            throw new ProviderException("向量缓存驱动必须实现 EmbeddingCacheInterface：{$class}");
        }

        return $this->instances[$name] = $instance;
    }

    /**
     * @return array<string, string>
     */
    public static function builtin(): array
    {
        return self::BUILTIN;
    }
}
