<?php
declare(strict_types=1);

namespace wise\wiki\exception;

use RuntimeException;

/**
 * wise-llm-wiki 统一异常
 *
 * 约定：
 *  - 对上游（wise-rag / wise-agent）的异常一律由 bridge 层包装成本异常，
 *    上层不感知 RagException / ProviderException 等外部类型＼�；
 *  - 静态工厂返回的异常信息面向运维可读（后台可展示），不得包含 api_key / 完整 URL 等敏感内容。
 */
class WikiException extends RuntimeException
{
    public static function ragUnavailable(): self
    {
        return new self('依赖包 wise-rag 不可用：请先 composer require wiseadmin/wise-rag 并执行迁移。');
    }

    public static function llmUnavailable(): self
    {
        return new self('未接入 AI 能力（wise-agent 未安装或未配置 provider），无法执行 wiki 编译。');
    }

    public static function spaceNotFound(int|string $key): self
    {
        return new self("Wiki 空间不存在：{$key}");
    }

    public static function pageNotFound(int|string $key): self
    {
        return new self("Wiki 页面不存在：{$key}");
    }

    public static function sourceKbMissing(int $kbId): self
    {
        return new self("源知识库不存在或已删除（id={$kbId}），请先检查空间绑定或暂停该空间。");
    }

    public static function shadowKbMissing(int $kbId): self
    {
        return new self("影子知识库缺失（id={$kbId}），请执行 wiki:doctor --rebuild-shadow 修复。");
    }

    public static function locked(string $slug): self
    {
        return new self("页面《{$slug}》正在编译中，请稍后重试。");
    }

    public static function invalidArgument(string $message): self
    {
        return new self($message);
    }
}
