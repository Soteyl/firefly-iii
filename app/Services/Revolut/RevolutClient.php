<?php

declare(strict_types=1);

namespace FireflyIII\Services\Revolut;

use FireflyIII\Exceptions\RevolutException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

use function Safe\json_decode;

class RevolutClient
{
    private const string BASE_URI = 'https://api.revolut.com/';
    private const int MAX_RATE_LIMIT_RETRIES = 2;
    private const string DEFAULT_SIGNIN_TOKEN = 'QXBwOlM5V1VuU0ZCeTY3Z1dhbjc=';

    public function getWallet(string $token, string $deviceId): array
    {
        $response = $this->request('GET', 'user/current/wallet', $token, $deviceId);
        if (!is_array($response)) {
            throw new RevolutException('Revolut wallet response is invalid.');
        }

        return $response;
    }

    public function getTransactions(string $token, string $deviceId, int $fromTimestampMs, ?int $toTimestampMs = null, ?string $walletId = null): array
    {
        $query = ['from' => $fromTimestampMs];
        if (null !== $toTimestampMs) {
            $query['to'] = $toTimestampMs;
        }
        if (null !== $walletId && '' !== trim($walletId)) {
            $query['walletId'] = $walletId;
        }

        $response = $this->request('GET', 'user/current/transactions/last', $token, $deviceId, ['query' => $query]);
        if (!is_array($response)) {
            throw new RevolutException('Revolut transactions response is invalid.');
        }

        return $response;
    }

    public function requestSigninCode(string $deviceId, string $phone, string $password): string
    {
        $response = $this->request('POST', 'signin', self::DEFAULT_SIGNIN_TOKEN, $deviceId, [
            'json' => [
                'phone'    => $phone,
                'password' => $password,
            ],
        ]);

        $channel = strtoupper(trim((string) ($response['channel'] ?? '')));
        if ('' === $channel) {
            throw new RevolutException('Revolut signin did not return a verification channel.');
        }

        return $channel;
    }

    public function confirmSigninCode(string $deviceId, string $phone, string $code): array
    {
        $cleanCode = str_replace('-', '', trim($code));
        if ('' === $cleanCode) {
            throw new RevolutException('Revolut verification code is empty.');
        }

        $response = $this->request('POST', 'signin/confirm', self::DEFAULT_SIGNIN_TOKEN, $deviceId, [
            'json' => [
                'phone' => $phone,
                'code'  => $cleanCode,
            ],
        ]);

        if (!is_array($response)) {
            throw new RevolutException('Revolut signin confirmation response is invalid.');
        }

        return $response;
    }

    /**
     * @throws RevolutException
     */
    private function request(string $method, string $path, string $token, string $deviceId, array $options = []): array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept'           => 'application/json',
            'X-Api-Version'    => '1',
            'X-Client-Version' => '6.34.3',
            'X-Device-Id'      => $deviceId,
            'User-Agent'       => 'Revolut/5.5 500500250 (FireflyIII)',
            'Authorization'    => sprintf('Basic %s', $token),
        ]);
        $options['http_errors'] = true;
        $options['timeout'] = 10;
        $options['connect_timeout'] = 5;

        $client = new Client([
            'base_uri' => self::BASE_URI,
        ]);

        $attempt = 0;
        while (true) {
            try {
                $response = $client->request($method, $path, $options);
                break;
            } catch (ConnectException $e) {
                Log::error(sprintf('Revolut API connection failure for "%s %s": %s', $method, $path, $e->getMessage()));

                throw new RevolutException('Could not connect to Revolut API.', previous: $e);
            } catch (RequestException $e) {
                if ($this->shouldRetryRateLimit($e, $attempt)) {
                    $delay = $this->retryDelaySeconds($e, $attempt);
                    Log::notice(sprintf(
                        'Revolut API rate limited "%s %s", retrying in %d second(s) (attempt %d/%d).',
                        $method,
                        $path,
                        $delay,
                        $attempt + 1,
                        self::MAX_RATE_LIMIT_RETRIES
                    ));
                    sleep($delay);
                    ++$attempt;
                    continue;
                }

                $message = $this->formatRequestException($e);
                Log::warning(sprintf('Revolut API request failure for "%s %s": %s', $method, $path, $message));

                throw new RevolutException($message, previous: $e);
            } catch (GuzzleException $e) {
                Log::error(sprintf('Unexpected Revolut API failure for "%s %s": %s', $method, $path, $e->getMessage()));

                throw new RevolutException('Unexpected Revolut API error.', previous: $e);
            }
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

        $response = $e->getResponse();
        $statusCode = $response->getStatusCode();
        $body = trim((string) $response->getBody());
        if ('' === $body) {
            return sprintf('Revolut API returned HTTP %d.', $statusCode);
        }

        return sprintf('Revolut API returned HTTP %d: %s', $statusCode, $body);
    }

    private function retryDelaySeconds(RequestException $e, int $attempt): int
    {
        $response = $e->getResponse();
        if (null !== $response && $response->hasHeader('Retry-After')) {
            $value = trim($response->getHeaderLine('Retry-After'));
            if (ctype_digit($value)) {
                return max(1, min(10, (int) $value));
            }
        }

        return min(10, 2 + $attempt);
    }

    private function shouldRetryRateLimit(RequestException $e, int $attempt): bool
    {
        $response = $e->getResponse();

        return null !== $response
            && 429 === $response->getStatusCode()
            && $attempt < self::MAX_RATE_LIMIT_RETRIES;
    }
}
