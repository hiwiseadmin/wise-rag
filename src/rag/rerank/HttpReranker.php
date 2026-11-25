<?php
declare(strict_types=1);

namespace wise\rag\rerank;

use wise\rag\client\HttpClient;
use wise\rag\contract\RerankerInterface;
use wise\rag\dto\ScoredChunk;
use wise\rag\exception\ProviderException;

/**
 * HTTP 重排驱动
 *
 * 适配常见 rerank 服务（BGE-reranker 服务、Cohere / Jina 等）：
 *   请求  {"model":"...","query":"...","documents":["...","..."]}
 *   响应  {"results":[{"index":0,"relevance_score":0.87}, ...]}   （或 data 字段）
 *
 * 配置：`wise-rag.rerank.providers.{name}` → endpoint / api_key / model / timeout
 */
class HttpReranker implements RerankerInterface
{
    protected array $config;

    protected HttpClient $http;

    public function __construct(array $config = [])
    {
        $this->config = $config + [
            'name'     => 'http',
            'endpoint' => '',
            'api_key'  => '',
            'model'    => '',
            'timeout'  => 30,
        ];

        $this->http = new HttpClient(['timeout' => (int) $this->config['timeout']]);
    }

    public function rerank(string $query, array $candidates, int $topN): array
    {
        if ($candidates === []) {
            return [];
        }

        $endpoint = (string) $this->config['endpoint'];
        if ($endpoint === '') {
            throw new ProviderException('重排服务未配置 endpoint');
        }

        $payload = [
            'query'     => $query,
            'documents' => array_map(static fn (ScoredChunk $c): string => $c->content, $candidates),
        ];
        if ((string) $this->config['model'] !== '') {
            $payload['model'] = (string) $this->config['model'];
        }

        $headers = [];
        if ((string) $this->config['api_key'] !== '') {
            $headers['Authorization'] = 'Bearer ' . (string) $this->config['api_key'];
        }

        $response = $this->http->postJson($endpoint, $payload, $headers);
        $results  = $response['results'] ?? $response['data'] ?? null;

        if (!is_array($results) || $results === []) {
            return array_slice($candidates, 0, max(1, $topN));
        }

        $ranked = [];
        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $index = (int) ($item['index'] ?? -1);
            if (!isset($candidates[$index])) {
                continue;
            }

            $chunk        = $candidates[$index];
            $ranked[]     = new ScoredChunk(
                $chunk->chunkId,
                $chunk->docId,
                $chunk->content,
                (float) ($item['relevance_score'] ?? $item['score'] ?? $chunk->score),
                $chunk->vectorScore,
                $chunk->keywordScore,
                $chunk->meta
            );
        }

        if ($ranked === []) {
            return array_slice($candidates, 0, max(1, $topN));
        }

        usort($ranked, static fn (ScoredChunk $a, ScoredChunk $b): int => $b->score <=> $a->score);

        return array_slice($ranked, 0, max(1, $topN));
    }

    public function getName(): string
    {
        return (string) $this->config['name'];
    }
}
