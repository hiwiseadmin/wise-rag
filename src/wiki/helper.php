<?php
declare(strict_types=1);

use wise\wiki\bridge\AgentBridge;
use wise\wiki\bridge\RagBridge;
use wise\wiki\model\WikiSpace;
use wise\wiki\service\SpaceService;

/**
 * wise-llm-wiki 全局辅助函数
 *
 * 经 composer.json 的 autoload.files 自动加载。
 */

if (!function_exists('wise_wiki_enabled')) {
    /**
     * 本包是否启用（宿主可用 config/wise-llm-wiki.php 的 enable 关闭）
     */
    function wise_wiki_enabled(): bool
    {
        return function_exists('config') && (bool) config('wise-rag.wiki.enable', true);
    }
}

if (!function_exists('wise_wiki_rag')) {
    /**
     * wise-rag 调用桥
     */
    function wise_wiki_rag(): RagBridge
    {
        return app(RagBridge::class);
    }
}

if (!function_exists('wise_wiki_agent')) {
    /**
     * wise-agent 调用桥
     */
    function wise_wiki_agent(): AgentBridge
    {
        return app(AgentBridge::class);
    }
}

if (!function_exists('wise_wiki_compilable')) {
    /**
     * 当前环境是否具备编译能力（wise-rag + wise-agent 同时可用）
     */
    function wise_wiki_compilable(): bool
    {
        return RagBridge::available() && AgentBridge::available();
    }
}

if (!function_exists('wise_wiki_space')) {
    /**
     * 按 space_key 或 ID 取空间
     */
    function wise_wiki_space(int|string $key): ?WikiSpace
    {
        return app(SpaceService::class)->get($key);
    }
}

if (!function_exists('wise_wiki_compile')) {
    /**
     * 编译一个主题（同步执行；批量请用 `php think wiki:compile`）
     *
     * @return array<string, mixed>
     */
    function wise_wiki_compile(int|string $spaceKey, string $topic): array
    {
        $space = wise_wiki_space($spaceKey);
        if ($space === null) {
            throw \wise\wiki\exception\WikiException::spaceNotFound($spaceKey);
        }

        return app(\wise\wiki\service\CompileService::class)->compile(
            new \wise\wiki\dto\CompileInput((int) $space->id, $topic)
        );
    }
}
