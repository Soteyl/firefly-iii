<?php

declare(strict_types=1);

namespace Tests\integration\Monobank;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Services\Monobank\MonobankClient;
use FireflyIII\Services\Monobank\MonobankAccountMapper;
use FireflyIII\Services\Monobank\MonobankImportService;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
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

    public function testAccountMapperStoresMultipleMaskedPans(): void
    {
        /** @var MonobankAccountMapper $mapper */
        $mapper = app(MonobankAccountMapper::class);

        $mapped = $mapper->syncConnectionAccounts($this->connection, [
            'accounts' => [[
                'id'           => 'mono-account-pan-list',
                'currencyCode' => 980,
                'maskedPan'    => [
                    '535838******4648',
                    '444111******0605',
                    '444111******6656',
                ],
                'iban'         => 'UA083220010000026202310597459',
            ]],
        ]);

        $this->assertCount(1, $mapped);
        $this->assertSame(
            '535838******4648, 444111******0605, 444111******6656',
            $mapped->first()?->mono_masked_pan
        );
    }

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
        $this->assertSame(
            1,
            TransactionJournalMeta::query()
                ->where('name', 'external_id')
                ->where('data', json_encode('monobank:mono-account-1:statement-1'))
                ->count()
        );

        $this->mapping->refresh();
        $this->assertSame('statement-1', $this->mapping->last_seen_statement_id);
        $this->assertSame($statement['time'], $this->mapping->last_synced_statement_ts);
    }

    public function testSkipsStatementWhenMatchingTransactionAlreadyExistsWithoutMonobankExternalId(): void
    {
        $statementTime = now()->subDay()->setTime(20, 59, 32);
        $counterparty = Account::factory()
            ->for($this->user)
            ->withType(AccountTypeEnum::EXPENSE)
            ->create(['name' => 'YouTube'])
        ;

        /** @var TransactionGroupRepositoryInterface $repository */
        $repository = app(TransactionGroupRepositoryInterface::class);

        $repository->store([
            'user'             => $this->user,
            'user_group'       => $this->user->userGroup,
            'apply_rules'      => false,
            'fire_webhooks'    => false,
            'batch_submission' => false,
            'transactions'     => [[
                'type'             => 'Withdrawal',
                'date'             => $statementTime->copy()->addHours(2),
                'amount'           => '149.00',
                'currency_code'    => 'UAH',
                'description'      => 'YouTube',
                'source_id'        => $this->assetAccount->id,
                'destination_id'   => $counterparty->id,
            ]],
        ]);

        $statement = [
            'id'           => 'statement-existing-match',
            'time'         => $statementTime->timestamp,
            'amount'       => -14900,
            'currencyCode' => 980,
            'description'  => 'YouTube',
        ];

        $mock = Mockery::mock(MonobankClient::class);
        $mock->shouldReceive('getStatements')->once()->andReturn([$statement]);
        app()->instance(MonobankClient::class, $mock);

        /** @var MonobankImportService $service */
        $service = app(MonobankImportService::class);

        $run = $service->syncConnection($this->connection, 'manual');

        $this->assertSame('success', $run->status);
        $this->assertSame(0, $run->stats_json['imported']);
        $this->assertSame(1, $run->stats_json['duplicates']);
        $this->assertSame(
            0,
            TransactionJournalMeta::query()
                ->where('name', 'external_id')
                ->where('data', json_encode('monobank:mono-account-1:statement-existing-match'))
                ->count()
        );
    }

    public function testFirstImportHonorsSyncFromDateAndSkipsOlderStatements(): void
    {
        $firstImportFrom = now()->subDays(2)->startOfDay()->timestamp;
        $this->mapping->sync_from_ts = $firstImportFrom;
        $this->mapping->last_synced_statement_ts = null;
        $this->mapping->save();

        $oldStatement = [
            'id'   => 'statement-too-old',
            'time' => now()->subDays(4)->timestamp,
        ];

        $mock = Mockery::mock(MonobankClient::class);
        $mock->shouldReceive('getStatements')
            ->once()
            ->withArgs(function (string $token, string $accountId, int $fromTimestamp, int $toTimestamp): bool {
                return 'token-1234567890' === $token
                    && 'mono-account-1' === $accountId
                    && $fromTimestamp === (int) $this->mapping->sync_from_ts
                    && $toTimestamp >= $fromTimestamp;
            })
            ->andReturn([$oldStatement])
        ;
        app()->instance(MonobankClient::class, $mock);

        /** @var MonobankImportService $service */
        $service = app(MonobankImportService::class);

        $run = $service->syncConnection($this->connection, 'manual');

        $this->assertSame('success', $run->status);
        $this->assertSame(0, $run->stats_json['imported']);
        $this->assertSame(1, $run->stats_json['skipped']);
    }

    public function testOngoingSyncUsesLastSyncedTimestampBuffer(): void
    {
        $this->mapping->sync_from_ts = now()->subDays(10)->timestamp;
        $this->mapping->last_synced_statement_ts = now()->subHours(5)->timestamp;
        $this->mapping->save();

        $mock = Mockery::mock(MonobankClient::class);
        $mock->shouldReceive('getStatements')
            ->once()
            ->withArgs(function (string $token, string $accountId, int $fromTimestamp, int $toTimestamp): bool {
                return 'token-1234567890' === $token
                    && 'mono-account-1' === $accountId
                    && $fromTimestamp === ((int) $this->mapping->last_synced_statement_ts) - 3600
                    && $toTimestamp >= $fromTimestamp;
            })
            ->andReturn([])
        ;
        app()->instance(MonobankClient::class, $mock);

        /** @var MonobankImportService $service */
        $service = app(MonobankImportService::class);

        $run = $service->syncConnection($this->connection, 'manual');

        $this->assertSame('success', $run->status);
        $this->assertSame(0, $run->stats_json['imported']);
        $this->assertSame(0, $run->stats_json['skipped']);
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
