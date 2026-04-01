<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

use FireflyIII\Exceptions\MonobankException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

use function Safe\json_decode;
use function Safe\json_encode;

class MonobankClient
{
    private const string BASE_URI = 'https://api.monobank.ua/';

    public function getClientInfo(string $token): array
    {
        $response = $this->request('GET', 'personal/client-info', $token);
        if (!is_array($response)) {
            throw new MonobankException('Monobank client info response is invalid.');
        }

        return $response;
    }

    public function getStatements(string $token, string $accountId, int $fromTimestamp, ?int $toTimestamp = null): array
    {
        $path     = sprintf('personal/statement/%s/%d', rawurlencode($accountId), $fromTimestamp);
        if (null !== $toTimestamp) {
            $path .= sprintf('/%d', $toTimestamp);
        }

        $response = $this->request('GET', $path, $token);
        if (!is_array($response)) {
            throw new MonobankException('Monobank statement response is invalid.');
        }

        return $response;
    }

    public function setWebhook(string $token, string $webhookUrl): void
    {
        $this->request('POST', 'personal/webhook', $token, [
            'json' => ['webHookUrl' => $webhookUrl],
        ]);
    }

    /**
     * @throws MonobankException
     */
    private function request(string $method, string $path, string $token, array $options = []): array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept'     => 'application/json',
            'User-Agent' => sprintf('FireflyIII/%s Monobank', config('firefly.version')),
            'X-Token'    => $token,
        ]);
        $options['http_errors'] = true;
        $options['timeout']     = 10;
        $options['connect_timeout'] = 5;

        $client = new Client([
            'base_uri' => self::BASE_URI,
        ]);

        try {
            $response = $client->request($method, $path, $options);
        } catch (ConnectException $e) {
            Log::error(sprintf('Monobank API connection failure for "%s %s": %s', $method, $path, $e->getMessage()));

            throw new MonobankException('Could not connect to Monobank API.', previous: $e);
        } catch (RequestException $e) {
            $message = $this->formatRequestException($e);
            Log::warning(sprintf('Monobank API request failure for "%s %s": %s', $method, $path, $message));

            throw new MonobankException($message, previous: $e);
        } catch (GuzzleException $e) {
            Log::error(sprintf('Unexpected Monobank API failure for "%s %s": %s', $method, $path, $e->getMessage()));

            throw new MonobankException('Unexpected Monobank API error.', previous: $e);
        }

        $body = (string) $response->getBody();
        if ('' === $body) {
            return [];
        }

        /** @var array $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function formatRequestException(RequestException $e): string
    {
        if (!$e->hasResponse()) {
            return $e->getMessage();
        }

        $response   = $e->getResponse();
        $statusCode = $response->getStatusCode();
        $body       = trim((string) $response->getBody());
        if ('' === $body) {
            return sprintf('Monobank API returned HTTP %d.', $statusCode);
        }

        $payload = null;
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // keep raw body below
        }

        if (is_array($payload) && isset($payload['errorDescription']) && is_string($payload['errorDescription'])) {
            return sprintf('Monobank API returned HTTP %d: %s', $statusCode, $payload['errorDescription']);
        }

        return sprintf('Monobank API returned HTTP %d: %s', $statusCode, $body);
    }
}
