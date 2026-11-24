<?php
declare(strict_types=1);

namespace wise\rag\model;

use think\Model;

/**
 * 知识源模型（表 ai_kb_sources）
 *
 * @property int         $id
 * @property int         $kb_id
 * @property string      $source_key
 * @property string      $origin
 * @property string      $provider
 * @property string|null $config
 * @property string      $sync_mode
 * @property string|null $cron
 * @property string|null $checkpoint
 * @property int|null    $last_sync
 * @property string      $status
 */
class KbSource extends Model
{
    public const ORIGIN_MANUAL     = 'manual';
    public const ORIGIN_PLUGIN     = 'plugin';
    public const ORIGIN_TABLE      = 'table';
    public const ORIGIN_ATTACHMENT = 'attachment';

    public const SYNC_MANUAL    = 'manual';
    public const SYNC_ON_CHANGE = 'on_change';
    public const SYNC_CRON      = 'cron';

    protected $name = 'ai_kb_sources';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'kb_id'     => 'integer',
        'last_sync' => 'integer',
    ];

    /**
     * 源配置
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $raw = (string) $this->getAttr('config');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
