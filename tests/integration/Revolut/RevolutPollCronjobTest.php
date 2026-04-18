<?php

declare(strict_types=1);

namespace Tests\integration\Revolut;

use FireflyIII\Models\BankConnection;
use FireflyIII\Services\Revolut\RevolutSyncService;
use FireflyIII\Support\Cronjobs\RevolutPollCronjob;
use FireflyIII\Support\Facades\FireflyConfig;
use Mockery;
use Tests\integration\TestCase;

final class RevolutPollCronjobTest extends TestCase
{
    public function testPollsActiveConnectionsAndHonorsCooldown(): void
    {
        BankConnection::query()->update(['status' => 'disabled']);

        $user = $this->createAuthenticatedUser();
        $otherUser = $this->createAuthenticatedUser();

        $active = BankConnection::create([
            'user_id'             => $user->id,
            'user_group_id'       => $user->user_group_id,
            'provider'            => 'revolut',
            'status'              => 'active',
            'access_token'        => 'token-1234567890',
            'provider_config'     => ['device_id' => 'device-123'],
            'webhook_path_secret' => 'secret-7',
        ]);

        BankConnection::create([
            'user_id'             => $otherUser->id,
            'user_group_id'       => $otherUser->user_group_id,
            'provider'            => 'revolut',
            'status'              => 'disabled',
            'access_token'        => 'token-abcdefghij',
            'provider_config'     => ['device_id' => 'device-999'],
            'webhook_path_secret' => 'secret-8',
        ]);

        $mock = Mockery::mock(RevolutSyncService::class);
        $mock->shouldReceive('syncConnection')->once()->withArgs(
            static fn (BankConnection $connection, string $trigger): bool => $connection->id === $active->id && 'poll' === $trigger
        );
        app()->instance(RevolutSyncService::class, $mock);

        FireflyConfig::set('last_revolut_poll_job', 0);

        $firstRunAt = now(config('app.timezone'));
        $cron = new RevolutPollCronjob();
        $cron->setDate($firstRunAt);
        $cron->fire();

        $this->assertTrue($cron->jobFired);
        $this->assertTrue($cron->jobSucceeded);

        $cooldownCron = new RevolutPollCronjob();
        $cooldownCron->setDate((clone $firstRunAt)->addMinute());
        $cooldownCron->fire();

        $this->assertFalse($cooldownCron->jobFired);
        $this->assertStringContainsString('It has been', (string) $cooldownCron->message);
        $this->assertNotSame('0', (string) FireflyConfig::get('last_revolut_poll_job', 0)->data);
    }
}
