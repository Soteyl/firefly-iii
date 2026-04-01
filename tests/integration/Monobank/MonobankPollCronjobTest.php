<?php

declare(strict_types=1);

namespace Tests\integration\Monobank;

use Carbon\Carbon;
use FireflyIII\Models\BankConnection;
use FireflyIII\Services\Monobank\BankSyncService;
use FireflyIII\Support\Cronjobs\MonobankPollCronjob;
use FireflyIII\Support\Facades\FireflyConfig;
use Mockery;
use Tests\integration\TestCase;

final class MonobankPollCronjobTest extends TestCase
{
    public function testPollsActiveConnectionsAndHonorsCooldown(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherUser = $this->createAuthenticatedUser();

        $active = BankConnection::create([
            'user_id'             => $user->id,
            'user_group_id'       => $user->user_group_id,
            'provider'            => 'monobank',
            'status'              => 'active',
            'access_token'        => 'token-1234567890',
            'webhook_path_secret' => 'secret-3',
        ]);

        BankConnection::create([
            'user_id'             => $otherUser->id,
            'user_group_id'       => $otherUser->user_group_id,
            'provider'            => 'monobank',
            'status'              => 'disabled',
            'access_token'        => 'token-abcdefghij',
            'webhook_path_secret' => 'secret-4',
        ]);

        $mock = Mockery::mock(BankSyncService::class);
        $mock->shouldReceive('syncConnection')->once()->withArgs(
            static fn (BankConnection $connection, string $trigger): bool => $connection->id === $active->id && 'poll' === $trigger
        );
        app()->instance(BankSyncService::class, $mock);

        $firstRunAt = Carbon::create(2026, 4, 1, 12, 0, 0, config('app.timezone'));
        $cron = new MonobankPollCronjob();
        $cron->setDate($firstRunAt);
        $cron->fire();

        $this->assertTrue($cron->jobFired);
        $this->assertTrue($cron->jobSucceeded);

        $cooldownCron = new MonobankPollCronjob();
        $cooldownCron->setDate((clone $firstRunAt)->addMinute());
        $cooldownCron->fire();

        $this->assertFalse($cooldownCron->jobFired);
        $this->assertStringContainsString('It has been', (string) $cooldownCron->message);
        $this->assertNotSame('0', (string) FireflyConfig::get('last_monobank_poll_job', 0)->data);
    }
}
