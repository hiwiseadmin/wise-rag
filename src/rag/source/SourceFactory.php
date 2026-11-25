<?php
declare(strict_types=1);

namespace wise\rag\source;

use wise\rag\contract\KnowledgeSourceInterface;
use wise\rag\exception\ProviderException;

/**
 * 知识源工厂
 *
 * 依 `ai_kb_sources.provider` 创建驱动；内置 manual / table / attachment，
 * 可通过 `wise-rag.sources.providers.*`（或 mergeConfig）替换 / 追加。
 */
class SourceFactory
{
    /** @var array<string, string> 内置驱动：名称 => 类 */
    protected const BUILTIN = [
        'manual'     => ManualSource::class,
        'table'      => TableSource::class,
        'attachment' => AttachmentSource::class,
    ];

    /** @var array<string, array<string, mixed>> */
    protected array $providers;

    /**
     * @param array<string, mixed> $config wise-rag.sources 配置节
     */
    public function __construct(array $config = [])
    {
        $this->providers = (array) ($config['providers'] ?? []);
    }

    /**
     * 由 ai_kb_sources 行创建驱动
     *
     * @param array<string, mixed> $row
     * @throws ProviderException
     */
    public function make(array $row): KnowledgeSourceInterface
    {
        $provider = (string) ($row['provider'] ?? 'manual');
        $class    = (string) ($this->providers[$provider]['class'] ?? self::BUILTIN[$provider] ?? '');

        if ($class === '' || !class_exists($class)) {
            throw new ProviderException("未知的知识源驱动：{$provider}");
        }

        $config = json_decode((string) ($row['config'] ?? ''), true);
        $config = is_array($config) ? $config : [];

        $instance = new $class((string) ($row['source_key'] ?? ''), $config, $row['checkpoint'] ?? null);
        if (!$instance instanceof KnowledgeSourceInterface) {
            throw new ProviderException("知识源驱动必须实现 KnowledgeSourceInterface：{$class}");
        }

        return $instance;
    }

    /**
     * 内置驱动清单
     *
     * @return array<string, string>
     */
    public static function builtin(): array
    {
        return self::BUILTIN;
    }
}
