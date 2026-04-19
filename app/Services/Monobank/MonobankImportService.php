<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

use Carbon\Carbon;
use FireflyIII\Services\Banking\ImportCategoryRuleService;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\Models\BankSyncRun;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Safe\Exceptions\JsonException;

use function Safe\json_encode;

class MonobankImportService
{
    private const int INITIAL_LOOKBACK_SECONDS = 2_678_400;
    private const int SYNC_LOOKBACK_BUFFER_SECONDS = 3_600;
    private const int ACCOUNT_REQUEST_DELAY_MS = 1200;
    private const int INTERNAL_TRANSFER_MAX_TIME_DIFF_SECONDS = 120;

    public function __construct(
        private readonly MonobankClient $client,
        private readonly MonobankTransactionMapper $transactionMapper,
        private readonly ImportCategoryRuleService $categoryRuleService,
        private readonly TransactionGroupRepositoryInterface $transactionGroupRepository,
    ) {
    }

    /**
     * @throws FireflyException
     * @throws JsonException
     * @throws MonobankException
     */
    public function syncConnection(BankConnection $connection, string $trigger = 'manual'): BankSyncRun
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
            /** @var Collection<int, BankConnectionAccount> $accounts */
            $accounts = $connection->bankConnectionAccounts()
                ->with(['bankConnection.user.userGroup', 'fireflyAccount.accountType'])
                ->orderBy('id')
                ->get()
            ;

            $polledAccountCount = 0;
            /** @var array<int, array{mapping: BankConnectionAccount, statements: array<int, array>}> $statementsByMapping */
            $statementsByMapping = [];
            foreach ($accounts as $mapping) {
                ++$stats['accounts_considered'];
                if (null === $mapping->firefly_account_id || false === $mapping->enabled) {
                    ++$stats['unmapped'];
                    continue;
                }

                if ($polledAccountCount > 0) {
                    usleep(self::ACCOUNT_REQUEST_DELAY_MS * 1000);
                }
                ++$stats['accounts_polled'];
                ++$polledAccountCount;

                $fromTimestamp = $this->fromTimestamp($mapping);
                $toTimestamp   = now()->timestamp;
                $statements    = $this->client->getStatements((string) $connection->access_token, $mapping->mono_account_id, $fromTimestamp, $toTimestamp);
                usort($statements, static fn (array $a, array $b): int => ((int) ($a['time'] ?? 0)) <=> ((int) ($b['time'] ?? 0)));
                $statementsByMapping[(int) $mapping->id] = ['mapping' => $mapping, 'statements' => $statements];
            }

            $consumedExternalIds = [];
            foreach ($statementsByMapping as $entry) {
                $this->syncMappedAccount(
                    $connection,
                    $entry['mapping'],
                    $entry['statements'],
                    $statementsByMapping,
                    $consumedExternalIds,
                    $stats
                );
            }

            $run->status      = 'success';
            $run->finished_at = now();
            $run->stats_json  = $stats;
            $run->save();

            $connection->status = 'active';
            $connection->last_error_message = null;
            $connection->last_successful_sync_at = now();
            $connection->save();

            return $run;
        } catch (FireflyException|JsonException|MonobankException $e) {
            Log::warning(sprintf('Monobank sync failed for bank connection #%d: %s', $connection->id, $e->getMessage()));

            $run->status        = 'failed';
            $run->finished_at   = now();
            $run->stats_json    = $stats;
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
     * @param array<string, int>                                                  $stats
     * @param array<int, array{mapping: BankConnectionAccount, statements: array<int, array>}> $statementsByMapping
     * @param array<string, bool>                                                 $consumedExternalIds
     *
     * @throws FireflyException
     * @throws JsonException
     * @throws MonobankException
     */
    private function syncMappedAccount(
        BankConnection $connection,
        BankConnectionAccount $mapping,
        array $statements,
        array $statementsByMapping,
        array &$consumedExternalIds,
        array &$stats
    ): void {
        $lastSeenId     = $mapping->last_seen_statement_id;
        $lastSyncedTime = $mapping->last_synced_statement_ts;

        foreach ($statements as $statement) {
            if ($this->isBeforeFirstImportStart($mapping, (int) ($statement['time'] ?? 0))) {
                ++$stats['skipped'];
                continue;
            }
            $externalId = $this->transactionMapper->externalId($mapping, $statement);
            if (null === $externalId) {
                ++$stats['skipped'];
                continue;
            }
            if (true === ($consumedExternalIds[$externalId] ?? false)) {
                ++$stats['duplicates'];
                $lastSeenId     = (string) ($statement['id'] ?? $lastSeenId);
                $lastSyncedTime = max((int) $lastSyncedTime, (int) ($statement['time'] ?? 0));
                continue;
            }
            if ($this->hasImportedStatement($connection, $externalId)) {
                ++$stats['duplicates'];
                $lastSeenId     = (string) ($statement['id'] ?? $lastSeenId);
                $lastSyncedTime = max((int) $lastSyncedTime, (int) ($statement['time'] ?? 0));
                continue;
            }

            $counterExternalId = null;
            $mapped            = null;
            $pair              = $this->findInternalTransferPair($mapping, $statement, $statementsByMapping, $consumedExternalIds);
            if (is_array($pair)) {
                /** @var BankConnectionAccount $counterparty */
                $counterparty      = $pair['mapping'];
                $counterExternalId = $pair['external_id'];
                $mapped            = $this->transactionMapper->mapInternalTransfer($mapping, $counterparty, $statement);
            }
            if (null === $mapped) {
                $mapped = $this->transactionMapper->mapStatement($mapping, $statement);
            }
            if (null === $mapped) {
                ++$stats['skipped'];
                continue;
            }
            if (isset($mapped['transactions'][0]) && is_array($mapped['transactions'][0])) {
                $mapped['transactions'][0] = $this->categoryRuleService->apply($connection, $statement, $mapped['transactions'][0]);
            }
            if ($this->matchesExistingJournal($connection, $mapped['transactions'][0] ?? [])) {
                ++$stats['duplicates'];
                $lastSeenId     = (string) ($statement['id'] ?? $lastSeenId);
                $lastSyncedTime = max((int) $lastSyncedTime, (int) ($statement['time'] ?? 0));
                continue;
            }

            /** @var TransactionGroup $group */
            $group = $this->transactionGroupRepository->store($mapped);
            if (null !== $counterExternalId) {
                $this->storePairExternalId($group, $counterExternalId);
                $consumedExternalIds[$counterExternalId] = true;
            }
            ++$stats['imported'];

            $lastSeenId     = (string) ($statement['id'] ?? $lastSeenId);
            $lastSyncedTime = max((int) $lastSyncedTime, (int) ($statement['time'] ?? 0));
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
        $syncFrom     = $mapping->sync_from_ts ?? $defaultStart;
        if (null === $mapping->last_synced_statement_ts) {
            return max((int) $syncFrom, $defaultStart);
        }

        return max((int) $syncFrom, ((int) $mapping->last_synced_statement_ts) - self::SYNC_LOOKBACK_BUFFER_SECONDS);
    }

    /**
     * @throws JsonException
     */
    private function hasImportedStatement(BankConnection $connection, string $externalId): bool
    {
        $encoded = json_encode($externalId);

        return TransactionJournalMeta::query()
            ->whereIn('name', ['external_id', 'monobank_pair_external_id'])
            ->where('hash', hash('sha256', $encoded))
            ->where('data', $encoded)
            ->whereHas('transactionJournal', static function ($query) use ($connection): void {
                $query->where('user_id', $connection->user_id);
            })
            ->exists()
        ;
    }

    /**
     * @param array<int, array{mapping: BankConnectionAccount, statements: array<int, array>}> $statementsByMapping
     * @param array<string, bool>                                                                $consumedExternalIds
     *
     * @return null|array{mapping: BankConnectionAccount, external_id: string}
     */
    private function findInternalTransferPair(
        BankConnectionAccount $mapping,
        array $statement,
        array $statementsByMapping,
        array $consumedExternalIds
    ): ?array {
        if (!$this->isPotentialInternalTransfer($statement)) {
            return null;
        }

        $amount      = (int) ($statement['amount'] ?? 0);
        $operation   = (int) ($statement['operationAmount'] ?? $amount);
        $statementTs = (int) ($statement['time'] ?? 0);
        if (0 === $amount || 0 === $statementTs) {
            return null;
        }

        foreach ($statementsByMapping as $entry) {
            $counterMapping = $entry['mapping'];
            if ((int) $counterMapping->id === (int) $mapping->id || null === $counterMapping->firefly_account_id || false === $counterMapping->enabled) {
                continue;
            }

            foreach ($entry['statements'] as $counterStatement) {
                if (!$this->isPotentialInternalTransfer($counterStatement)) {
                    continue;
                }

                $counterTs = (int) ($counterStatement['time'] ?? 0);
                if ($counterTs <= 0 || abs($statementTs - $counterTs) > self::INTERNAL_TRANSFER_MAX_TIME_DIFF_SECONDS) {
                    continue;
                }

                $counterExternalId = $this->transactionMapper->externalId($counterMapping, $counterStatement);
                if (null === $counterExternalId || true === ($consumedExternalIds[$counterExternalId] ?? false)) {
                    continue;
                }

                $counterAmount    = (int) ($counterStatement['amount'] ?? 0);
                $counterOperation = (int) ($counterStatement['operationAmount'] ?? $counterAmount);
                $mirrors          = ($counterAmount === -$operation && $counterOperation === -$amount)
                    || ($counterAmount === -$amount);
                if (!$mirrors) {
                    continue;
                }

                return ['mapping' => $counterMapping, 'external_id' => $counterExternalId];
            }
        }

        return null;
    }

    private function isPotentialInternalTransfer(array $statement): bool
    {
        $mcc = (int) ($statement['mcc'] ?? $statement['originalMcc'] ?? 0);
        if (4829 !== $mcc) {
            return false;
        }

        $description = mb_strtolower(trim((string) ($statement['description'] ?? '')));
        if ('' === $description) {
            return false;
        }

        if (str_starts_with($description, 'переказ')) {
            return true;
        }

        return str_starts_with($description, 'з ') && str_contains($description, 'картк');
    }

    private function storePairExternalId(TransactionGroup $group, string $externalId): void
    {
        $externalId = trim($externalId);
        if ('' === $externalId) {
            return;
        }

        /** @var null|TransactionJournal $journal */
        $journal = $group->transactionJournals()->first();
        if (!$journal instanceof TransactionJournal) {
            return;
        }

        $exists = TransactionJournalMeta::query()
            ->where('transaction_journal_id', $journal->id)
            ->where('name', 'monobank_pair_external_id')
            ->where('hash', hash('sha256', json_encode($externalId)))
            ->exists()
        ;
        if ($exists) {
            return;
        }

        $meta = new TransactionJournalMeta();
        $meta->transaction_journal_id = $journal->id;
        $meta->name = 'monobank_pair_external_id';
        $meta->data = $externalId;
        $meta->save();
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
