<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Context;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusAuditLogPlugin\Context\AuditContextProviderInterface;
use Tmonier\SyliusAuditLogPlugin\Context\RecordingPolicy;

final class RecordingPolicyTest extends TestCase
{
    public function testOnlyTheConfiguredSourcesAreRecorded(): void
    {
        self::assertTrue($this->policy('admin')->shouldRecord());
        self::assertTrue($this->policy('cli', 'sylius:cancel-unpaid-orders')->shouldRecord());
        self::assertFalse($this->policy('shop')->shouldRecord());
    }

    public function testIgnoredCommandsAndTheKillSwitchStopRecording(): void
    {
        self::assertFalse($this->policy('cli', 'sylius:fixtures:load')->shouldRecord());
        self::assertFalse($this->policy('admin', enabled: false)->shouldRecord());
    }

    private function policy(string $source, ?string $command = null, bool $enabled = true): RecordingPolicy
    {
        $provider = $this->createMock(AuditContextProviderInterface::class);
        $provider->method('getSource')->willReturn($source);
        $provider->method('getCommandName')->willReturn($command);

        return new RecordingPolicy($provider, $enabled, ['admin', 'admin_api', 'cli'], ['sylius:fixtures:load']);
    }
}
