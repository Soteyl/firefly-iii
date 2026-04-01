<?php

declare(strict_types=1);

namespace Tests\integration\Monobank;

use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankSyncEvent;
use FireflyIII\Services\Monobank\BankSyncService;
use Mockery;
use Override;
use Tests\integration\TestCase;

final class MonobankWebhookControllerTest extends TestCase
{
    private BankConnection $connection;

    public function testPostStoresEventAndTriggersSync(): void
    {
        $mock = Mockery::mock(BankSyncService::class);
        $mock->shouldReceive('syncConnection')->once()->withArgs(function (BankConnection $connection, string $trigger): bool {
            return $connection->id === $this->connection->id && 'webhook' === $trigger;
        });
        app()->instance(BankSyncService::class, $mock);

        $response = $this->postJson(route('monobank.webhook', ['secret' => 'secret-2']), [
            'type' => 'StatementItem',
            'data' => ['account' => 'mono-account-1', 'statementItem' => ['id' => 'stmt-1']],
        ]);

        $response->assertStatus(200);
        $response->assertContent('ok');

        $event = BankSyncEvent::first();
        $this->assertInstanceOf(BankSyncEvent::class, $event);
        $this->assertSame('StatementItem', $event->event_type);
        $this->assertNotNull($event->processed_at);
    }

    public function testGetProbeReturns200WithoutSyncing(): void
    {
        $mock = Mockery::mock(BankSyncService::class);
        $mock->shouldNotReceive('syncConnection');
        app()->instance(BankSyncService::class, $mock);

        $response = $this->get(route('monobank.webhook', ['secret' => 'secret-2']));

        $response->assertStatus(200);
        $response->assertContent('ok');
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->createAuthenticatedUser();
        $this->connection = BankConnection::create([
            'user_id'             => $user->id,
            'user_group_id'       => $user->user_group_id,
            'provider'            => 'monobank',
            'status'              => 'active',
            'access_token'        => 'token-1234567890',
            'webhook_path_secret' => 'secret-2',
        ]);
    }
}
