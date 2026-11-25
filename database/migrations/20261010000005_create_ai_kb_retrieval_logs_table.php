<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建知识检索日志表（ai_kb_retrieval_logs）—— 检索观测＼�
 */
class CreateAiKbRetrievalLogsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_kb_retrieval_logs', [
            'engine'    => 'InnoDB',
            'comment'   => '知识检索日志表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('kb_id')->setUnsigned()->setDefault(0)->setComment('知识库ID'))
            ->addColumn(Column::text('query')->setComment('查询文本'))
            ->addColumn(Column::integer('hit_count')->setUnsigned()->setDefault(0)->setComment('命中条数'))
            ->addColumn(Column::decimal('top_score', 6, 4)->setNullable()->setComment('最高分'))
            ->addColumn(Column::integer('latency_ms')->setUnsigned()->setDefault(0)->setComment('耗时（毫秒）'))
            ->addColumn(Column::string('user_type', 32)->setDefault('admin')->setComment('用户类型'))
            ->addColumn(Column::integer('user_id')->setUnsigned()->setDefault(0)->setComment('用户ID'))
            ->addColumn(Column::string('session_id', 64)->setNullable()->setComment('会话ID'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addIndex(['kb_id', 'create_time'], ['name' => 'idx_kb_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_kb_retrieval_logs')) {
            $this->table('ai_kb_retrieval_logs')->drop()->save();
        }
    }
}
