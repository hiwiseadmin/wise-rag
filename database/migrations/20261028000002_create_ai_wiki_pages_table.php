<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 Wiki 页面表（ai_wiki_pages）
 *
 * 页面正文是 wiki 的**唯一真源**；影子知识库里的文档只是派生物（可随时重建）。
 */
class CreateAiWikiPagesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_wiki_pages', [
            'engine'    => 'InnoDB',
            'comment'   => 'Wiki 页面表（真源）',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn(Column::integer('space_id')->setUnsigned()->setDefault(0)->setComment('空间ID'))
            ->addColumn(Column::string('slug', 128)->setDefault('')->setComment('空间内唯一标识（kebab-case）'))
            ->addColumn(Column::string('title', 255)->setDefault('')->setComment('标题'))
            ->addColumn(Column::string('kind', 16)->setDefault('topic')->setComment('类型：topic/entity/decision/faq/glossary'))
            ->addColumn(Column::string('status', 16)->setDefault('draft')->setComment('状态：draft/review/published/archived'))
            ->addColumn(Column::text('content_md')->setNullable()->setComment('页面正文 Markdown'))
            ->addColumn(Column::string('summary', 500)->setDefault('')->setComment('一句话摘要'))
            ->addColumn(Column::text('outline')->setNullable()->setComment('段落 → 引用 映射 JSON'))
            ->addColumn(Column::integer('rev_no')->setUnsigned()->setDefault(0)->setComment('当前版本号'))
            ->addColumn(Column::string('source_fingerprint', 40)->setDefault('')->setComment('依赖源内容 hash 集合的 sha1（增量判定）'))
            ->addColumn(Column::integer('shadow_doc_id')->setUnsigned()->setDefault(0)->setComment('影子库中的文档ID（0=未发布）'))
            ->addColumn(Column::tinyInteger('staleness')->setUnsigned()->setDefault(0)->setComment('腐烂度 0~100'))
            ->addColumn(Column::tinyInteger('quality_score')->setUnsigned()->setDefault(0)->setComment('质量分 0~100'))
            ->addColumn(Column::string('dirty_reason', 32)->setDefault('')->setComment('脏标记原因：doc_changed/prompt_changed/manual/quality'))
            ->addColumn(Column::string('editor_type', 16)->setDefault('system')->setComment('最后编辑者类型：system/admin/agent'))
            ->addColumn(Column::integer('editor_id')->setUnsigned()->setDefault(0)->setComment('最后编辑者ID'))
            ->addColumn(Column::string('locked_by', 64)->setDefault('')->setComment('编译锁持有者'))
            ->addColumn(Column::dateTime('locked_until')->setNullable()->setComment('编译锁到期时间'))
            ->addColumn(Column::dateTime('published_at')->setNullable()->setComment('发布时间'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['space_id', 'slug'], ['unique' => true, 'name' => 'uk_space_slug'])
            ->addIndex(['space_id', 'status'], ['name' => 'idx_status'])
            ->addIndex(['source_fingerprint'], ['name' => 'idx_fingerprint'])
            ->create();

        $tableName = (string) Db::name('ai_wiki_pages')->getTable();
        $this->execute(
            "ALTER TABLE `{$tableName}` MODIFY `content_md` LONGTEXT NULL COMMENT '页面正文 Markdown'"
        );
    }

    public function down(): void
    {
        if ($this->hasTable('ai_wiki_pages')) {
            $this->table('ai_wiki_pages')->drop()->save();
        }
    }
}
