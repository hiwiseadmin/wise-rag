<?php
declare(strict_types=1);

namespace wise\rag\client;

use wise\rag\exception\ProviderException;
use wise\support\runtime\Runtime;

/**
 * 轻量 HTTP 客户端（cURL）
 *
 * 仅服务于 Embedding / 重排等出网请求：POST JSON、超时、指数退避重试。
 * 4xx 视为不可重试（多为鉴权 / 参数错误），5xx 与网络错误重试。
 */
class HttpClient
{
    protected array $options;

    protected array $defaultOptions = [
        'timeout'         => 30,
        'connect_timeout' => 10,
        'max_retry'       => 2,
        'retry_delay_ms'  => 500,
        'headers'         => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
    ];

    public function __construct(array $options = [])
    {
        $this->options = array_replace_recursive($this->defaultOptions, $options);
    }

    /**
     * 发送 POST JSON 请求并返回解码后的响应体
     *
     * @throws ProviderException
     */
    public function postJson(string $url, array $payload, array $headers = []): array
    {
        if (!function_exists('curl_init')) {
            throw new ProviderException('HTTP 请求需要 ext-curl 扩展');
        }

        $attempts    = max(1, (int) $this->options['max_retry'] + 1);
        $lastError   = '';
        $lastContext = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => $this->buildHeaders($headers),
                CURLOPT_TIMEOUT        => (int) $this->options['timeout'],
                CURLOPT_CONNECTTIMEOUT => (int) $this->options['connect_timeout'],
            ]);

            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                $lastError   = '网络错误';
                $lastContext = ['url' => $url, 'curl_error' => $err];
                $this->backoff($attempt);
                continue;
            }

            if ($status >= 200 && $status < 300) {
                $decoded = json_decode((string) $body, true);
                if (!is_array($decoded)) {
                    throw new ProviderException(
                        '上游返回了无法解析的响应，请确认 base_url 指向 OpenAI 兼容的 /embeddings 端点',
                        0,
                        null,
                        $this->context($url, $status, (string) $body)
                    );
                }

                return $decoded;
            }

            if ($status >= 400 && $status < 500) {
                throw new ProviderException(
                    "上游服务拒绝请求（HTTP {$status}），请检查 api_key / base_url / 模型名",
                    0,
                    null,
                    $this->context($url, $status, (string) $body)
                );
            }

            $lastError   = $status > 0 ? "HTTP {$status}" : '网络错误';
            $lastContext = $status > 0 ? $this->context($url, $status, (string) $body) : ['url' => $url];
            $this->backoff($attempt);
        }

        error_log('[wise-rag] HTTP 请求失败: ' . json_encode($lastContext, JSON_UNESCAPED_UNICODE));

        throw new ProviderException(
            '上游服务暂时不可用' . ($lastError === '' ? '' : "（{$lastError}）") . '，请稍后重试或检查服务状态',
            0,
            null,
            $lastContext
        );
    }

    /**
     * @param array<string, string>|array<int, string> $headers
     * @return string[]
     */
    protected function buildHeaders(array $headers): array
    {
        $merged = array_replace((array) $this->options['headers'], $headers);

        $out = [];
        foreach ($merged as $key => $value) {
            $out[] = is_int($key) ? (string) $value : "{$key}: {$value}";
        }

        return $out;
    }

    protected function backoff(int $attempt): void
    {
        $ms = (int) $this->options['retry_delay_ms'];
        if ($ms <= 0 || $attempt <= 0) {
            return;
        }

        $microseconds = $ms * 1000 * $attempt;

        Runtime::sleep($microseconds / 1_000_000);
    }

    /**
     * 构造仅用于日志的异常上下文（响应体先脱敏再截断）
     *
     * @return array<string, mixed>
     */
    protected function context(string $url, int $status, string $body): array
    {
        return [
            'url'    => $url,
            'status' => $status,
            'body'   => mb_substr($this->redact($body), 0, 1000),
        ];
    }

    /**
     * 脱敏：屏蔽常见的密钥回显（上游错误体可能把请求头原样返回）
     */
    protected function redact(string $body): string
    {
        return (string) preg_replace(
            [
                '/sk-[A-Za-z0-9_\-]{8,}/',
                '/(Bearer\s+)[A-Za-z0-9\._\-]+/i',
                '/(api[_-]?key["\'\s:=]+)[A-Za-z0-9\._\-]{8,}/i',
            ],
            ['sk-***', '$1***', '$1***'],
            $body
        );
    }
}
