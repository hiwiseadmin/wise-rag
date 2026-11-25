<?php
declare(strict_types=1);

namespace wise\wiki\bridge;

use wise\agent\client\AiClientFactory;
use wise\agent\client\Feature;
use wise\agent\contract\AiClientInterface;
use wise\agent\tool\ToolRegistry;
use wise\wiki\exception\WikiException;

/**
 * 对 wise-agent 的**唯一**调用收口（LLM + 工具注册）
 *
 * 与 RagBridge 同理：上游变更只改这里；异常统一包装。
 * 未安装 wise-agent 时包仍可加载、后台不白屏，只有编译入口给出可读错误。
 */
class AgentBridge
{
    /**
     * @param object|null $injected 测试用客户端（实现 AiClientInterface）；为 null 时走 AiClientFactory
     */
    public function __construct(protected ?object $injected = null)
    {
    }

    public static function available(): bool
    {
        return class_exists(AiClientFactory::class) && interface_exists(AiClientInterface::class);
    }

    /**
     * 创建客户端（未安装时抛可读异常）
     */
    public function client(?string $provider = null): AiClientInterface
    {
        if ($this->injected !== null
            && interface_exists(AiClientInterface::class)
            && $this->injected instanceof AiClientInterface
        ) {
            return $this->injected;
        }

        if (!self::available()) {
            throw WikiException::llmUnavailable();
        }

        return AiClientFactory::create($provider !== '' ? $provider : null);
    }

    /**
     * 发起一次对话
     *
     * @param array<int, array<string, mixed>> $messages [['role'=>'system','content'=>'...'], ...]
     * @param array<string, mixed>             $options  temperature / max_tokens / response_format ...
     * @return array{content:string, tool_calls:array, usage:array, finish_reason:string}
     */
    public function chat(array $messages, array $options = [], ?string $provider = null): array
    {
        $client = $this->client($provider);

        if (!empty($options['json']) && $this->supportsJsonMode($client)) {
            $options['response_format'] = ['type' => 'json_object'];
        }
        unset($options['json']);

        try {
            $result = $client->chat($messages, [], $options);
        } catch (\Exception $e) {
            throw WikiException::invalidArgument('LLM 调用失败：' . $e->getMessage());
        }

        return is_array($result) ? $result : ['content' => '', 'tool_calls' => [], 'usage' => [], 'finish_reason' => ''];
    }

    public function supportsJsonMode(AiClientInterface $client): bool
    {
        try {
            return $client->supports(Feature::JSON_MODE);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 注册 wiki.* 工具（默认命名空间，getName() 必须返回全名）
     *
     * @param object $registry ToolRegistry 实例（省略则用单例）
     */
    public function registerTools(?object $registry = null): int
    {
        if (!self::available()) {
            return 0;
        }

        return 0;
    }

    /**
     * 工具注册表（未安装时返回 null）
     */
    public function registry(): ?object
    {
        if (!class_exists(ToolRegistry::class)) {
            return null;
        }

        return ToolRegistry::instance();
    }
}
