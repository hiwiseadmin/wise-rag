<?php
declare(strict_types=1);

namespace wise\wiki\model;

use think\Model;

/**
 * Wiki 页面（表 ai_wiki_pages）—— **唯一真源**
 *
 * 影子知识库里的文档只是派生物；删库重建时以本表为准重灌。
 *
 * @property int         $id
 * @property int         $space_id
 * @property string      $slug
 * @property string      $title
 * @property string      $kind
 * @property string      $status
 * @property string|null $content_md
 * @property string      $summary
 * @property string|null $outline
 * @property int         $rev_no
 * @property string      $source_fingerprint
 * @property int         $shadow_doc_id
 * @property int         $staleness
 * @property int         $quality_score
 * @property string      $dirty_reason
 * @property string      $editor_type
 * @property int         $editor_id
 * @property string      $locked_by
 * @property string|null $locked_until
 * @property string|null $published_at
 */
class WikiPage extends Model
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_REVIEW    = 'review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    public const KINDS = ['topic', 'entity', 'decision', 'faq', 'glossary'];

    public const DIRTY_DOC_CHANGED    = 'doc_changed';
    public const DIRTY_PROMPT_CHANGED = 'prompt_changed';
    public const DIRTY_MANUAL         = 'manual';
    public const DIRTY_QUALITY        = 'quality';

    protected $name = 'ai_wiki_pages';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'space_id'      => 'integer',
        'rev_no'        => 'integer',
        'shadow_doc_id' => 'integer',
        'staleness'     => 'integer',
        'quality_score' => 'integer',
        'editor_id'     => 'integer',
    ];

    public function isDirty(): bool
    {
        return (string) $this->dirty_reason !== '';
    }

    /**
     * 是否有人工编辑痕迹（决定重编译能否直接覆盖）
     */
    public function isHumanEdited(): bool
    {
        return (string) $this->editor_type === 'admin';
    }

    public function markDirty(string $reason): void
    {
        $this->save(['dirty_reason' => $reason]);
    }

    public function markClean(): void
    {
        $this->save(['dirty_reason' => '']);
    }
}
