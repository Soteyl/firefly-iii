<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Models\BankConnection;

class MonobankWebhookManager
{
    public function __construct(private readonly MonobankClient $client)
    {
    }

    /**
     * @throws MonobankException
     */
    public function registerWebhook(BankConnection $connection): bool
    {
        $url = $this->webhookUrl($connection);
        if (null === $url) {
            $connection->webhook_enabled = false;
            $connection->save();

            return false;
        }

        $this->client->setWebhook((string) $connection->access_token, $url);
        $connection->webhook_enabled = true;
        $connection->last_error_message = null;
        $connection->save();

        return true;
    }

    public function webhookUrl(BankConnection $connection): ?string
    {
        $baseUrl = trim((string) config('app.url'));
        if ('' === $baseUrl) {
            return null;
        }
        $baseUrl = rtrim($baseUrl, '/');
        if (!str_starts_with($baseUrl, 'https://')) {
            return null;
        }

        return sprintf('%s%s', $baseUrl, route('monobank.webhook', ['secret' => $connection->webhook_path_secret], false));
    }
}
