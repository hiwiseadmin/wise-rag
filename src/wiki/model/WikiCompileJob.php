<?php
declare(strict_types=1);

namespace wise\wiki\model;

use think\Model;

/**
 * 编译任务（表 ai_wiki_compile_jobs）
 *
 * 任务 + 审计二合一：失败原因、token 用量、耗时全部落库，
 * 使「页面为什么没更新」永远可回答。
 *
 * @property int    $id
 * @property int    $space_id
 * @property int    $page_id
 * @property string $trigger_type
 * @property string $status
 * @property string $prompt_version
 * @property string $model_name
 * @property int    $evidence_count
 * @property int    $tokens_in
 * @property int    $tokens_out
 * @property float  $cost
 * @property int    $latency_ms
 * @property int    $attempt
 * @property string $error
 * @property string|null $payload
 */
class WikiCompileJob extends Model
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $name = 'ai_wiki_compile_jobs';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'space_id'       => 'integer',
        'page_id'        => 'integer',
        'evidence_count' => 'integer',
        'tokens_in'      => 'integer',
        'tokens_out'     => 'integer',
        'latency_ms'     => 'integer',
        'attempt'        => 'integer',
    ];
}
