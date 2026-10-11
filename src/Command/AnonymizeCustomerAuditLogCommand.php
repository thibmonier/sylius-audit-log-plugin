<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Command;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusAuditLogPlugin\Maintenance\CustomerAuditLogAnonymizer;

#[AsCommand(name: 'tmonier:audit-log:anonymize-customer', description: 'Remove the personal data of a customer from the audit log (right to erasure)')]
final class AnonymizeCustomerAuditLogCommand extends Command
{
    /** @param CustomerRepositoryInterface<CustomerInterface> $customerRepository */
    public function __construct(
        private readonly CustomerAuditLogAnonymizer $anonymizer,
        private readonly CustomerRepositoryInterface $customerRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('customer', InputArgument::REQUIRED, 'Customer id or e-mail address')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail of a deleted customer given by id, to also anonymize the entries labelled with it')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the entries that would be anonymized')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $identifier */
        $identifier = $input->getArgument('customer');
        /** @var string|null $email */
        $email = $input->getOption('email');

        $customer = ctype_digit($identifier)
            ? $this->customerRepository->find((int) $identifier)
            : $this->customerRepository->findOneBy(['emailCanonical' => mb_strtolower($identifier)]);

        if ($customer instanceof CustomerInterface && is_int($customer->getId())) {
            $customerId = $customer->getId();
            $email ??= $customer->getEmail();
        } elseif (ctype_digit($identifier)) {
            // Deleted customer: its entries are still identified by its id.
            $customerId = (int) $identifier;
        } else {
            $io->error(sprintf('No customer with the e-mail "%s". A deleted customer can be anonymized by id (with --email).', $identifier));

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $io->success(sprintf('%d entr(y|ies) of customer #%d would be anonymized (dry run).', $this->anonymizer->count($customerId, $email), $customerId));

            return Command::SUCCESS;
        }

        $count = $this->anonymizer->anonymize($customerId, $email);
        $io->success(sprintf('Anonymized %d entr(y|ies) of customer #%d.', $count, $customerId));

        return Command::SUCCESS;
    }
}
