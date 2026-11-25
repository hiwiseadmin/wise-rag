<?php
declare(strict_types=1);

namespace wise\wiki\compile;

use wise\wiki\bridge\AgentBridge;
use wise\wiki\dto\CompileOutput;
use wise\wiki\dto\Evidence;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;
use wise\wiki\support\JsonRepair;
use wise\wiki\support\Slugger;

/**
 * LLM 编译：发请求 → 容错解析 JSON → 映射为 CompileOutput
 *
 * 纪律：
 *  - 解析失败只重试 `llm.retry` 次，且把上一次输出作为 assistant 消息回灌（比单纯重发更有效）；
 *  - 结构字段做「安全兜底」，但**引用编号不做兜底** —— 幻觉引用必须由 OutputValidator 拦下，
 *    不能在这里悄悄过滤掉，否则「正文标了 [9] 但证据只有 8 条」会被无声吞掉。
 */
class WikiCompiler
{
    public function __construct(
        protected AgentBridge $agent,
        protected PromptBuilder $prompts
    ) {
    }

    /**
     * @param Evidence[]                      $evidence
     * @param array<int, array<string,mixed>> $existing
     * @throws WikiException LLM 不可用 / 输出无法解析
     */
    public function compile(
        WikiSpace $space,
        string $topic,
        string $kind,
        array $evidence,
        array $existing = []
    ): CompileOutput {
        $messages = $this->prompts->build($space, $topic, $kind, $evidence, $existing);

        $provider = (string) ($space->model_provider ?: (string) config('wise-rag.wiki.llm.provider', ''));
        $model    = (string) ($space->model_name ?: (string) config('wise-rag.wiki.llm.model', ''));
        $retry    = max(0, (int) config('wise-rag.wiki.llm.retry', 1));

        $options = [
            'json'        => true,
            'temperature' => (float) config('wise-rag.wiki.llm.temperature', 0.2),
            'max_tokens'  => (int) config('wise-rag.wiki.llm.max_tokens', 4096),
        ];
        if ($model !== '') {
            $options['model'] = $model;
        }

        $lastError = '';

        for ($attempt = 0; $attempt <= $retry; $attempt++) {
            $result  = $this->agent->chat($messages, $options, $provider !== '' ? $provider : null);
            $content = (string) ($result['content'] ?? '');
            $usage   = (array) ($result['usage'] ?? []);

            $parsed = JsonRepair::parse($content);

            if ($parsed === null) {
                $lastError = '输出不是合法 JSON';
                $messages[] = ['role' => 'assistant', 'content' => mb_substr($content, 0, 800)];
                $messages[] = [
                    'role'    => 'user',
                    'content' => '上一条输出无法解析为 JSON（' . $lastError . '）。请只返回 JSON 对象，'
                        . '不要解释文字、不要 ``` 围栏、不要注释。',
                ];
                continue;
            }

            return $this->map($parsed, $space, $kind, $topic, $usage, $provider, $model);
        }

        throw WikiException::invalidArgument(
            'LLM 输出无法解析为 JSON（已重试 ' . $retry . ' 次）：' . $lastError
        );
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $usage
     */
    protected function map(
        array $parsed,
        WikiSpace $space,
        string $kind,
        string $topic,
        array $usage,
        string $provider,
        string $model
    ): CompileOutput {
        $title = trim((string) ($parsed['title'] ?? ''));
        if ($title === '') {
            $title = $topic;
        }

        $rawSlug = trim((string) ($parsed['slug'] ?? ''));
        $slug    = Slugger::isValid($rawSlug) ? $rawSlug : Slugger::make($title !== '' ? $title : $topic);

        $resolvedKind = (string) ($parsed['kind'] ?? $kind);
        if (!in_array($resolvedKind, WikiPage::KINDS, true)) {
            $resolvedKind = in_array($kind, WikiPage::KINDS, true) ? $kind : 'topic';
        }

        $markdown = (string) ($parsed['markdown'] ?? '');
        $maxChars = max(500, (int) config('wise-rag.wiki.compile.max_page_chars', 20000));
        if (mb_strlen($markdown) > $maxChars) {
            $markdown = mb_substr($markdown, 0, $maxChars);
        }

        $citations = [];
        foreach ((array) ($parsed['citations'] ?? []) as $no) {
            if (is_numeric($no)) {
                $citations[] = (int) $no;
            }
        }

        $links = [];
        foreach ((array) ($parsed['links'] ?? []) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $slugTo = trim((string) ($link['slug'] ?? ''));
            if ($slugTo === '') {
                continue;
            }
            $links[] = [
                'slug' => $slugTo,
                'rel'  => (string) ($link['rel'] ?? 'related'),
            ];
        }

        return new CompileOutput(
            $title,
            $slug,
            $resolvedKind,
            mb_substr(trim((string) ($parsed['summary'] ?? '')), 0, 500),
            trim($markdown),
            array_values(array_unique($citations)),
            $links,
            (float) ($parsed['confidence'] ?? 0.0),
            (int) ($usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? 0)),
            (int) ($usage['completion_tokens'] ?? ($usage['output_tokens'] ?? 0)),
            $model !== '' ? $model : $this->guessModel($provider),
            (string) config('wise-rag.wiki.compile.prompt_version', 'v1')
        );
    }

    /**
     * 尽力取实际模型名（失败不影响编译：只用于审计记录）
     */
    protected function guessModel(string $provider): string
    {
        try {
            return $this->agent->client($provider !== '' ? $provider : null)->getModelName();
        } catch (\Exception $e) {
            return '';
        }
    }
}
