<?php
declare(strict_types=1);

namespace wise\wiki\model;

use think\Model;

/**
 * Wiki 空间（表 ai_wiki_spaces）
 *
 * 一个空间 = 一个源知识库（编译输入）+ 一个影子知识库（页面索引）。
 *
 * @property int         $id
 * @property string      $space_key
 * @property string      $name
 * @property string|null $description
 * @property int         $source_kb_id
 * @property int         $shadow_kb_id
 * @property string      $owner_type
 * @property int         $owner_id
 * @property string      $visibility
 * @property string|null $compile_policy
 * @property string      $model_provider
 * @property string      $model_name
 * @property string      $prompt_version
 * @property int         $budget_tokens_month
 * @property int         $tokens_used_month
 * @property int         $page_count
 * @property int         $dirty_count
 * @property string      $status
 */
class WikiSpace extends Model
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_DISABLED = 'disabled';

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_TENANT  = 'tenant';
    public const VISIBILITY_PUBLIC  = 'public';

    protected $name = 'ai_wiki_spaces';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'source_kb_id'       => 'integer',
        'shadow_kb_id'       => 'integer',
        'owner_id'           => 'integer',
        'budget_tokens_month'=> 'integer',
        'tokens_used_month'  => 'integer',
        'page_count'         => 'integer',
        'dirty_count'        => 'integer',
    ];

    /**
     * 编译策略（覆盖全局默认）
     *
     * @return array<string, mixed>
     */
    public function getCompilePolicy(): array
    {
        $raw = (string) $this->getAttr('compile_policy');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 取编译策略某一项（空间级优先，回落全局配置）
     */
    public function policy(string $key, mixed $default = null): mixed
    {
        $policy = $this->getCompilePolicy();

        return $policy[$key] ?? (function_exists('config') ? config('wise-rag.wiki.compile.' . $key, $default) : $default);
    }

    public function isActive(): bool
    {
        return (string) $this->status === self::STATUS_ACTIVE;
    }
}
