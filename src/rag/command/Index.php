<?php
declare(strict_types=1);

namespace wise\rag\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use wise\rag\service\IndexTaskService;
use wise\rag\service\KnowledgeBaseService;

/**
 * 重建知识库 / 知识源索引
 *
 *   php think wise:rag:index product_docs
 *   php think wise:rag:index 3 --force
 *   php think wise:rag:index --source=smartstock:product_docs
 *   php think wise:rag:index product_docs --async
 */
class Index extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:rag:index')
            ->setDescription('重建知识库 / 知识源索引')
            ->addArgument('kb', Argument::OPTIONAL, 'kb_key 或知识库 ID')
            ->addOption('source', 's', Option::VALUE_REQUIRED, '按 source_key 索引')
            ->addOption('async', null, Option::VALUE_NONE, '入队异步执行（php think queue:work）')
            ->addOption('force', 'f', Option::VALUE_NONE, '忽略增量水位，全量重建');
    }

    protected function execute(Input $input, Output $output): int
    {
        $kbArg     = trim((string) $input->getArgument('kb'));
        $sourceKey = trim((string) $input->getOption('source'));

        $kbService = app(KnowledgeBaseService::class);
        $tasks     = app(IndexTaskService::class);

        $kbId     = null;
        $sourceId = null;

        if ($sourceKey !== '') {
            $row = Db::name('ai_kb_sources')->where('source_key', $sourceKey)->find();
            if (!is_array($row)) {
                $output->writeln("<error>知识源不存在：{$sourceKey}</error>");

                return 1;
            }
            $sourceId = (int) $row['id'];
            $kbId     = (int) $row['kb_id'];
        } elseif ($kbArg !== '') {
            $kb = ctype_digit($kbArg) ? $kbService->get((int) $kbArg) : $kbService->findByKey($kbArg);
            if ($kb === null) {
                $output->writeln("<error>知识库不存在：{$kbArg}</error>");

                return 1;
            }
            $kbId = (int) $kb->id;
        } else {
            $output->writeln('<error>请指定 kb（kb_key / ID）或 --source=source_key</error>');

            return 1;
        }

        $async = (bool) $input->getOption('async');
        $force = (bool) $input->getOption('force');

        $res = $tasks->run($kbId, $sourceId, $async, $force);

        if (!empty($res['async'])) {
            $output->writeln('<info>已入队，等待 worker 处理：php think queue:work</info>');

            return 0;
        }

        $result = (array) ($res['result'] ?? []);

        if (isset($result['details'])) {
            foreach ((array) $result['details'] as $detail) {
                $output->writeln($this->line((array) $detail));
            }
        } elseif (isset($result['source_key'])) {
            $output->writeln($this->line($result));
        }

        $output->writeln('<info>索引完成。</info>');

        return 0;
    }

    /**
     * @param array<string, mixed> $detail
     */
    protected function line(array $detail): string
    {
        return sprintf(
            '  %-28s 文档 %d / 分块 %d',
            (string) ($detail['source_key'] ?? ''),
            (int) ($detail['documents'] ?? 0),
            (int) ($detail['chunks'] ?? 0)
        );
    }
}
