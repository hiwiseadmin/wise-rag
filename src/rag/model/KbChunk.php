<?php
declare(strict_types=1);

namespace wise\rag\model;

use think\Model;

/**
 * 知识分块模型（表 ai_kb_chunks）
 *
 * 注意：批量写入与向量更新走 `Db`（insertAll / 批量 update），本模型主要用于查询与展示。
 *
 * @property int         $id
 * @property int         $kb_id
 * @property int         $doc_id
 * @property int         $chunk_index
 * @property string      $content
 * @property int         $token_count
 * @property string|null $heading
 * @property int|null    $page
 * @property string      $content_hash
 * @property int|null    $dimension
 * @property string      $owner_type
 * @property int         $owner_id
 */
class KbChunk extends Model
{
    protected $name = 'ai_kb_chunks';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'kb_id'       => 'integer',
        'doc_id'      => 'integer',
        'chunk_index' => 'integer',
        'token_count' => 'integer',
        'page'        => 'integer',
        'dimension'   => 'integer',
        'owner_id'    => 'integer',
    ];
}
