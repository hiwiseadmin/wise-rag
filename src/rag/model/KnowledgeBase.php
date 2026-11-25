<?php
declare(strict_types=1);

namespace wise\rag\model;

use think\Model;

/**
 * 知识库模型（表 ai_kb_knowledge_bases）
 *
 * @property int         $id
 * @property string      $kb_key
 * @property string      $name
 * @property string|null $description
 * @property string      $owner_type
 * @property int         $owner_id
 * @property string      $visibility
 * @property string      $embedding
 * @property string      $embed_model
 * @property int         $dimension
 * @property string      $vector_store
 * @property string      $collection
 * @property string|null $chunk_config
 * @property string|null $retrieval_config
 * @property int         $doc_count
 * @property int         $chunk_count
 * @property int         $vector_count
 * @property string      $status
 */
class KnowledgeBase extends Model
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INDEXING = 'indexing';
    public const STATUS_ERROR    = 'error';
    public const STATUS_DISABLED = 'disabled';

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_TENANT  = 'tenant';
    public const VISIBILITY_PUBLIC  = 'public';

    protected $name = 'ai_kb_knowledge_bases';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'owner_id'     => 'integer',
        'dimension'    => 'integer',
        'doc_count'    => 'integer',
        'chunk_count'  => 'integer',
        'vector_count' => 'integer',
    ];

    /**
     * 分块配置（覆盖全局默认）
     *
     * @return array<string, mixed>
     */
    public function getChunkConfig(): array
    {
        return $this->decodeJson('chunk_config');
    }

    /**
     * 检索配置（覆盖全局默认）
     *
     * @return array<string, mixed>
     */
    public function getRetrievalConfig(): array
    {
        return $this->decodeJson('retrieval_config');
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(string $field): array
    {
        $raw = (string) $this->getAttr($field);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
