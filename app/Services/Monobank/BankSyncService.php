<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankSyncRun;
use FireflyIII\Exceptions\FireflyException;
use Illuminate\Support\Collection;
use Safe\Exceptions\JsonException;

class BankSyncService
{
    public function __construct(
        private readonly MonobankClient $client,
        private readonly MonobankAccountMapper $accountMapper,
        private readonly MonobankImportService $importService,
        private readonly MonobankWebhookManager $webhookManager,
    ) {
    }

    /**
     * @throws MonobankException
     */
    public function refreshAccounts(BankConnection $connection): Collection
    {
        $clientInfo = $this->client->getClientInfo((string) $connection->access_token);

        $accounts = $this->accountMapper->syncConnectionAccounts($connection, $clientInfo);
        try {
            $this->webhookManager->registerWebhook($connection);
        } catch (MonobankException $e) {
            $connection->webhook_enabled = false;
            $connection->last_error_message = $e->getMessage();
        }
        $connection->status = 'active';
        if (true === $connection->webhook_enabled) {
            $connection->last_error_message = null;
        }
        $connection->last_successful_sync_at = now();
        $connection->save();

        return $accounts;
    }

    /**
     * @throws MonobankException
     */
    public function validateToken(string $token): array
    {
        return $this->client->getClientInfo($token);
    }

    /**
     * @throws FireflyException
     * @throws JsonException
     * @throws MonobankException
     */
    public function syncConnection(BankConnection $connection, string $trigger = 'manual'): BankSyncRun
    {
        return $this->importService->syncConnection($connection, $trigger);
    }
}
