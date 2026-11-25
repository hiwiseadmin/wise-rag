<?php
declare(strict_types=1);

namespace wise\rag\exception;

/**
 * Embedding / 向量存储 / 重排 Provider 调用失败
 *
 * 约定（重要）：
 *   - `message` 面向使用者（后台弹窗 / MCP 工具结果 / LLM 工具结果），必须是**可安全展示**的文案；
 *   - `context` 仅用于日志，可能包含上游响应体（含密钥回显）等敏感信息，**不得**回传前端或 LLM。
 *
 * `$context` 带默认值，历史 `new ProviderException('...')` 单参调用不受影响。
 */
class ProviderException extends RagException
{
    /**
     * @param array<string, mixed> $context 仅用于日志的上下文（状态码 / URL / 脱敏后的响应体片段等）
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        protected array $context = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
