<?php
declare(strict_types=1);

namespace wise\wiki\contract;

use wise\wiki\dto\Evidence;
use wise\wiki\model\WikiSpace;

/**
 * Prompt 组装契约
 *
 * 按页面类型（kind）分流模板，模板改动必须同步升 `compile.prompt_version`，
 * 否则历史版本无法回溯「这版是用哪套 prompt 生成的」。
 */
interface PromptProfileInterface
{
    /**
     * @param Evidence[]                    $evidence
     * @param array<int, array<string,mixed>> $existing 现有页面目录（防重复建页）
     * @return array<int, array{role:string, content:string}>
     */
    public function build(WikiSpace $space, string $topic, string $kind, array $evidence, array $existing = []): array;
}
