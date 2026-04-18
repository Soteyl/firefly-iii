<?php

declare(strict_types=1);

namespace FireflyIII\Services\Revolut;

use FireflyIII\Exceptions\RevolutException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Safe\Exceptions\JsonException;

use function Safe\json_decode;

class EnableBankingClient
{
    private const string DEFAULT_BASE_URI = 'https://api.enablebanking.com/';

    public function getApplication(string $applicationId, string $privateKeyPath): array
    {
        return $this->request('GET', 'application', $applicationId, $privateKeyPath);
    }

    public function startAuthorization(
        string $applicationId,
        string $privateKeyPath,
        string $aspspName,
        string $country,
        string $redirectUrl,
        string $state
    ): array {
        return $this->request('POST', 'auth', $applicationId, $privateKeyPath, [
            'json' => [
                'access' => [
                    'valid_until' => now()->addDays(90)->utc()->toIso8601String(),
                ],
                'aspsp' => [
                    'name'    => $aspspName,
                    'country' => strtoupper($country),
                ],
                'state'        => $state,
                'redirect_url' => trim($redirectUrl),
                'psu_type'     => 'personal',
            ],
        ]);
    }

    public function createSession(string $applicationId, string $privateKeyPath, string $code): array
    {
        return $this->request('POST', 'sessions', $applicationId, $privateKeyPath, [
            'json' => ['code' => $code],
        ]);
    }

    public function getSession(string $applicationId, string $privateKeyPath, string $sessionId): array
    {
        return $this->request('GET', sprintf('sessions/%s', rawurlencode($sessionId)), $applicationId, $privateKeyPath);
    }

    public function getBalances(string $applicationId, string $privateKeyPath, string $accountUid): array
    {
        return $this->request('GET', sprintf('accounts/%s/balances', rawurlencode($accountUid)), $applicationId, $privateKeyPath);
    }

    public function getTransactions(string $applicationId, string $privateKeyPath, string $accountUid, string $dateFrom): array
    {
        $transactions = [];
        $continuationKey = null;

        do {
            $query = [
                'date_from'          => $dateFrom,
                'transaction_status' => 'BOOK',
            ];
            if (null !== $continuationKey) {
                $query['continuation_key'] = $continuationKey;
            }
            $response = $this->request(
                'GET',
                sprintf('accounts/%s/transactions', rawurlencode($accountUid)),
                $applicationId,
                $privateKeyPath,
                ['query' => $query]
            );

            $items = $response['transactions'] ?? [];
            if (is_array($items)) {
                $transactions = array_merge($transactions, $items);
            }
            $continuationKey = isset($response['continuation_key']) ? trim((string) $response['continuation_key']) : null;
            if ('' === $continuationKey) {
                $continuationKey = null;
            }
        } while (null !== $continuationKey);

        return $transactions;
    }

    /**
     * @throws RevolutException
     */
    private function request(string $method, string $path, string $applicationId, string $privateKeyPath, array $options = []): array
    {
        $token = $this->buildJwt($applicationId, $privateKeyPath);
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept'        => 'application/json',
            'Authorization' => sprintf('Bearer %s', $token),
        ]);
        $options['http_errors'] = false;
        $options['timeout'] = 20;
        $options['connect_timeout'] = 10;

        $client = new Client([
            'base_uri' => (string) config('services.enable_banking.base_uri', self::DEFAULT_BASE_URI),
        ]);

        try {
            $response = $client->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new RevolutException(sprintf('Enable Banking request failed: %s', $e->getMessage()), previous: $e);
        }

        $statusCode = $response->getStatusCode();
        $body = trim((string) $response->getBody());
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = '' === $body ? sprintf('Enable Banking returned HTTP %d.', $statusCode) : sprintf('Enable Banking returned HTTP %d: %s', $statusCode, $body);
            throw new RevolutException($message);
        }
        if ('' === $body) {
            return [];
        }

        try {
            /** @var array $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RevolutException('Enable Banking response is not valid JSON.', previous: $e);
        }

        return $decoded;
    }

    /**
     * @throws RevolutException
     */
    private function buildJwt(string $applicationId, string $privateKeyPath): string
    {
        $applicationId = trim($applicationId);
        $privateKeyPath = trim($privateKeyPath);
        if ('' === $applicationId || '' === $privateKeyPath) {
            throw new RevolutException('Enable Banking application ID or private key path is missing.');
        }
        if (!is_readable($privateKeyPath)) {
            throw new RevolutException(sprintf('Enable Banking private key path is not readable: %s', $privateKeyPath));
        }

        $iat = now()->timestamp;
        $payload = [
            'iss' => 'enablebanking.com',
            'aud' => 'api.enablebanking.com',
            'iat' => $iat,
            'exp' => $iat + 3600,
        ];
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $applicationId];
        $headerJson = json_encode($header);
        $payloadJson = json_encode($payload);
        if (!is_string($headerJson) || !is_string($payloadJson)) {
            throw new RevolutException('Could not encode Enable Banking JWT payload.');
        }
        $encodedHeader = $this->base64UrlEncode($headerJson);
        $encodedPayload = $this->base64UrlEncode($payloadJson);
        $signingInput = sprintf('%s.%s', $encodedHeader, $encodedPayload);

        $privateKey = openssl_pkey_get_private((string) file_get_contents($privateKeyPath));
        if (false === $privateKey) {
            throw new RevolutException('Could not read Enable Banking private key.');
        }
        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (is_resource($privateKey) || $privateKey instanceof \OpenSSLAsymmetricKey) {
            openssl_free_key($privateKey);
        }
        if (true !== $ok) {
            throw new RevolutException('Could not sign Enable Banking JWT.');
        }

        $jwt = sprintf('%s.%s', $signingInput, $this->base64UrlEncode($signature));
        if ('' === trim($jwt)) {
            throw new RevolutException('Could not build Enable Banking JWT.');
        }

        return $jwt;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
