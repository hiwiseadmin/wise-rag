<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建知识文档表（ai_kb_documents）
 */
class CreateAiKbDocumentsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_kb_documents', [
            'engine'    => 'InnoDB',
            'comment'   => '知识文档表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('kb_id')->setUnsigned()->setDefault(0)->setComment('知识库ID'))
            ->addColumn(Column::integer('source_id')->setUnsigned()->setDefault(0)->setComment('知识源ID'))
            ->addColumn(Column::string('external_id', 128)->setNullable()->setComment('源侧业务ID（幂等键）'))
            ->addColumn(Column::string('title', 255)->setDefault('')->setComment('标题'))
            ->addColumn(Column::string('mime', 64)->setNullable()->setComment('MIME 类型'))
            ->addColumn(Column::string('file_path', 512)->setNullable()->setComment('附件 / 本地路径'))
            ->addColumn(Column::string('url', 512)->setNullable()->setComment('引用溯源链接'))
            ->addColumn(Column::string('content_hash', 64)->setDefault('')->setComment('解析后文本 hash（增量判断）'))
            ->addColumn(Column::integer('char_count')->setUnsigned()->setDefault(0)->setComment('字符数'))
            ->addColumn(Column::integer('chunk_count')->setUnsigned()->setDefault(0)->setComment('分块数'))
            ->addColumn(Column::string('owner_type', 32)->setDefault('admin')->setComment('归属类型'))
            ->addColumn(Column::integer('owner_id')->setUnsigned()->setDefault(0)->setComment('归属ID'))
            ->addColumn(Column::string('status', 16)->setDefault('pending')->setComment('状态：pending/parsed/indexed/error/skipped'))
            ->addColumn(Column::text('error')->setNullable()->setComment('错误信息'))
            ->addColumn(Column::text('meta')->setNullable()->setComment('元数据 JSON'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['kb_id', 'external_id'], ['unique' => true, 'name' => 'uk_kb_ext'])
            ->addIndex(['kb_id', 'status'], ['name' => 'idx_kb'])
            ->addIndex(['owner_type', 'owner_id'], ['name' => 'idx_owner'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_kb_documents')) {
            $this->table('ai_kb_documents')->drop()->save();
        }
    }
}
