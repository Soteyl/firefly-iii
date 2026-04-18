<?php

declare(strict_types=1);

namespace FireflyIII\Services\Revolut;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Exceptions\RevolutException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankSyncRun;
use Illuminate\Support\Collection;
use Safe\Exceptions\JsonException;

class RevolutSyncService
{
    public function __construct(
        private readonly RevolutClient $client,
        private readonly EnableBankingClient $enableBankingClient,
        private readonly RevolutAccountMapper $accountMapper,
        private readonly RevolutImportService $importService,
    ) {
    }

    /**
     * @throws RevolutException
     */
    public function refreshAccounts(BankConnection $connection): Collection
    {
        if ($this->usesEnableBanking($connection)) {
            return $this->refreshAccountsFromEnableBanking($connection);
        }

        $deviceId = $this->deviceId($connection);
        $wallet = $this->client->getWallet((string) $connection->access_token, $deviceId);

        $accounts = $this->accountMapper->syncConnectionAccounts($connection, $wallet);
        $connection->status = 'active';
        $connection->last_error_message = null;
        $connection->last_successful_sync_at = now();
        $connection->save();

        return $accounts;
    }

    /**
     * @throws RevolutException
     */
    public function validateToken(string $token, string $deviceId): array
    {
        return $this->client->getWallet($token, $deviceId);
    }

    /**
     * @throws RevolutException
     */
    public function startEnableBankingAuthorization(string $applicationId, string $country, string $redirectUrl, string $state): array
    {
        $privateKeyPath = $this->enableBankingKeyPath($applicationId);

        return $this->enableBankingClient->startAuthorization(
            $applicationId,
            $privateKeyPath,
            'Revolut',
            strtoupper(trim($country)),
            $redirectUrl,
            $state
        );
    }

    /**
     * @throws RevolutException
     */
    public function completeEnableBankingAuthorization(BankConnection $connection, string $applicationId, string $redirectResponseUrl): Collection
    {
        $privateKeyPath = $this->enableBankingKeyPath($applicationId);
        $code = $this->extractCode($redirectResponseUrl);
        if ('' === $code) {
            throw new RevolutException('Could not find authorization code in the provided redirect URL.');
        }
        $session = $this->enableBankingClient->createSession($applicationId, $privateKeyPath, $code);
        $sessionId = trim((string) ($session['session_id'] ?? ''));
        if ('' === $sessionId) {
            throw new RevolutException('Enable Banking did not return a session ID.');
        }
        $accounts = isset($session['accounts']) && is_array($session['accounts']) ? $session['accounts'] : [];

        $providerConfig = is_array($connection->provider_config) ? $connection->provider_config : [];
        $providerConfig['integration'] = 'enable_banking';
        $providerConfig['enable_banking'] = [
            'application_id'  => trim($applicationId),
            'country'         => strtoupper(trim((string) ($providerConfig['enable_banking']['country'] ?? 'IE'))),
            'session_id'      => $sessionId,
        ];
        $connection->provider_config = $providerConfig;
        $connection->status = 'active';
        $connection->last_error_message = null;
        $connection->last_successful_sync_at = now();
        $connection->save();

        return $this->accountMapper->syncEnableBankingAccounts($connection, $accounts);
    }

    /**
     * @throws FireflyException
     * @throws JsonException
     * @throws RevolutException
     */
    public function syncConnection(BankConnection $connection, string $trigger = 'manual'): BankSyncRun
    {
        $deviceId = $this->usesEnableBanking($connection) ? 'enable-banking' : $this->deviceId($connection);

        return $this->importService->syncConnection($connection, $deviceId, $trigger);
    }

    /**
     * @throws RevolutException
     */
    private function deviceId(BankConnection $connection): string
    {
        $config = is_array($connection->provider_config) ? $connection->provider_config : [];
        $deviceId = trim((string) ($config['device_id'] ?? ''));
        if ('' === $deviceId) {
            throw new RevolutException('Missing Revolut device ID for this connection.');
        }

        return $deviceId;
    }

    private function usesEnableBanking(BankConnection $connection): bool
    {
        $config = is_array($connection->provider_config) ? $connection->provider_config : [];

        return 'enable_banking' === trim((string) ($config['integration'] ?? ''));
    }

    /**
     * @throws RevolutException
     */
    private function refreshAccountsFromEnableBanking(BankConnection $connection): Collection
    {
        $config = is_array($connection->provider_config) ? $connection->provider_config : [];
        $enableConfig = is_array($config['enable_banking'] ?? null) ? $config['enable_banking'] : [];
        $applicationId = trim((string) ($enableConfig['application_id'] ?? ''));
        $sessionId = trim((string) ($enableConfig['session_id'] ?? ''));
        if ('' === $applicationId || '' === $sessionId) {
            throw new RevolutException('Enable Banking configuration is incomplete for this connection.');
        }
        $privateKeyPath = $this->enableBankingKeyPath($applicationId);

        $session = $this->enableBankingClient->getSession($applicationId, $privateKeyPath, $sessionId);
        $accounts = isset($session['accounts']) && is_array($session['accounts']) ? $session['accounts'] : [];
        $mapped = $this->accountMapper->syncEnableBankingAccounts($connection, $accounts);

        $connection->status = 'active';
        $connection->last_error_message = null;
        $connection->last_successful_sync_at = now();
        $connection->save();

        return $mapped;
    }

    private function extractCode(string $redirectResponseUrl): string
    {
        $parts = parse_url(trim($redirectResponseUrl));
        if (!is_array($parts)) {
            return '';
        }
        $query = $parts['query'] ?? '';
        if (!is_string($query) || '' === $query) {
            return '';
        }
        parse_str($query, $params);

        return trim((string) ($params['code'] ?? ''));
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
}
