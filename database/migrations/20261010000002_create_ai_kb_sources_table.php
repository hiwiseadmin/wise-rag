<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建知识源表（ai_kb_sources）
 */
class CreateAiKbSourcesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_kb_sources', [
            'engine'    => 'InnoDB',
            'comment'   => '知识源表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('kb_id')->setUnsigned()->setDefault(0)->setComment('知识库ID'))
            ->addColumn(Column::string('source_key', 128)->setNull(false)->setComment('源唯一 key，如 smartstock:product_docs'))
            ->addColumn(Column::string('origin', 32)->setDefault('manual')->setComment('来源：manual/plugin/table/attachment'))
            ->addColumn(Column::string('provider', 32)->setDefault('manual')->setComment('知识源驱动名'))
            ->addColumn(Column::text('config')->setNullable()->setComment('源配置 JSON（表名/字段/过滤）'))
            ->addColumn(Column::string('sync_mode', 16)->setDefault('manual')->setComment('同步模式：manual/on_change/cron'))
            ->addColumn(Column::string('cron', 64)->setNullable()->setComment('cron 表达式'))
            ->addColumn(Column::string('checkpoint', 128)->setNullable()->setComment('增量水位'))
            ->addColumn(Column::integer('last_sync')->setUnsigned()->setNullable()->setComment('最近同步时间戳'))
            ->addColumn(Column::string('status', 16)->setDefault('active')->setComment('状态：active/disabled'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['kb_id', 'source_key'], ['unique' => true, 'name' => 'uk_kb_source'])
            ->addIndex(['sync_mode', 'status'], ['name' => 'idx_sync'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_kb_sources')) {
            $this->table('ai_kb_sources')->drop()->save();
        }
    }
}
