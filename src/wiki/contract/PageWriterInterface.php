<?php
declare(strict_types=1);

namespace wise\wiki\contract;

use wise\wiki\dto\CompileOutput;
use wise\wiki\dto\Evidence;
use wise\wiki\model\WikiSpace;

/**
 * 页面落库契约
 *
 * 实现方负责：页面 upsert（含 rev_no）、版本快照、依赖（dependencies）、链接（links）。
 * **必须在单个事务内完成**；发布到影子库不在本契约内（见 ShadowPublisher）。
 */
interface PageWriterInterface
{
    /**
     * @param Evidence[]                   $evidence
     * @param array<string, mixed>         $meta status / change_reason / prompt_version / model / tokens_in / tokens_out
     * @return array{page_id:int, rev_no:int, status:string, deferred:bool}
     */
    public function write(WikiSpace $space, CompileOutput $output, array $evidence, array $meta = []): array;
}
