<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusAuditLogPlugin\Repository\AuditLogEntryRepositoryInterface;

#[AsCommand(name: 'tmonier:audit-log:purge', description: 'Delete audit log entries older than the retention period')]
final class PurgeAuditLogCommand extends Command
{
    public function __construct(
        private readonly AuditLogEntryRepositoryInterface $repository,
        private readonly ClockInterface $clock,
        private readonly ?int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Age limit: a number of days ("365d", "90"), or a relative date ("6 months", "1 year"). Defaults to the retention_days setting.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the entries that would be deleted')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Entries deleted per query', '1000')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $olderThan */
        $olderThan = $input->getOption('older-than');
        if (null === $olderThan && null === $this->retentionDays) {
            $io->note('No retention period is configured (retention_days: null) and no --older-than given: nothing to purge.');

            return Command::SUCCESS;
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));

        try {
            $limit = self::limit($now, $olderThan ?? sprintf('%dd', (int) $this->retentionDays));
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $batchSize = (int) $input->getOption('batch-size');
        if ($batchSize < 1) {
            $io->error('The batch size must be a positive number.');

            return Command::INVALID;
        }

        $date = $limit->format('Y-m-d H:i:s');
        if ((bool) $input->getOption('dry-run')) {
            $io->success(sprintf('%d entr(y|ies) created before %s UTC would be deleted (dry run).', $this->repository->countOlderThan($limit), $date));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($limit, $batchSize);
        $io->success(sprintf('Deleted %d entr(y|ies) created before %s UTC.', $deleted, $date));

        return Command::SUCCESS;
    }

    /** @throws \InvalidArgumentException */
    public static function limit(\DateTimeImmutable $now, string $olderThan): \DateTimeImmutable
    {
        $olderThan = trim($olderThan);
        if (1 === preg_match('/^(\d+)\s*d?$/i', $olderThan, $matches)) {
            $days = (int) $matches[1];
            if ($days < 1) {
                throw new \InvalidArgumentException('The age limit must be at least one day.');
            }

            return $now->modify(sprintf('-%d days', $days));
        }

        if (1 === preg_match('/^\d+\s*(day|week|month|year)s?$/i', $olderThan)) {
            return $now->modify('-' . $olderThan);
        }

        throw new \InvalidArgumentException(sprintf('Invalid age limit "%s": use a number of days ("365d") or a relative period ("6 months", "1 year").', $olderThan));
    }
}
