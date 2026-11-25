<?php
declare(strict_types=1);

namespace wise\rag\client;

use wise\rag\cache\CachedEmbeddingClient;
use wise\rag\cache\EmbeddingCacheFactory;
use wise\rag\contract\EmbeddingInterface;
use wise\rag\exception\ProviderException;

/**
 * Embedding 客户端工厂
 *
 * 依配置 `wise-rag.embedding` 创建客户端，并按 `source` 决定鉴权 / 端点来源：
 *   - auto （默认）：provider 名与 wise-agent.providers 同名时，复用其 api_key / base_url；
 *   - agent      ：强制复用 wise-agent.providers（缺失即报错）；
 *   - own        ：只用本包独立配置。
 *
 * 注意：只复用鉴权与端点，**不复用 wise-agent 的 chat 客户端类**；
 * model / dimension 始终以本包配置为准（chat 与 embedding 常常不是同一家最优）。
 */
class EmbeddingFactory
{
    protected array $config;

    /** @var array<string, EmbeddingInterface> 按 provider 名缓存 */
    protected array $instances = [];

    /**
     * @param array                      $config       wise-rag.embedding 配置节
     * @param EmbeddingCacheFactory|null $cacheFactory 向量缓存工厂（空 = 不启用语义缓存）
     */
    public function __construct(array $config = [], protected ?EmbeddingCacheFactory $cacheFactory = null)
    {
        $this->config = $config;
    }

    /**
     * @throws ProviderException
     */
    public function make(?string $name = null): EmbeddingInterface
    {
        $name = $name !== null && $name !== '' ? $name : (string) ($this->config['default'] ?? 'openai');

        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $config = $this->resolveConfig($name);
        $class  = (string) ($config['class'] ?? OpenAiEmbeddingClient::class);

        if (!class_exists($class)) {
            throw new ProviderException("Embedding 客户端类不存在：{$class}");
        }

        $instance = new $class($config);
        if (!$instance instanceof EmbeddingInterface) {
            throw new ProviderException("Embedding 客户端必须实现 EmbeddingInterface：{$class}");
        }

        try {
            $cache = $this->cacheFactory?->make();
            if ($cache !== null) {
                $instance = new CachedEmbeddingClient($instance, $cache);
            }
        } catch (\Exception $e) {
            error_log('[wise-rag] 语义缓存初始化失败，已跳过: ' . $e->getMessage());
        }

        return $this->instances[$name] = $instance;
    }

    /**
     * 解析某 provider 的最终配置（叠加 wise-agent 复用策略）
     *
     * @return array<string, mixed>
     */
    public function resolveConfig(string $name): array
    {
        $own    = (array) ($this->config['providers'][$name] ?? []);
        $source = (string) ($this->config['source'] ?? 'auto');
        $agent  = $this->agentProvider($name);

        if ($own === [] && $agent === []) {
            throw new ProviderException(
                "未配置的 Embedding provider：{$name}（请检查 wise-rag.embedding.providers，"
                . '或设置 embedding.source=agent 复用 wise-agent.providers）'
            );
        }

        $config         = $own;
        $config['name'] = $name;

        if ($source === 'own') {
            return $config;
        }

        if ($agent === []) {
            if ($source === 'agent') {
                throw new ProviderException("embedding.source=agent，但 wise-agent.providers 未配置 provider：{$name}");
            }

            return $config;
        }

        foreach (['api_key', 'base_url'] as $key) {
            $agentValue = (string) ($agent[$key] ?? '');
            if ($agentValue === '') {
                continue;
            }
            if ($source === 'agent' || (string) ($config[$key] ?? '') === '') {
                $config[$key] = $agentValue;
            }
        }

        return $config;
    }

    /**
     * 读取 wise-agent 的同名 provider 配置（脱离框架时返回空）
     *
     * @return array<string, mixed>
     */
    protected function agentProvider(string $name): array
    {
        if (!function_exists('config')) {
            return [];
        }

        $providers = config('wise-agent.providers', []);
        if (!is_array($providers) || !isset($providers[$name]) || !is_array($providers[$name])) {
            return [];
        }

        return $providers[$name];
    }
}
