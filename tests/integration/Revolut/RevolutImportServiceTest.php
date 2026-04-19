<?php

declare(strict_types=1);

namespace Tests\integration\Revolut;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Services\Revolut\RevolutClient;
use FireflyIII\Services\Revolut\RevolutImportService;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Mockery;
use Override;
use Tests\integration\TestCase;

final class RevolutImportServiceTest extends TestCase
{
    private Account $assetAccount;
    private BankConnection $connection;
    private BankConnectionAccount $mapping;
    private User $user;

    public function testImportsAndDeduplicatesTransactions(): void
    {
        $transaction = [
            'id'          => 'rev-tx-1',
            'legId'       => 'rev-leg-1',
            'createdDate' => now()->subMinutes(5)->timestamp * 1000,
            'amount'      => -1234,
            'currency'    => 'EUR',
            'description' => 'Coffee shop',
            'category'    => 'Coffee',
            'type'        => 'CARD_PAYMENT',
            'state'       => 'COMPLETED',
            'merchant'    => ['name' => 'Coffee shop'],
        ];

        $mock = Mockery::mock(RevolutClient::class);
        $mock->shouldReceive('getTransactions')->twice()->andReturn([$transaction], [$transaction]);
        app()->instance(RevolutClient::class, $mock);

        /** @var RevolutImportService $service */
        $service = app(RevolutImportService::class);

        $firstRun = $service->syncConnection($this->connection, 'device-123', 'manual');
        $secondRun = $service->syncConnection($this->connection, 'device-123', 'manual');

        $this->assertSame('success', $firstRun->status);
        $this->assertSame(1, $firstRun->stats_json['imported']);
        $this->assertSame(0, $firstRun->stats_json['duplicates']);
        $this->assertSame(0, $secondRun->stats_json['imported']);
        $this->assertSame(1, $secondRun->stats_json['duplicates']);
        $this->assertSame(
            1,
            TransactionJournalMeta::query()
                ->where('name', 'external_id')
                ->where('data', json_encode('revolut:wallet-main:pocket-eur:rev-leg-1'))
                ->count()
        );

        $this->mapping->refresh();
        $this->assertSame('rev-leg-1', $this->mapping->last_seen_statement_id);
    }

    public function testFirstImportHonorsSyncFromDateAndSkipsOlderTransactions(): void
    {
        $firstImportFrom = now()->subDays(2)->startOfDay()->timestamp;
        $this->mapping->sync_from_ts = $firstImportFrom;
        $this->mapping->last_synced_statement_ts = null;
        $this->mapping->save();

        $oldTransaction = [
            'id'          => 'rev-tx-too-old',
            'legId'       => 'rev-leg-too-old',
            'createdDate' => now()->subDays(5)->timestamp * 1000,
            'state'       => 'COMPLETED',
            'currency'    => 'EUR',
        ];

        $mock = Mockery::mock(RevolutClient::class);
        $mock->shouldReceive('getTransactions')
            ->once()
            ->withArgs(function (string $token, string $deviceId, int $fromTimestampMs, int $toTimestampMs, ?string $walletId): bool {
                return 'token-1234567890' === $token
                    && 'device-123' === $deviceId
                    && $fromTimestampMs === ((int) $this->mapping->sync_from_ts * 1000)
                    && $toTimestampMs >= $fromTimestampMs
                    && $walletId === $this->mapping->revolut_wallet_id;
            })
            ->andReturn([$oldTransaction])
        ;
        app()->instance(RevolutClient::class, $mock);

        /** @var RevolutImportService $service */
        $service = app(RevolutImportService::class);

        $run = $service->syncConnection($this->connection, 'device-123', 'manual');

        $this->assertSame('success', $run->status);
        $this->assertSame(0, $run->stats_json['imported']);
        $this->assertSame(1, $run->stats_json['skipped']);
    }

    public function testAppliesOverrideAndMccCategoryRules(): void
    {
        $mccCategory = Category::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'name'          => 'Groceries',
        ]);
        $overrideCategory = Category::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'name'          => 'Special payment',
        ]);

        Preferences::setForUser($this->user, 'bank_connection_category_rules', [
            'mcc'       => [
                ['mcc' => '5411', 'category_id' => $mccCategory->id, 'enabled' => true],
            ],
            'overrides' => [
                [
                    'external_id'          => 'revolut:wallet-main:pocket-eur:rev-leg-override',
                    'description_contains' => '',
                    'mcc'                  => '',
                    'category_id'          => $overrideCategory->id,
                    'enabled'              => true,
                ],
            ],
        ]);

        $baseTime = now()->subMinutes(8)->timestamp * 1000;
        $mccTransaction = [
            'id'          => 'rev-tx-mcc',
            'legId'       => 'rev-leg-mcc',
            'createdDate' => $baseTime,
            'amount'      => -4500,
            'currency'    => 'EUR',
            'description' => 'MCC purchase',
            'category'    => 'shopping',
            'type'        => 'CARD_PAYMENT',
            'state'       => 'COMPLETED',
            'merchant'    => ['name' => 'Market', 'categoryCode' => '5411'],
        ];
        $overrideTransaction = [
            'id'          => 'rev-tx-override',
            'legId'       => 'rev-leg-override',
            'createdDate' => $baseTime + 1000,
            'amount'      => -5500,
            'currency'    => 'EUR',
            'description' => 'Override purchase',
            'category'    => 'shopping',
            'type'        => 'CARD_PAYMENT',
            'state'       => 'COMPLETED',
            'merchant'    => ['name' => 'Market', 'categoryCode' => '5411'],
        ];

        $mock = Mockery::mock(RevolutClient::class);
        $mock->shouldReceive('getTransactions')->once()->andReturn([$mccTransaction, $overrideTransaction]);
        app()->instance(RevolutClient::class, $mock);

        /** @var RevolutImportService $service */
        $service = app(RevolutImportService::class);
        $run = $service->syncConnection($this->connection, 'device-123', 'manual');

        $this->assertSame('success', $run->status);
        $this->assertSame(2, $run->stats_json['imported']);

        /** @var TransactionJournalMeta $mccMeta */
        $mccMeta = TransactionJournalMeta::query()
            ->where('name', 'external_id')
            ->where('data', json_encode('revolut:wallet-main:pocket-eur:rev-leg-mcc'))
            ->firstOrFail()
        ;
        $this->assertSame('Groceries', $mccMeta->transactionJournal->categories()->firstOrFail()->name);

        /** @var TransactionJournalMeta $overrideMeta */
        $overrideMeta = TransactionJournalMeta::query()
            ->where('name', 'external_id')
            ->where('data', json_encode('revolut:wallet-main:pocket-eur:rev-leg-override'))
            ->firstOrFail()
        ;
        $this->assertSame('Special payment', $overrideMeta->transactionJournal->categories()->firstOrFail()->name);
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
            ->create(['name' => 'Revolut asset'])
        ;

        $this->connection = BankConnection::create([
            'user_id'             => $this->user->id,
            'user_group_id'       => $this->user->user_group_id,
            'provider'            => 'revolut',
            'status'              => 'active',
            'access_token'        => 'token-1234567890',
            'provider_config'     => ['device_id' => 'device-123'],
            'webhook_path_secret' => 'secret-revolut-1',
            'webhook_enabled'     => false,
        ]);

        $this->mapping = BankConnectionAccount::create([
            'bank_connection_id'    => $this->connection->id,
            'mono_account_id'       => 'not-used-for-revolut',
            'mono_account_type'     => 'BLACK',
            'revolut_account_id'    => 'wallet-main:pocket-eur',
            'revolut_wallet_id'     => 'wallet-main',
            'revolut_account_type'  => 'CURRENT',
            'revolut_currency_code' => 'EUR',
            'revolut_account_name'  => 'EUR CURRENT',
            'revolut_is_vault'      => false,
            'firefly_account_id'    => $this->assetAccount->id,
            'enabled'               => true,
            'sync_from_ts'          => now()->subDays(3)->timestamp,
        ]);
    }
}
