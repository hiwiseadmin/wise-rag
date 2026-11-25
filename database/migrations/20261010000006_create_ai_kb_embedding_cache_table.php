<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建向量缓存表（ai_kb_embedding_cache）
 *
 * 语义缓存：以「文本 md5 + 模型」为键缓存 embedding 结果，避免重复调用（省成本）。
 */
class CreateAiKbEmbeddingCacheTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_kb_embedding_cache', [
            'engine'    => 'InnoDB',
            'comment'   => '向量缓存表（语义缓存）',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::string('hash', 64)->setComment('文本 md5'))
            ->addColumn(Column::string('model', 128)->setDefault('')->setComment('embedding 模型'))
            ->addColumn(Column::integer('dimension')->setUnsigned()->setDefault(0)->setComment('向量维度'))
            ->addColumn('vector', 'blob', ['null' => true, 'comment' => 'float32 序列化'])
            ->addColumn(Column::integer('hits')->setUnsigned()->setDefault(0)->setComment('命中次数'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['hash', 'model'], ['unique' => true, 'name' => 'uk_hash_model'])
            ->addIndex(['update_time'], ['name' => 'idx_update_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_kb_embedding_cache')) {
            $this->table('ai_kb_embedding_cache')->drop()->save();
        }
    }
}
