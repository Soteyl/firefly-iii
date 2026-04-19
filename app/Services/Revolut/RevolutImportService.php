<?php

declare(strict_types=1);

namespace FireflyIII\Services\Revolut;

use Carbon\Carbon;
use FireflyIII\Services\Banking\ImportCategoryRuleService;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Exceptions\RevolutException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\Models\BankSyncRun;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Safe\Exceptions\JsonException;

use function Safe\json_encode;

class RevolutImportService
{
    private const int INITIAL_LOOKBACK_SECONDS = 2_678_400;
    private const int SYNC_LOOKBACK_BUFFER_SECONDS = 3_600;

    public function __construct(
        private readonly RevolutClient $client,
        private readonly EnableBankingClient $enableBankingClient,
        private readonly RevolutTransactionMapper $transactionMapper,
        private readonly ImportCategoryRuleService $categoryRuleService,
        private readonly TransactionGroupRepositoryInterface $transactionGroupRepository,
    ) {
    }

    /**
     * @throws FireflyException
     * @throws JsonException
     * @throws RevolutException
     */
    public function syncConnection(BankConnection $connection, string $deviceId, string $trigger = 'manual'): BankSyncRun
    {
        $run = new BankSyncRun([
            'bank_connection_id' => $connection->id,
            'trigger'            => $trigger,
            'status'             => 'running',
            'started_at'         => now(),
        ]);
        $run->save();

        $stats = [
            'accounts_considered' => 0,
            'accounts_polled'     => 0,
            'imported'            => 0,
            'duplicates'          => 0,
            'skipped'             => 0,
            'unmapped'            => 0,
        ];

        try {
            $providerConfig = is_array($connection->provider_config) ? $connection->provider_config : [];
            $integration = trim((string) ($providerConfig['integration'] ?? 'manual'));

            /** @var Collection<int, BankConnectionAccount> $accounts */
            $accounts = $connection->bankConnectionAccounts()
                ->with(['bankConnection.user.userGroup', 'fireflyAccount.accountType'])
                ->whereNotNull('revolut_account_id')
                ->orderBy('id')
                ->get()
            ;

            foreach ($accounts as $mapping) {
                ++$stats['accounts_considered'];
                if (null === $mapping->firefly_account_id || false === $mapping->enabled) {
                    ++$stats['unmapped'];
                    continue;
                }

                ++$stats['accounts_polled'];
                if ('enable_banking' === $integration) {
                    $this->syncMappedEnableBankingAccount($connection, $mapping, $providerConfig, $stats);
                } else {
                    $this->syncMappedAccount($connection, $mapping, $deviceId, $stats);
                }
            }

            $run->status = 'success';
            $run->finished_at = now();
            $run->stats_json = $stats;
            $run->save();

            $connection->status = 'active';
            $connection->last_error_message = null;
            $connection->last_successful_sync_at = now();
            $connection->save();

            return $run;
        } catch (FireflyException|JsonException|RevolutException $e) {
            Log::warning(sprintf('Revolut sync failed for bank connection #%d: %s', $connection->id, $e->getMessage()));

            $run->status = 'failed';
            $run->finished_at = now();
            $run->stats_json = $stats;
            $run->error_message = $e->getMessage();
            $run->save();

            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            throw $e;
        }
    }

    /**
     * @param array<string, int> $stats
     *
     * @throws FireflyException
     * @throws JsonException
     * @throws RevolutException
     */
    private function syncMappedAccount(BankConnection $connection, BankConnectionAccount $mapping, string $deviceId, array &$stats): void
    {
        $fromTimestamp = $this->fromTimestamp($mapping);
        $fromTimestampMs = $fromTimestamp * 1000;
        $toTimestampMs = now()->timestamp * 1000;

        $transactions = $this->client->getTransactions(
            (string) $connection->access_token,
            $deviceId,
            $fromTimestampMs,
            $toTimestampMs,
            $mapping->revolut_wallet_id
        );

        usort($transactions, static fn (array $a, array $b): int => ((int) ($a['createdDate'] ?? 0)) <=> ((int) ($b['createdDate'] ?? 0)));

        $lastSeenId = $mapping->last_seen_statement_id;
        $lastSyncedTime = $mapping->last_synced_statement_ts;

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                ++$stats['skipped'];
                continue;
            }
            $createdAtTs = (int) floor(((int) ($transaction['createdDate'] ?? 0)) / 1000);
            if ($this->isBeforeFirstImportStart($mapping, $createdAtTs)) {
                ++$stats['skipped'];
                continue;
            }
            if ('DECLINED' === strtoupper((string) ($transaction['state'] ?? ''))) {
                ++$stats['skipped'];
                continue;
            }
            if (!$this->transactionMapper->matchesMapping($mapping, $transaction)) {
                ++$stats['skipped'];
                continue;
            }

            $externalId = $this->transactionMapper->externalId($mapping, $transaction);
            if (null === $externalId) {
                ++$stats['skipped'];
                continue;
            }

            if ($this->hasImportedStatement($connection, $externalId)) {
                ++$stats['duplicates'];
                $lastSeenId = (string) ($transaction['legId'] ?? $transaction['id'] ?? $lastSeenId);
                $lastSyncedTime = max((int) $lastSyncedTime, $createdAtTs);
                continue;
            }

            $mapped = $this->transactionMapper->mapTransaction($mapping, $transaction);
            if (null === $mapped) {
                ++$stats['skipped'];
                continue;
            }
            if (isset($mapped['transactions'][0]) && is_array($mapped['transactions'][0])) {
                $mapped['transactions'][0] = $this->categoryRuleService->apply($connection, $transaction, $mapped['transactions'][0]);
            }
            if ($this->matchesExistingJournal($connection, $mapped['transactions'][0] ?? [])) {
                ++$stats['duplicates'];
                $lastSeenId = (string) ($transaction['legId'] ?? $transaction['id'] ?? $lastSeenId);
                $lastSyncedTime = max((int) $lastSyncedTime, $createdAtTs);
                continue;
            }

            $this->transactionGroupRepository->store($mapped);
            ++$stats['imported'];

            $lastSeenId = (string) ($transaction['legId'] ?? $transaction['id'] ?? $lastSeenId);
            $lastSyncedTime = max((int) $lastSyncedTime, $createdAtTs);
        }

        $mapping->last_seen_statement_id = $lastSeenId;
        $mapping->last_synced_statement_ts = $lastSyncedTime;
        $mapping->save();
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, int>   $stats
     *
     * @throws FireflyException
     * @throws JsonException
     * @throws RevolutException
     */
    private function syncMappedEnableBankingAccount(
        BankConnection $connection,
        BankConnectionAccount $mapping,
        array $providerConfig,
        array &$stats
    ): void {
        $enableConfig = is_array($providerConfig['enable_banking'] ?? null) ? $providerConfig['enable_banking'] : [];
        $applicationId = trim((string) ($enableConfig['application_id'] ?? ''));
        if ('' === $applicationId) {
            throw new RevolutException('Enable Banking configuration is incomplete for this Revolut connection.');
        }
        $privateKeyPath = $this->enableBankingKeyPath($applicationId);

        $accountUid = $this->enableBankingAccountUid((string) $mapping->revolut_account_id);
        if (null === $accountUid) {
            ++$stats['skipped'];
            return;
        }

        $fromTimestamp = $this->fromTimestamp($mapping);
        $dateFrom = Carbon::createFromTimestamp($fromTimestamp, 'UTC')->toDateString();
        $transactions = $this->enableBankingClient->getTransactions($applicationId, $privateKeyPath, $accountUid, $dateFrom);
        usort($transactions, function (array $a, array $b): int {
            $left = (string) ($a['booking_date'] ?? $a['value_date'] ?? $a['transaction_date'] ?? '');
            $right = (string) ($b['booking_date'] ?? $b['value_date'] ?? $b['transaction_date'] ?? '');

            return strcmp($left, $right);
        });

        $lastSeenId = $mapping->last_seen_statement_id;
        $lastSyncedTime = $mapping->last_synced_statement_ts;

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                ++$stats['skipped'];
                continue;
            }
            $transactionTimestamp = $this->enableTransactionTimestamp($transaction);
            if ($this->isBeforeFirstImportStart($mapping, $transactionTimestamp)) {
                ++$stats['skipped'];
                continue;
            }

            $externalId = $this->transactionMapper->externalIdForEnable($mapping, $transaction);
            if ($this->hasImportedStatement($connection, $externalId)) {
                ++$stats['duplicates'];
                $lastSeenId = $externalId;
                $lastSyncedTime = max((int) $lastSyncedTime, $transactionTimestamp);
                continue;
            }

            $mapped = $this->transactionMapper->mapEnableBankingTransaction($mapping, $transaction);
            if (null === $mapped) {
                ++$stats['skipped'];
                continue;
            }
            if (isset($mapped['transactions'][0]) && is_array($mapped['transactions'][0])) {
                $mapped['transactions'][0] = $this->categoryRuleService->apply($connection, $transaction, $mapped['transactions'][0]);
            }
            if ($this->matchesExistingJournal($connection, $mapped['transactions'][0] ?? [])) {
                ++$stats['duplicates'];
                $lastSeenId = $externalId;
                $lastSyncedTime = max((int) $lastSyncedTime, $transactionTimestamp);
                continue;
            }

            $this->transactionGroupRepository->store($mapped);
            ++$stats['imported'];

            $lastSeenId = $externalId;
            $lastSyncedTime = max((int) $lastSyncedTime, $transactionTimestamp);
        }

        $mapping->last_seen_statement_id = $lastSeenId;
        $mapping->last_synced_statement_ts = $lastSyncedTime;
        $mapping->save();
    }

    private function isBeforeFirstImportStart(BankConnectionAccount $mapping, int $statementTimestamp): bool
    {
        if (null !== $mapping->last_synced_statement_ts) {
            return false;
        }
        if (null === $mapping->sync_from_ts) {
            return false;
        }
        if ($statementTimestamp <= 0) {
            return false;
        }

        return $statementTimestamp < (int) $mapping->sync_from_ts;
    }

    private function fromTimestamp(BankConnectionAccount $mapping): int
    {
        $defaultStart = now()->subSeconds(self::INITIAL_LOOKBACK_SECONDS)->timestamp;
        $syncFrom = $mapping->sync_from_ts ?? $defaultStart;
        if (null === $mapping->last_synced_statement_ts) {
            return max((int) $syncFrom, $defaultStart);
        }

        return max((int) $syncFrom, ((int) $mapping->last_synced_statement_ts) - self::SYNC_LOOKBACK_BUFFER_SECONDS);
    }

    private function enableBankingAccountUid(string $revolutAccountId): ?string
    {
        $value = trim($revolutAccountId);
        if (!str_starts_with($value, 'enable:')) {
            return null;
        }
        $uid = trim(substr($value, strlen('enable:')));

        return '' === $uid ? null : $uid;
    }

    private function enableTransactionTimestamp(array $transaction): int
    {
        $date = trim((string) ($transaction['booking_date'] ?? $transaction['value_date'] ?? $transaction['transaction_date'] ?? ''));
        if ('' === $date) {
            return 0;
        }
        try {
            return Carbon::parse($date, 'UTC')->endOfDay()->timestamp;
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * @throws RevolutException
     */
    private function enableBankingKeyPath(string $applicationId): string
    {
        $path = storage_path(sprintf('app/enable-banking-keys/%s.pem', strtolower(trim($applicationId))));
        if (!is_readable($path)) {
            throw new RevolutException('No uploaded private key found for selected Enable Banking application.');
        }

        return $path;
    }

    /**
     * @throws JsonException
     */
    private function hasImportedStatement(BankConnection $connection, string $externalId): bool
    {
        $encoded = json_encode($externalId);

        return TransactionJournalMeta::query()
            ->where('name', 'external_id')
            ->where('hash', hash('sha256', $encoded))
            ->where('data', $encoded)
            ->whereHas('transactionJournal', static function ($query) use ($connection): void {
                $query->where('user_id', $connection->user_id);
            })
            ->exists()
        ;
    }

    private function matchesExistingJournal(BankConnection $connection, array $transaction): bool
    {
        $type = (string) ($transaction['type'] ?? '');
        $description = trim((string) ($transaction['description'] ?? ''));
        $date = $transaction['date'] ?? null;
        $amount = (string) ($transaction['amount'] ?? '');
        if ('' === $type || '' === $description || '' === $amount || !$date instanceof \DateTimeInterface) {
            return false;
        }

        $transactionType = TransactionType::query()->where('type', $type)->first();
        if (!$transactionType instanceof TransactionType) {
            return false;
        }

        $mappedAccountId = $this->mappedAccountId($transaction);
        if (null === $mappedAccountId) {
            return false;
        }

        $signedAmount = $this->signedAmount($transaction);
        $counterAmount = bcsub('0', $signedAmount, 12);
        $from = Carbon::instance($date)->copy()->subHours(3);
        $to = Carbon::instance($date)->copy()->addHours(3);

        return TransactionJournal::query()
            ->where('user_id', $connection->user_id)
            ->where('transaction_type_id', $transactionType->id)
            ->where('description', $description)
            ->whereBetween('date', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->whereHas('transactions', static function ($query) use ($mappedAccountId, $signedAmount): void {
                $query->where('account_id', $mappedAccountId)->where('amount', $signedAmount);
            })
            ->whereHas('transactions', static function ($query) use ($counterAmount): void {
                $query->where('amount', $counterAmount);
            })
            ->exists()
        ;
    }

    private function mappedAccountId(array $transaction): ?int
    {
        if (isset($transaction['source_id']) && null !== $transaction['source_id']) {
            return (int) $transaction['source_id'];
        }
        if (isset($transaction['destination_id']) && null !== $transaction['destination_id']) {
            return (int) $transaction['destination_id'];
        }

        return null;
    }

    private function signedAmount(array $transaction): string
    {
        $amount = (string) ($transaction['amount'] ?? '0');

        return match ((string) ($transaction['type'] ?? '')) {
            'Withdrawal' => sprintf('-%s', ltrim($amount, '-')),
            default      => ltrim($amount, '+'),
        };
    }
}
