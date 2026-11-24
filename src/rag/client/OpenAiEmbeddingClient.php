<?php
declare(strict_types=1);

namespace wise\rag\client;

use wise\rag\contract\EmbeddingInterface;
use wise\rag\exception\ProviderException;

/**
 * OpenAI 协议兼容的 Embedding 客户端
 *
 * 适用于任何暴露 `/embeddings` 的 OpenAI 协议端点：OpenAI / 智谱 / 通义 /
 * Ollama / 自建 BGE 服务等。配置项见 `wise-rag.embedding.providers.*`。
 */
class OpenAiEmbeddingClient implements EmbeddingInterface
{
    protected array $config;

    protected HttpClient $http;

    /** 首次成功响应中探测到的维度（配置未声明时回填） */
    protected ?int $discoveredDimension = null;

    public function __construct(array $config = [])
    {
        $this->config = $config + [
            'name'       => 'openai',
            'api_key'    => '',
            'base_url'   => 'https://api.openai.com/v1',
            'model'      => 'text-embedding-3-small',
            'dimension'  => 1536,
            'batch_size' => 64,
            'timeout'    => 30,
        ];

        $this->http = new HttpClient(['timeout' => (int) $this->config['timeout']]);
    }

    public function embed(array $texts, array $options = []): array
    {
        $texts = array_values(array_map(static fn ($t): string => (string) $t, $texts));
        if ($texts === []) {
            return [];
        }

        foreach ($texts as $i => $text) {
            if (trim($text) === '') {
                throw new ProviderException("Embedding 输入第 {$i} 项为空字符串，请调用方先过滤");
            }
        }

        $model = (string) ($options['model'] ?? $this->config['model']);
        $batch = max(1, (int) ($options['batch_size'] ?? $this->config['batch_size']));

        $vectors = [];
        foreach (array_chunk($texts, $batch) as $chunk) {
            $response = $this->http->postJson(
                $this->endpoint(),
                ['model' => $model, 'input' => array_values($chunk)],
                $this->headers()
            );

            $data = $response['data'] ?? null;
            if (!is_array($data)) {
                throw new ProviderException('Embedding 响应缺少 data 字段');
            }

            usort($data, static fn ($a, $b): int => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));
            foreach ($data as $item) {
                $embedding = array_map('floatval', (array) ($item['embedding'] ?? []));
                if ($embedding === []) {
                    throw new ProviderException('Embedding 响应包含空向量');
                }
                $vectors[] = $embedding;
            }
        }

        if (isset($vectors[0])) {
            $this->discoveredDimension = count($vectors[0]);
        }

        return $vectors;
    }

    public function getDimension(): int
    {
        $configured = (int) $this->config['dimension'];

        return $configured > 0 ? $configured : (int) ($this->discoveredDimension ?? 0);
    }

    public function getModel(): string
    {
        return (string) $this->config['model'];
    }

    public function getName(): string
    {
        return (string) $this->config['name'];
    }

    protected function endpoint(): string
    {
        return rtrim((string) $this->config['base_url'], '/') . '/embeddings';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $apiKey = (string) $this->config['api_key'];
        if ($apiKey === '') {
            throw new ProviderException('Embedding 缺少 api_key（可在 wise-rag.embedding.providers 配置，或经 embedding.source 复用 wise-agent.providers）');
        }

        return ['Authorization' => 'Bearer ' . $apiKey];
    }
}
