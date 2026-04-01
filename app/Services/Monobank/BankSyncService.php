<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Models\BankConnection;
use Illuminate\Support\Collection;

class BankSyncService
{
    public function __construct(private readonly MonobankClient $client, private readonly MonobankAccountMapper $accountMapper)
    {
    }

    /**
     * @throws MonobankException
     */
    public function refreshAccounts(BankConnection $connection): Collection
    {
        $clientInfo = $this->client->getClientInfo((string) $connection->access_token);

        $accounts = $this->accountMapper->syncConnectionAccounts($connection, $clientInfo);
        $connection->status = 'active';
        $connection->last_error_message = null;
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
}
