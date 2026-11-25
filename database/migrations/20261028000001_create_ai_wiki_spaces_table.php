<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 Wiki 空间表（ai_wiki_spaces）
 *
 * 一个空间 = 一个源知识库（编译输入）+ 一个影子知识库（页面索引）。
 * 影子库的存在是为了让编译取料（kbIds 白名单只含源库）在物理上不可能读到自己的产物。
 */
class CreateAiWikiSpacesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_wiki_spaces', [
            'engine'    => 'InnoDB',
            'comment'   => 'Wiki 空间表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::string('space_key', 64)->setDefault('')->setComment('空间标识（小写+下划线）'))
            ->addColumn(Column::string('name', 128)->setDefault('')->setComment('空间名称'))
            ->addColumn(Column::text('description')->setNullable()->setComment('描述'))
            ->addColumn(Column::integer('source_kb_id')->setUnsigned()->setDefault(0)->setComment('源知识库ID（ai_kb_knowledge_bases）'))
            ->addColumn(Column::integer('shadow_kb_id')->setUnsigned()->setDefault(0)->setComment('影子知识库ID（页面索引目标）'))
            ->addColumn(Column::string('owner_type', 32)->setDefault('admin')->setComment('归属类型'))
            ->addColumn(Column::integer('owner_id')->setUnsigned()->setDefault(0)->setComment('归属ID'))
            ->addColumn(Column::string('visibility', 16)->setDefault('private')->setComment('可见性：private/tenant/public'))
            ->addColumn(Column::text('compile_policy')->setNullable()->setComment('编译策略 JSON'))
            ->addColumn(Column::string('model_provider', 32)->setDefault('')->setComment('LLM provider（空=wise-agent.default）'))
            ->addColumn(Column::string('model_name', 64)->setDefault('')->setComment('模型名（空=provider 默认）'))
            ->addColumn(Column::string('prompt_version', 16)->setDefault('')->setComment('当前编译 prompt 版本'))
            ->addColumn(Column::integer('budget_tokens_month')->setUnsigned()->setDefault(0)->setComment('月度 token 预算（0=不限）'))
            ->addColumn(Column::integer('tokens_used_month')->setUnsigned()->setDefault(0)->setComment('本月已用 token'))
            ->addColumn(Column::integer('page_count')->setUnsigned()->setDefault(0)->setComment('页面数（派生）'))
            ->addColumn(Column::integer('dirty_count')->setUnsigned()->setDefault(0)->setComment('待重编译页面数（派生）'))
            ->addColumn(Column::string('status', 16)->setDefault('active')->setComment('状态：active/paused/disabled'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['space_key'], ['unique' => true, 'name' => 'uk_space_key'])
            ->addIndex(['source_kb_id'], ['name' => 'idx_source_kb'])
            ->addIndex(['owner_type', 'owner_id'], ['name' => 'idx_owner'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_wiki_spaces')) {
            $this->table('ai_wiki_spaces')->drop()->save();
        }
    }
}
