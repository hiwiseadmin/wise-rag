<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 Wiki 页面版本表（ai_wiki_page_revisions）
 *
 * 每次编译 / 人工编辑都追一条；带 prompt_version 与 token 用量，便于回溯「这版是怎么来的、花了多少」。
 */
class CreateAiWikiPageRevisionsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_wiki_page_revisions', [
            'engine'    => 'InnoDB',
            'comment'   => 'Wiki 页面版本表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('page_id')->setUnsigned()->setDefault(0)->setComment('页面ID'))
            ->addColumn(Column::integer('rev_no')->setUnsigned()->setDefault(0)->setComment('版本号'))
            ->addColumn(Column::text('content_md')->setNullable()->setComment('本版正文'))
            ->addColumn(Column::string('summary', 500)->setDefault('')->setComment('本版摘要'))
            ->addColumn(Column::text('outline')->setNullable()->setComment('本版引用映射 JSON'))
            ->addColumn(Column::string('prompt_version', 16)->setDefault('')->setComment('生成该版的 prompt 版本'))
            ->addColumn(Column::string('model_name', 64)->setDefault('')->setComment('生成该版的模型'))
            ->addColumn(Column::integer('tokens_in')->setUnsigned()->setDefault(0)->setComment('输入 token'))
            ->addColumn(Column::integer('tokens_out')->setUnsigned()->setDefault(0)->setComment('输出 token'))
            ->addColumn(Column::decimal('cost', 12, 6)->setDefault(0)->setComment('估算成本'))
            ->addColumn(Column::string('change_reason', 32)->setDefault('')->setComment('变更原因：compile/rebuild/rebuild_from_sources/manual'))
            ->addColumn(Column::string('diff_summary', 500)->setDefault('')->setComment('变更摘要'))
            ->addColumn(Column::string('editor_type', 16)->setDefault('system')->setComment('编辑者类型：system/admin/agent'))
            ->addColumn(Column::integer('editor_id')->setUnsigned()->setDefault(0)->setComment('编辑者ID'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addIndex(['page_id', 'rev_no'], ['unique' => true, 'name' => 'uk_page_rev'])
            ->addIndex(['page_id'], ['name' => 'idx_page'])
            ->create();

        $tableName = (string) Db::name('ai_wiki_page_revisions')->getTable();
        $this->execute(
            "ALTER TABLE `{$tableName}` MODIFY `content_md` LONGTEXT NULL COMMENT '本版正文'"
        );
    }

    public function down(): void
    {
        if ($this->hasTable('ai_wiki_page_revisions')) {
            $this->table('ai_wiki_page_revisions')->drop()->save();
        }
    }
}
