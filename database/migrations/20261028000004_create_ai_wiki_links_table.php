<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 Wiki 页面链接表（ai_wiki_links）
 *
 * 页面图：支撑「先读图再下钻」的检索路径，也让编译期能发现矛盾与重复主题。
 */
class CreateAiWikiLinksTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_wiki_links', [
            'engine'    => 'InnoDB',
            'comment'   => 'Wiki 页面链接表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('space_id')->setUnsigned()->setDefault(0)->setComment('空间ID'))
            ->addColumn(Column::integer('from_page_id')->setUnsigned()->setDefault(0)->setComment('源页面ID'))
            ->addColumn(Column::integer('to_page_id')->setUnsigned()->setDefault(0)->setComment('目标页面ID'))
            ->addColumn(Column::string('rel_type', 16)->setDefault('related')->setComment('关系：related/depends/contradicts/part_of'))
            ->addColumn(Column::decimal('weight', 5, 4)->setDefault(1)->setComment('权重 0~1'))
            ->addColumn(Column::tinyInteger('auto')->setUnsigned()->setDefault(1)->setComment('是否由编译自动生成'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addIndex(['from_page_id', 'to_page_id', 'rel_type'], ['unique' => true, 'name' => 'uk_link'])
            ->addIndex(['from_page_id'], ['name' => 'idx_from'])
            ->addIndex(['to_page_id'], ['name' => 'idx_to'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_wiki_links')) {
            $this->table('ai_wiki_links')->drop()->save();
        }
    }
}
