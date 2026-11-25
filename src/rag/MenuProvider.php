<?php
declare(strict_types=1);

namespace wise\rag;

use wise\support\contracts\MenuItemProvider;

/**
 * wise-rag 后台菜单声明（ExtPoints::MENU_ITEM）
 *
 * 菜单不硬编码 INSERT，而是在此声明；由宿主 `app\admin\service\MenuItemSync`
 * 扫描扩展点幂等落地（含菜单派生权限联动）。升级时以本文件为真相刷新：
 * 改这里再执行 `php think menu:sync` 即可。
 *
 * 挂载位置：系统「插件管理」目录下（与「能力市场」「AI 能力审批」同组）。
 * 派生权限标识：admin.kb.*（如 admin.kb.bases / admin.kb.base_save / admin.kb.stats ...）
 */
class MenuProvider implements MenuItemProvider
{
    public function ident(): string
    {
        return 'wise-rag';
    }

    public function containers(): array
    {
        return [
            [
                'key'    => 'plugin',
                'name'   => '插件管理',
                'anchor' => '插件管理',
            ],
        ];
    }

    public function items(): array
    {
        return [
            [
                'name'        => '知识库管理',
                'icon'        => 'bi bi-journal-text',
                'path'        => '/admin/kb/index',
                'sort'        => 70,
                'type'        => 'menu',
                'status'      => 1,
                'description' => 'RAG 知识库、文档与向量索引管理',
                'child'       => [
                    ['name' => '知识库列表', 'path' => '/admin/kb/bases',           'type' => 'button'],
                    ['name' => '保存知识库', 'path' => '/admin/kb/base/save',       'type' => 'button'],
                    ['name' => '删除知识库', 'path' => '/admin/kb/base/delete',     'type' => 'button'],
                    ['name' => '重建索引',   'path' => '/admin/kb/base/reindex',    'type' => 'button'],
                    ['name' => '文档列表',   'path' => '/admin/kb/documents',       'type' => 'button'],
                    ['name' => '上传文档',   'path' => '/admin/kb/document/upload', 'type' => 'button'],
                    ['name' => '灌入文本',   'path' => '/admin/kb/document/ingest', 'type' => 'button'],
                    ['name' => '删除文档',   'path' => '/admin/kb/document/delete', 'type' => 'button'],
                    ['name' => '知识源列表', 'path' => '/admin/kb/source/list',     'type' => 'button'],
                    ['name' => '同步知识源', 'path' => '/admin/kb/source/sync',     'type' => 'button'],
                    ['name' => '调试检索',   'path' => '/admin/kb/test-retrieval',  'type' => 'button'],
                    ['name' => '统计概览',   'path' => '/admin/kb/stats',           'type' => 'button'],
                ],
            ],
        ];
    }
}
