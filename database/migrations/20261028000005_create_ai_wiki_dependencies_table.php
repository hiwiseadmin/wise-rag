<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 Wiki 源依赖表（ai_wiki_dependencies）
 *
 * 这是**增量重编译的索引**：源文档变化时按 doc_id 反查受影响页面。
 * 必须记录 doc_hash —— 否则 wise:rag:index --force 重放全量 DocumentIndexed 事件时，
 * 「事件到了但内容没变」也会触发重编译，一晚烧掉整月预算。
 */
class CreateAiWikiDependenciesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_wiki_dependencies', [
            'engine'    => 'InnoDB',
            'comment'   => 'Wiki 页面源依赖表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('page_id')->setUnsigned()->setDefault(0)->setComment('页面ID'))
            ->addColumn(Column::integer('kb_id')->setUnsigned()->setDefault(0)->setComment('源知识库ID'))
            ->addColumn(Column::integer('doc_id')->setUnsigned()->setDefault(0)->setComment('源文档ID'))
            ->addColumn(Column::integer('chunk_id')->setUnsigned()->setDefault(0)->setComment('源分块ID'))
            ->addColumn(Column::string('doc_hash', 64)->setDefault('')->setComment('引用时的文档 content_hash'))
            ->addColumn(Column::integer('cite_no')->setUnsigned()->setDefault(0)->setComment('页面内的引用编号'))
            ->addColumn(Column::tinyInteger('cited')->setUnsigned()->setDefault(1)->setComment('是否被正文实际引用'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addIndex(['page_id', 'doc_id', 'chunk_id'], ['unique' => true, 'name' => 'uk_dep'])
            ->addIndex(['doc_id'], ['name' => 'idx_doc'])
            ->addIndex(['page_id'], ['name' => 'idx_page'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_wiki_dependencies')) {
            $this->table('ai_wiki_dependencies')->drop()->save();
        }
    }
}
