<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\Models\BankSyncRun;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Safe\Exceptions\JsonException;
use function Safe\json_encode;

class MonobankImportService
{
    private const int INITIAL_LOOKBACK_SECONDS = 2_678_400;
    private const int SYNC_LOOKBACK_BUFFER_SECONDS = 3_600;

    public function __construct(
        private readonly MonobankClient $client,
        private readonly MonobankTransactionMapper $transactionMapper,
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

            foreach ($accounts as $mapping) {
                ++$stats['accounts_considered'];
                if (null === $mapping->firefly_account_id || false === $mapping->enabled) {
                    ++$stats['unmapped'];
                    continue;
                }

                ++$stats['accounts_polled'];
                $this->syncMappedAccount($connection, $mapping, $stats);
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
     * @param array<string, int> $stats
     *
     * @throws FireflyException
     * @throws JsonException
     * @throws MonobankException
     */
    private function syncMappedAccount(BankConnection $connection, BankConnectionAccount $mapping, array &$stats): void
    {
        $fromTimestamp = $this->fromTimestamp($mapping);
        $toTimestamp   = now()->timestamp;
        $statements    = $this->client->getStatements((string) $connection->access_token, $mapping->mono_account_id, $fromTimestamp, $toTimestamp);

        usort($statements, static fn (array $a, array $b): int => ((int) ($a['time'] ?? 0)) <=> ((int) ($b['time'] ?? 0)));

        $lastSeenId        = $mapping->last_seen_statement_id;
        $lastSyncedTime    = $mapping->last_synced_statement_ts;

        foreach ($statements as $statement) {
            $externalId = $this->transactionMapper->externalId($mapping, $statement);
            if (null === $externalId) {
                ++$stats['skipped'];
                continue;
            }
            if ($this->hasImportedStatement($connection, $externalId)) {
                ++$stats['duplicates'];
                $lastSeenId = (string) ($statement['id'] ?? $lastSeenId);
                $lastSyncedTime = max((int) $lastSyncedTime, (int) ($statement['time'] ?? 0));
                continue;
            }

            $mapped = $this->transactionMapper->mapStatement($mapping, $statement);
            if (null === $mapped) {
                ++$stats['skipped'];
                continue;
            }

            $this->transactionGroupRepository->store($mapped);
            ++$stats['imported'];

            $lastSeenId     = (string) ($statement['id'] ?? $lastSeenId);
            $lastSyncedTime = max((int) $lastSyncedTime, (int) ($statement['time'] ?? 0));
        }

        $mapping->last_seen_statement_id = $lastSeenId;
        $mapping->last_synced_statement_ts = $lastSyncedTime;
        $mapping->save();
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
            ->where('name', 'external_id')
            ->where('hash', hash('sha256', $encoded))
            ->where('data', $encoded)
            ->whereHas('transactionJournal', static function ($query) use ($connection): void {
                $query->where('user_id', $connection->user_id);
            })
            ->exists()
        ;
    }
}
