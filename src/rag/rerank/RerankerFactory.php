<?php
declare(strict_types=1);

namespace wise\rag\rerank;

use wise\rag\contract\RerankerInterface;
use wise\rag\exception\ProviderException;

/**
 * 重排工厂
 *
 * 依 `wise-rag.retrieval.rerank.enabled/provider` 决定是否创建；
 * provider 为空或未启用时返回 null（检索流程据此跳过重排）。
 */
class RerankerFactory
{
    protected array $config;

    /** @var array<string, RerankerInterface> */
    protected array $instances = [];

    /**
     * @param array<string, mixed> $config wise-rag.rerank 配置节
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * @return RerankerInterface|null null = 未启用重排
     * @throws ProviderException
     */
    public function make(?string $name = null): ?RerankerInterface
    {
        $name = $name !== null && $name !== '' ? $name : (string) ($this->config['default'] ?? '');

        if ($name === '') {
            return null;
        }

        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $configured = (array) ($this->config['providers'][$name] ?? []);
        if ($configured === []) {
            throw new ProviderException("未配置的 rerank provider：{$name}");
        }

        $class = (string) ($configured['class'] ?? HttpReranker::class);
        if (!class_exists($class)) {
            throw new ProviderException("rerank 驱动类不存在：{$class}");
        }

        $instance = new $class($configured + ['name' => $name]);
        if (!$instance instanceof RerankerInterface) {
            throw new ProviderException("rerank 驱动必须实现 RerankerInterface：{$class}");
        }

        return $this->instances[$name] = $instance;
    }
}
