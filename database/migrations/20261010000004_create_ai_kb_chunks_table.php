<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;
use think\facade\Db;

/**
 * 创建知识分块表（ai_kb_chunks）
 *
 * 含默认向量列（database 驱动：float32 序列化）与关键词检索用全文索引。
 */
class CreateAiKbChunksTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_kb_chunks', [
            'engine'    => 'InnoDB',
            'comment'   => '知识分块表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('kb_id')->setUnsigned()->setDefault(0)->setComment('知识库ID'))
            ->addColumn(Column::integer('doc_id')->setUnsigned()->setDefault(0)->setComment('文档ID'))
            ->addColumn(Column::integer('chunk_index')->setUnsigned()->setDefault(0)->setComment('块序号'))
            ->addColumn(Column::text('content')->setComment('分块文本'))
            ->addColumn(Column::integer('token_count')->setUnsigned()->setDefault(0)->setComment('token 数'))
            ->addColumn(Column::string('heading', 255)->setNullable()->setComment('所属标题路径'))
            ->addColumn(Column::integer('page')->setUnsigned()->setNullable()->setComment('页码'))
            ->addColumn(Column::string('content_hash', 64)->setDefault('')->setComment('分块文本 hash（避免重复 embedding）'))
            ->addColumn('vector', 'blob', ['null' => true, 'comment' => '默认 database 驱动：float32 序列化'])
            ->addColumn(Column::integer('dimension')->setUnsigned()->setNullable()->setComment('向量维度'))
            ->addColumn(Column::string('owner_type', 32)->setDefault('admin')->setComment('归属类型'))
            ->addColumn(Column::integer('owner_id')->setUnsigned()->setDefault(0)->setComment('归属ID'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['doc_id'], ['name' => 'idx_doc'])
            ->addIndex(['kb_id'], ['name' => 'idx_kb'])
            ->addIndex(['kb_id', 'owner_type', 'owner_id'], ['name' => 'idx_scope'])
            ->create();

        $this->addFulltextIndex();
    }

    /**
     * 为 content 建全文索引（关键词检索默认驱动 mysql_fulltext）。
     *
     * 失败静默跳过：未建索引时关键词驱动会自动回退为 LIKE。
     * 中文依赖 ngram 分词器（MySQL 5.7+ 内置），故优先 ngram，退化普通 FULLTEXT。
     */
    protected function addFulltextIndex(): void
    {
        $tableName = (string) Db::name('ai_kb_chunks')->getTable();

        try {
            $this->execute(
                "ALTER TABLE `{$tableName}` ADD FULLTEXT `ft_kb_chunks_content` (`content`) WITH PARSER ngram"
            );
            return;
        } catch (\Throwable $e) {
        }

        try {
            $this->execute(
                "ALTER TABLE `{$tableName}` ADD FULLTEXT `ft_kb_chunks_content` (`content`)"
            );
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        if ($this->hasTable('ai_kb_chunks')) {
            $this->table('ai_kb_chunks')->drop()->save();
        }
    }
}
