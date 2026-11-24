<?php
declare(strict_types=1);

namespace wise\wiki\security;

/**
 * AI 工具权限提供者（ExtPoints::AI_TOOL_PERMISSION_PROVIDER）
 *
 * 返回结构：ident => [name, description, access, plugin]
 * 由 wise-agent 的权限树汇总，后台「权限管理 → 权限配置」可视化授权；wise-agent 缺失时零副作用。
 */
class WikiPermissionProvider
{
    /** 只读：检索 / 读页 */
    public const PERMISSION_READ = 'ai.tool.wiki_read';

    /** 写入：新建 / 改写页面（需人工确认） */
    public const PERMISSION_WRITE = 'ai.tool.wiki_write';

    /**
     * @return array<string, array<string, string>>
     */
    public function collect(): array
    {
        return [
            self::PERMISSION_READ => [
                'name'        => 'AI 工具：Wiki 读取',
                'description' => '检索与读取 wise-llm-wiki 页面（只读，返回带引用的结论）。',
                'access'      => 'read',
                'plugin'      => 'rag.wiki',
            ],
            self::PERMISSION_WRITE => [
                'name'        => 'AI 工具：Wiki 写入',
                'description' => '由 Agent 新建或改写 wiki 页面（默认只能写 draft，发布需人工）。',
                'access'      => 'write',
                'plugin'      => 'rag.wiki',
            ],
        ];
    }
}
