<?php
declare(strict_types=1);

namespace wise\wiki\compile;

use wise\wiki\dto\CompileOutput;
use wise\wiki\dto\Evidence;
use wise\wiki\dto\ValidationResult;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;
use wise\wiki\support\Slugger;

/**
 * 编译产物校验（防幻觉的最后一关）
 *
 * 分两级：
 *  - `errors`：结构性错误（无正文、引用编号不存在于证据、超长）→ 直接失败，重试或落 error；
 *  - `warnings`：质量问题（覆盖率低、置信度低、slug 被修正）→ 不失败，但把状态降为 draft。
 *
 * 「宁可不可见，不可误导」是这里唯一的取舍原则。
 */
class OutputValidator
{
    /**
     * @param Evidence[] $evidence
     */
    public function validate(CompileOutput $output, array $evidence, WikiSpace $space): ValidationResult
    {
        $errors   = [];
        $warnings = [];

        $maxChars = max(500, (int) config('wise-rag.wiki.compile.max_page_chars', 20000));

        if (trim($output->markdown) === '') {
            $errors[] = '正文为空';
        }
        if (mb_strlen($output->markdown) > $maxChars) {
            $errors[] = sprintf('正文超长（%d > %d）', mb_strlen($output->markdown), $maxChars);
        }
        if (trim($output->title) === '') {
            $errors[] = '标题为空';
        }
        if (!Slugger::isValid($output->slug)) {
            $errors[] = 'slug 非法：' . $output->slug;
        }
        if ($output->summary === '') {
            $warnings[] = '摘要为空';
        }

        $allowed = [];
        foreach ($evidence as $item) {
            $allowed[$item->no] = true;
        }

        $badCitations = [];
        foreach ($output->citations as $no) {
            if (!isset($allowed[$no])) {
                $badCitations[] = $no;
            }
        }
        if ($badCitations !== []) {
            $errors[] = '引用了不存在的证据编号：' . implode(',', $badCitations);
        }

        preg_match_all('/\[(\d{1,3})\]/', $output->markdown, $m);
        $inline = array_values(array_unique(array_map('intval', $m[1] ?? [])));

        $unknownInline = array_values(array_filter($inline, static fn (int $n): bool => !isset($allowed[$n])));
        if ($unknownInline !== []) {
            $errors[] = '正文引用了不存在的证据编号：' . implode(',', $unknownInline);
        }

        $missingDeclared = array_values(array_diff($inline, $output->citations));
        if ($missingDeclared !== []) {
            $warnings[] = '正文标注了但 citations 未声明：' . implode(',', $missingDeclared);
        }

        $unusedDeclared = array_values(array_diff($output->citations, $inline));
        if ($unusedDeclared !== []) {
            $warnings[] = 'citations 声明了但正文未使用：' . implode(',', $unusedDeclared);
        }

        $coverage = $this->citationCoverage($output->markdown);
        $minCover = (float) config('wise-rag.wiki.quality.min_citation_coverage', 0.6);
        if ($coverage < $minCover) {
            $warnings[] = sprintf('引用覆盖率偏低（%.2f < %.2f）', $coverage, $minCover);
        }

        $minConfidence = (float) config('wise-rag.wiki.quality.min_confidence', 0.5);
        if ($output->confidence < $minConfidence) {
            $warnings[] = sprintf('置信度偏低（%.2f < %.2f）', $output->confidence, $minConfidence);
        }

        $ok = $errors === [];

        if (!$ok) {
            $status = ValidationResult::STATUS_DRAFT;
        } elseif ($warnings !== []) {
            $status = ValidationResult::STATUS_DRAFT;
        } elseif ((bool) config('wise-rag.wiki.compile.auto_publish', false)) {
            $status = ValidationResult::STATUS_PUBLISHED;
        } else {
            $status = ValidationResult::STATUS_REVIEW;
        }

        return new ValidationResult($ok, $errors, $warnings, $coverage, $status);
    }

    /**
     * 段落级引用覆盖率：正文段落里带 [n] 标注的比例
     *
     * 统计口径（刻意排除，避免虚高）：
     *  - 标题行（`#`）、代码块（``` 围栏内）、表格分隔行、空段落；
     *  - 「相关页面」这类纯链接段落仍计入（它本就无需引用，只能靠正文其它段落拉平均值）。
     */
    public function citationCoverage(string $markdown): float
    {
        $lines    = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $inCode   = false;
        $paragraphs = [];
        $buffer   = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                $inCode = !$inCode;
                continue;
            }
            if ($inCode) {
                continue;
            }
            if ($trimmed === '') {
                if ($buffer !== '') {
                    $paragraphs[] = $buffer;
                    $buffer       = '';
                }
                continue;
            }
            if (str_starts_with($trimmed, '#')) {
                if ($buffer !== '') {
                    $paragraphs[] = $buffer;
                    $buffer       = '';
                }
                continue;
            }
            if (preg_match('/^\|?[\s:-]+\|[\s:|-]*$/', $trimmed) === 1) {
                continue;
            }

            $buffer .= ($buffer === '' ? '' : ' ') . $trimmed;
        }

        if ($buffer !== '') {
            $paragraphs[] = $buffer;
        }

        $paragraphs = array_values(array_filter($paragraphs, static fn (string $p): bool => mb_strlen($p) >= 10));
        if ($paragraphs === []) {
            return 0.0;
        }

        $cited = 0;
        foreach ($paragraphs as $p) {
            if (preg_match('/\[\d{1,3}\]/', $p) === 1) {
                $cited++;
            }
        }

        return round($cited / count($paragraphs), 4);
    }

    /**
     * 重复建页检测：与现有 slug 的相似度
     *
     * @param array<int, array<string,mixed>> $existing
     */
    public function duplicatesOf(string $slug, array $existing): array
    {
        $hits = [];
        foreach ($existing as $row) {
            $other = (string) ($row['slug'] ?? '');
            if ($other === '' || $other === $slug) {
                continue;
            }
            if (Slugger::similar($slug, $other) >= 0.85) {
                $hits[] = $other;
            }
        }

        return $hits;
    }

    /**
     * kind 白名单兜底（LLM 可能自造类型）
     */
    public function normalizeKind(string $kind): string
    {
        return in_array($kind, WikiPage::KINDS, true) ? $kind : 'topic';
    }
}
