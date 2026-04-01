<?php

declare(strict_types=1);

namespace Tests\integration\Monobank;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Services\Monobank\MonobankClient;
use FireflyIII\Services\Monobank\MonobankImportService;
use FireflyIII\User;
use Mockery;
use Override;
use Tests\integration\TestCase;

final class MonobankImportServiceTest extends TestCase
{
    private Account $assetAccount;
    private BankConnection $connection;
    private BankConnectionAccount $mapping;
    private User $user;

    public function testImportsAndDeduplicatesStatements(): void
    {
        $statement = [
            'id'          => 'statement-1',
            'time'        => now()->subMinutes(5)->timestamp,
            'amount'      => -12345,
            'currencyCode'=> 980,
            'description' => 'Coffee shop',
            'mcc'         => 5814,
        ];

        $mock = Mockery::mock(MonobankClient::class);
        $mock->shouldReceive('getStatements')->twice()->andReturn([$statement], [$statement]);
        app()->instance(MonobankClient::class, $mock);

        /** @var MonobankImportService $service */
        $service = app(MonobankImportService::class);

        $firstRun = $service->syncConnection($this->connection, 'manual');
        $secondRun = $service->syncConnection($this->connection, 'manual');

        $this->assertSame('success', $firstRun->status);
        $this->assertSame(1, $firstRun->stats_json['imported']);
        $this->assertSame(0, $firstRun->stats_json['duplicates']);
        $this->assertSame(0, $secondRun->stats_json['imported']);
        $this->assertSame(1, $secondRun->stats_json['duplicates']);
        $this->assertSame(1, TransactionJournalMeta::where('name', 'external_id')->count());

        $this->mapping->refresh();
        $this->assertSame('statement-1', $this->mapping->last_seen_statement_id);
        $this->assertSame($statement['time'], $this->mapping->last_synced_statement_ts);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createAuthenticatedUser();
        $this->actingAs($this->user);

        $this->assetAccount = Account::factory()
            ->for($this->user)
            ->withType(AccountTypeEnum::ASSET)
            ->create(['name' => 'Monobank asset'])
        ;

        $this->connection = BankConnection::create([
            'user_id'             => $this->user->id,
            'user_group_id'       => $this->user->user_group_id,
            'provider'            => 'monobank',
            'status'              => 'active',
            'access_token'        => 'token-1234567890',
            'webhook_path_secret' => 'secret-1',
            'webhook_enabled'     => false,
        ]);

        $this->mapping = BankConnectionAccount::create([
            'bank_connection_id' => $this->connection->id,
            'mono_account_id'    => 'mono-account-1',
            'mono_currency_code' => 980,
            'firefly_account_id' => $this->assetAccount->id,
            'enabled'            => true,
            'sync_from_ts'       => now()->subDays(3)->timestamp,
        ]);
    }
}
