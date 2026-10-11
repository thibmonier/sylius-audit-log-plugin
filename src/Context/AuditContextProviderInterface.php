<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Context;

interface AuditContextProviderInterface
{
    /** One of AuditLogEntryInterface::SOURCE_*. */
    public function getSource(): string;

    /** Name of the running console command, null outside the console. */
    public function getCommandName(): ?string;

    public function getContext(): AuditContext;
}
