<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Context;

/** Whether the changes of the current request or command must be recorded. */
final class RecordingPolicy
{
    /**
     * @param list<string> $sources
     * @param list<string> $ignoredCommands
     */
    public function __construct(
        private readonly AuditContextProviderInterface $contextProvider,
        private readonly bool $enabled,
        private readonly array $sources,
        private readonly array $ignoredCommands,
    ) {
    }

    public function shouldRecord(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!in_array($this->contextProvider->getSource(), $this->sources, true)) {
            return false;
        }

        $command = $this->contextProvider->getCommandName();

        return null === $command || !in_array($command, $this->ignoredCommands, true);
    }
}
