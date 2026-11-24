<?php
declare(strict_types=1);

namespace wise\wiki;

use wise\support\contracts\MenuItemProvider;

/**
 * wise-llm-wiki 后台菜单声明（ExtPoints::MENU_ITEM）
 *
 * 声明是真相，数据库是运行时：改这里再执行 `php think menu:sync` 即可。
 * 挂载位置：系统「插件管理」目录下（与「知识库管理」同组，RAG 在下、Wiki 在上）。
 * 派生权限标识：admin.wiki.*（如 admin.wiki.index / admin.wiki.page_save ...）
 */
class MenuProvider implements MenuItemProvider
{
    public function ident(): string
    {
        return 'rag.wiki';
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
                'name'        => 'Wiki 知识编译',
                'icon'        => 'bi bi-journal-richtext',
                'path'        => '/admin/rag/wiki/index',
                'sort'        => 72,
                'type'        => 'menu',
                'status'      => 1,
                'description' => '把知识库碎片编译为可维护的 Wiki 页面',
                'child'       => [
                    ['name' => '统计概览',   'path' => '/admin/rag/wiki/stats',           'type' => 'button'],
                    ['name' => '源库下拉',   'path' => '/admin/rag/wiki/kb-list',         'type' => 'button'],
                    ['name' => '空间列表',   'path' => '/admin/rag/wiki/spaces',          'type' => 'button'],
                    ['name' => '保存空间',   'path' => '/admin/rag/wiki/space/save',      'type' => 'button'],
                    ['name' => '删除空间',   'path' => '/admin/rag/wiki/space/delete',    'type' => 'button'],
                    ['name' => '页面列表',   'path' => '/admin/rag/wiki/pages',           'type' => 'button'],
                    ['name' => '页面详情',   'path' => '/admin/rag/wiki/page',            'type' => 'button'],
                    ['name' => '查看版本',   'path' => '/admin/rag/wiki/page/revision',   'type' => 'button'],
                    ['name' => '保存页面',   'path' => '/admin/rag/wiki/page/save',       'type' => 'button'],
                    ['name' => '发布页面',   'path' => '/admin/rag/wiki/page/publish',    'type' => 'button'],
                    ['name' => '归档页面',   'path' => '/admin/rag/wiki/page/archive',    'type' => 'button'],
                    ['name' => '删除页面',   'path' => '/admin/rag/wiki/page/delete',     'type' => 'button'],
                    ['name' => '应用候选版本', 'path' => '/admin/rag/wiki/page/apply',    'type' => 'button'],
                    ['name' => '触发编译',   'path' => '/admin/rag/wiki/compile',         'type' => 'button'],
                    ['name' => '编译任务',   'path' => '/admin/rag/wiki/jobs',            'type' => 'button'],
                    ['name' => '重试任务',   'path' => '/admin/rag/wiki/job/retry',       'type' => 'button'],
                    ['name' => '检索调试',   'path' => '/admin/rag/wiki/test-retrieval',  'type' => 'button'],
                ],
            ],
        ];
    }
}
