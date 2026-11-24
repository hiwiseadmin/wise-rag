<?php
declare(strict_types=1);

namespace wise\rag\model;

use think\Model;

/**
 * 知识文档模型（表 ai_kb_documents）
 *
 * @property int         $id
 * @property int         $kb_id
 * @property int         $source_id
 * @property string|null $external_id
 * @property string      $title
 * @property string|null $mime
 * @property string|null $file_path
 * @property string|null $url
 * @property string      $content_hash
 * @property int         $char_count
 * @property int         $chunk_count
 * @property string      $owner_type
 * @property int         $owner_id
 * @property string      $status
 * @property string|null $error
 * @property string|null $meta
 */
class KbDocument extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARSED  = 'parsed';
    public const STATUS_INDEXED = 'indexed';
    public const STATUS_ERROR   = 'error';
    public const STATUS_SKIPPED = 'skipped';

    protected $name = 'ai_kb_documents';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'kb_id'       => 'integer',
        'source_id'   => 'integer',
        'char_count'  => 'integer',
        'chunk_count' => 'integer',
        'owner_id'    => 'integer',
    ];
}
