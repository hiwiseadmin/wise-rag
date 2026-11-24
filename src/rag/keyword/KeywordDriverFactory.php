<?php
declare(strict_types=1);

namespace wise\rag\keyword;

use wise\rag\contract\KeywordDriverInterface;
use wise\rag\exception\ProviderException;

/**
 * 关键词检索驱动工厂
 *
 * 依 `wise-rag.keyword.driver`（mysql_fulltext | like）选择实现；
 * 可经 `keyword.drivers.*` 替换 / 追加（插件用 mergeConfig 亦可）。
 */
class KeywordDriverFactory
{
    /** @var array<string, string> 内置驱动 */
    protected const BUILTIN = [
        'mysql_fulltext' => FulltextDriver::class,
        'like'           => LikeDriver::class,
    ];

    protected array $config;

    /** @var array<string, KeywordDriverInterface> */
    protected array $instances = [];

    /**
     * @param array<string, mixed> $config wise-rag.keyword 配置节
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * @throws ProviderException
     */
    public function make(?string $name = null): KeywordDriverInterface
    {
        $name = $name !== null && $name !== '' ? $name : (string) ($this->config['driver'] ?? 'mysql_fulltext');

        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $configured = (array) ($this->config['drivers'][$name] ?? []);
        $class      = (string) ($configured['class'] ?? self::BUILTIN[$name] ?? '');

        if ($class === '' || !class_exists($class)) {
            throw new ProviderException("未知的关键词检索驱动：{$name}");
        }

        $instance = new $class($this->config + $configured);
        if (!$instance instanceof KeywordDriverInterface) {
            throw new ProviderException("关键词驱动必须实现 KeywordDriverInterface：{$class}");
        }

        return $this->instances[$name] = $instance;
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
