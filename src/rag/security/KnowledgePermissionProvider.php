<?php
declare(strict_types=1);

namespace wise\rag\security;

/**
 * AI 工具权限提供者（ExtPoints::AI_TOOL_PERMISSION_PROVIDER）
 *
 * 向 wise-agent 的权限树（ToolPermissionRegistrar）补充 wise-rag 的检索能力权限，
 * 使其出现在后台「权限管理 → 权限配置」中，可按角色可视化授权。
 *
 * 返回结构：ident => [name, description, access, plugin]
 */
class KnowledgePermissionProvider
{
    /** 检索能力权限标识（写入 ToolInterface::getPermission()） */
    public const PERMISSION = 'ai.tool.kb_search';

    /**
     * @return array<string, array<string, string>>
     */
    public function collect(): array
    {
        return [
            self::PERMISSION => [
                'name'        => 'AI 工具：知识库检索',
                'description' => '检索 wise-rag 知识库（只读），返回带引用溯源的片段。',
                'access'      => 'read',
                'plugin'      => 'wise-rag',
            ],
        ];
    }
}
