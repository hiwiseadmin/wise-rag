<?php
declare(strict_types=1);

namespace wise\rag\support;

use wise\rag\exception\ProviderException;

/**
 * 异常展示出口：把「可展示消息」与「仅日志详情」分离
 *
 * ProviderException 的 message 已是脱敏文案，而 context 中可能含上游响应体
 * （部分网关会把 Authorization 头原样回显）。统一经此处转出：详情写日志，
 * 返回值才允许交给前端弹窗 / MCP 工具结果 / LLM 工具结果。
 */
final class ErrorPresenter
{
    /**
     * 返回可安全展示给外部的消息
     */
    public static function message(\Throwable $e): string
    {
        if ($e instanceof ProviderException && $e->getContext() !== []) {
            error_log('[wise-rag] Provider 详情: ' . json_encode($e->getContext(), JSON_UNESCAPED_UNICODE));
        }

        return $e->getMessage();
    }
}
