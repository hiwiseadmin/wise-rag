<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建知识库表（ai_kb_knowledge_bases）
 */
class CreateAiKbKnowledgeBasesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_kb_knowledge_bases', [
            'engine'    => 'InnoDB',
            'comment'   => '知识库表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::string('kb_key', 64)->setNull(false)->setComment('知识库唯一标识'))
            ->addColumn(Column::string('name', 128)->setNull(false)->setComment('名称'))
            ->addColumn(Column::text('description')->setNullable()->setComment('描述'))
            ->addColumn(Column::string('owner_type', 32)->setDefault('admin')->setComment('归属类型：admin/member/system'))
            ->addColumn(Column::integer('owner_id')->setUnsigned()->setDefault(0)->setComment('归属ID（0=全局）'))
            ->addColumn(Column::string('visibility', 16)->setDefault('private')->setComment('可见性：private/tenant/public'))
            ->addColumn(Column::string('embedding', 64)->setDefault('')->setComment('Embedding Provider 名'))
            ->addColumn(Column::string('embed_model', 64)->setDefault('')->setComment('实际模型（维度/模型一致性校验）'))
            ->addColumn(Column::integer('dimension')->setUnsigned()->setDefault(0)->setComment('向量维度'))
            ->addColumn(Column::string('vector_store', 32)->setDefault('database')->setComment('向量存储驱动'))
            ->addColumn(Column::string('collection', 64)->setDefault('')->setComment('向量集合名'))
            ->addColumn(Column::text('chunk_config')->setNullable()->setComment('分块参数 JSON'))
            ->addColumn(Column::text('retrieval_config')->setNullable()->setComment('检索参数 JSON'))
            ->addColumn(Column::integer('doc_count')->setUnsigned()->setDefault(0)->setComment('文档数'))
            ->addColumn(Column::integer('chunk_count')->setUnsigned()->setDefault(0)->setComment('分块数'))
            ->addColumn(Column::integer('vector_count')->setUnsigned()->setDefault(0)->setComment('已向量化分块数（避免统计时扫描 vector 大列）'))
            ->addColumn(Column::string('status', 16)->setDefault('active')->setComment('状态：active/indexing/error/disabled'))
            ->addColumn(Column::text('meta')->setNullable()->setComment('元数据 JSON'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['kb_key'], ['unique' => true, 'name' => 'uk_kb_key'])
            ->addIndex(['owner_type', 'owner_id'], ['name' => 'idx_owner'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_kb_knowledge_bases')) {
            $this->table('ai_kb_knowledge_bases')->drop()->save();
        }
    }
}
