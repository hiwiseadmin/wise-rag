<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 Wiki 编译任务表（ai_wiki_compile_jobs）
 *
 * 任务表与审计表二合一：失败原因、token 用量、耗时都落库，
 * 使「编译为什么不更新」永远可回答（与 wise-rag 的 documents.error 同款收口思路）。
 *
 * 列名用 trigger_type 而非 trigger：trigger 是 MySQL 保留字。
 */
class CreateAiWikiCompileJobsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_wiki_compile_jobs', [
            'engine'    => 'InnoDB',
            'comment'   => 'Wiki 编译任务表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('space_id')->setUnsigned()->setDefault(0)->setComment('空间ID'))
            ->addColumn(Column::integer('page_id')->setUnsigned()->setDefault(0)->setComment('页面ID（0=批量）'))
            ->addColumn(Column::string('trigger_type', 16)->setDefault('manual')->setComment('触发来源：manual/schedule/doc_changed/agent'))
            ->addColumn(Column::string('status', 16)->setDefault('queued')->setComment('状态：queued/running/ok/failed/skipped'))
            ->addColumn(Column::string('prompt_version', 16)->setDefault('')->setComment('本次使用的 prompt 版本'))
            ->addColumn(Column::string('model_name', 64)->setDefault('')->setComment('本次使用的模型'))
            ->addColumn(Column::integer('evidence_count')->setUnsigned()->setDefault(0)->setComment('取料条数'))
            ->addColumn(Column::integer('tokens_in')->setUnsigned()->setDefault(0)->setComment('输入 token'))
            ->addColumn(Column::integer('tokens_out')->setUnsigned()->setDefault(0)->setComment('输出 token'))
            ->addColumn(Column::decimal('cost', 12, 6)->setDefault(0)->setComment('估算成本'))
            ->addColumn(Column::integer('latency_ms')->setUnsigned()->setDefault(0)->setComment('耗时毫秒'))
            ->addColumn(Column::tinyInteger('attempt')->setUnsigned()->setDefault(0)->setComment('已尝试次数'))
            ->addColumn(Column::string('error', 500)->setDefault('')->setComment('失败原因'))
            ->addColumn(Column::text('payload')->setNullable()->setComment('入参快照 JSON'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['space_id', 'status'], ['name' => 'idx_space_status'])
            ->addIndex(['page_id'], ['name' => 'idx_page'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_wiki_compile_jobs')) {
            $this->table('ai_wiki_compile_jobs')->drop()->save();
        }
    }
}
