<?php
declare(strict_types=1);

namespace wise\rag\vector;

use wise\rag\contract\VectorStoreInterface;
use wise\rag\exception\ProviderException;

/**
 * 向量存储工厂
 *
 * 依 `wise-rag.vector.default` / 各知识库自身的 `vector_store` 选择驱动。
 * 插件可用 mergeConfig 往 `vector.stores.*` 追加自己的驱动（无需扩展点）。
 */
class VectorFactory
{
    protected array $config;

    /** @var array<string, VectorStoreInterface> */
    protected array $instances = [];

    /**
     * @param array $config wise-rag.vector 配置节
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * @throws ProviderException
     */
    public function make(?string $name = null): VectorStoreInterface
    {
        $name = $name !== null && $name !== '' ? $name : (string) ($this->config['default'] ?? 'database');

        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $stores = (array) ($this->config['stores'] ?? []);
        $config = (array) ($stores[$name] ?? []);
        $class  = (string) ($config['class'] ?? ($name === 'database' ? DatabaseVectorStore::class : ''));

        if ($class === '' || !class_exists($class)) {
            throw new ProviderException("未知的向量存储驱动：{$name}");
        }

        $instance = new $class($config);
        if (!$instance instanceof VectorStoreInterface) {
            throw new ProviderException("向量存储驱动必须实现 VectorStoreInterface：{$class}");
        }

        return $this->instances[$name] = $instance;
    }
}
