<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Exceptions\RevolutException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Services\Monobank\BankSyncService;
use FireflyIII\Services\Revolut\EnableBankingClient;
use FireflyIII\Services\Revolut\RevolutSyncService;
use FireflyIII\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Safe\Exceptions\JsonException;

use function trans_choice;

final class BankConnectionController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(static function ($request, $next) {
            app('view')->share('title', (string) trans('firefly.bank_connections'));
            app('view')->share('mainTitleIcon', 'fa-building-columns');

            return $next($request);
        });
    }

    public function index(AccountRepositoryInterface $accountRepository): Factory|View
    {
        /** @var User $user */
        $user = auth()->user();
        $accountRepository->setUser($user);
        $connections = $user->bankConnections()->with(['bankConnectionAccounts.fireflyAccount'])->orderBy('id')->get();
        $assetAccounts = $accountRepository->getActiveAccountsByType([
            AccountTypeEnum::ASSET->value,
            AccountTypeEnum::DEFAULT->value,
        ]);

        return view('preferences.bank-connections', [
            'connections'   => $connections,
            'assetAccounts' => $assetAccounts,
            'revolutEnableBankingApplications' => $this->uploadedEnableBankingApplications(),
        ]);
    }

    public function store(Request $request, BankSyncService $bankSyncService): RedirectResponse
    {
        $data = $request->validate([
            'access_token' => 'required|string|min:10|max:255',
        ]);

        /** @var User $user */
        $user = auth()->user();
        /** @var BankConnection $connection */
        $connection = $user->bankConnections()->firstOrNew(['provider' => 'monobank']);
        $connection->user_id = $user->id;
        $connection->user_group_id = $user->user_group_id;
        $connection->provider = 'monobank';
        $connection->status = 'pending';
        $connection->access_token = $data['access_token'];
        if (null === $connection->webhook_path_secret || '' === $connection->webhook_path_secret) {
            $connection->webhook_path_secret = Str::random(64);
        }
        $connection->save();

        try {
            $accounts = $bankSyncService->refreshAccounts($connection);
        } catch (MonobankException $e) {
            Log::warning(sprintf('Could not validate Monobank token for bank connection #%d: %s', $connection->id, $e->getMessage()));
            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            return redirect()->route('preferences.bank-connections.index')->withErrors(['access_token' => $e->getMessage()]);
        }

        return redirect()
            ->route('preferences.bank-connections.index')
            ->with('success', trans_choice('firefly.bank_connection_saved', $accounts->count(), ['count' => $accounts->count()]))
        ;
    }

    public function storeRevolut(Request $request, RevolutSyncService $revolutSyncService): RedirectResponse
    {
        $data = $request->validate([
            'revolut_access_token' => 'required|string|min:10|max:255',
            'revolut_device_id'    => 'required|string|min:4|max:255',
        ]);

        /** @var User $user */
        $user = auth()->user();
        /** @var BankConnection $connection */
        $connection = $user->bankConnections()->firstOrNew(['provider' => 'revolut']);
        $connection->user_id = $user->id;
        $connection->user_group_id = $user->user_group_id;
        $connection->provider = 'revolut';
        $connection->status = 'pending';
        $connection->access_token = $data['revolut_access_token'];
        $connection->provider_config = [
            'device_id' => $data['revolut_device_id'],
        ];
        if (null === $connection->webhook_path_secret || '' === $connection->webhook_path_secret) {
            $connection->webhook_path_secret = Str::random(64);
        }
        $connection->save();

        try {
            $accounts = $revolutSyncService->refreshAccounts($connection);
        } catch (RevolutException $e) {
            Log::warning(sprintf('Could not validate Revolut token for bank connection #%d: %s', $connection->id, $e->getMessage()));
            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            return redirect()->route('preferences.bank-connections.index')->withErrors(['revolut_access_token' => $e->getMessage()]);
        }

        return redirect()
            ->route('preferences.bank-connections.index')
            ->with('success', trans_choice('firefly.revolut_connection_saved', $accounts->count(), ['count' => $accounts->count()]))
        ;
    }

    public function requestRevolutEnableBankingAuth(Request $request, RevolutSyncService $revolutSyncService): RedirectResponse
    {
        $data = $request->validate([
            'revolut_eb_application_id' => 'required|uuid',
            'revolut_eb_country' => 'required|string|size:2',
        ]);

        $applicationId = trim((string) $data['revolut_eb_application_id']);
        $country = strtoupper(trim((string) $data['revolut_eb_country']));
        $state = $this->buildEnableBankingState($applicationId, $country);
        $redirectUrl = route('preferences.bank-connections.revolut.enable-banking.callback');

        try {
            $auth = $revolutSyncService->startEnableBankingAuthorization($applicationId, $country, $redirectUrl, $state);
            $authUrl = trim((string) ($auth['url'] ?? ''));
            if ('' === $authUrl) {
                throw new RevolutException('Enable Banking did not return an authorization URL.');
            }
        } catch (RevolutException $e) {
            Log::warning(sprintf('Could not start Enable Banking authorization for Revolut: %s', $e->getMessage()));

            return redirect()
                ->route('preferences.bank-connections.index')
                ->withErrors(['revolut_eb_application_id' => $e->getMessage()])
                ->withInput()
            ;
        }

        return redirect()->away($authUrl);
    }

    public function uploadRevolutEnableBankingKey(Request $request, EnableBankingClient $enableBankingClient): RedirectResponse
    {
        $data = $request->validate([
            'revolut_eb_private_key_file' => 'required|file|max:256',
        ]);

        /** @var UploadedFile $file */
        $file = $data['revolut_eb_private_key_file'];
        $original = strtolower(trim((string) $file->getClientOriginalName()));
        if (!str_ends_with($original, '.pem')) {
            return redirect()->route('preferences.bank-connections.index')
                ->withErrors(['revolut_eb_private_key_file' => (string) trans('firefly.revolut_enable_banking_invalid_pem')])
                ->withInput()
            ;
        }

        $contents = (string) file_get_contents($file->getRealPath());
        if ('' === trim($contents)) {
            return redirect()->route('preferences.bank-connections.index')
                ->withErrors(['revolut_eb_private_key_file' => (string) trans('firefly.revolut_enable_banking_invalid_pem')])
                ->withInput()
            ;
        }

        $privateKey = openssl_pkey_get_private($contents);
        if (false === $privateKey) {
            return redirect()->route('preferences.bank-connections.index')
                ->withErrors(['revolut_eb_private_key_file' => (string) trans('firefly.revolut_enable_banking_invalid_pem')])
                ->withInput()
            ;
        }
        if (is_resource($privateKey) || $privateKey instanceof \OpenSSLAsymmetricKey) {
            openssl_free_key($privateKey);
        }

        $applicationId = '';
        if (preg_match('/([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/', $original, $matches)) {
            $applicationId = strtolower((string) ($matches[1] ?? ''));
        }
        if ('' === $applicationId) {
            return redirect()->route('preferences.bank-connections.index')
                ->withErrors(['revolut_eb_private_key_file' => (string) trans('firefly.revolut_enable_banking_invalid_file_name')])
                ->withInput()
            ;
        }

        $directory = storage_path('app/enable-banking-keys');
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $targetPath = sprintf('%s/%s.pem', $directory, strtolower($applicationId));
        file_put_contents($targetPath, $contents);
        chmod($targetPath, 0600);

        try {
            $application = $enableBankingClient->getApplication($applicationId, $targetPath);
        } catch (RevolutException $e) {
            @unlink($targetPath);

            return redirect()->route('preferences.bank-connections.index')
                ->withErrors(['revolut_eb_private_key_file' => $e->getMessage()])
                ->withInput()
            ;
        }
        $name = trim((string) ($application['name'] ?? ''));

        return redirect()->route('preferences.bank-connections.index')
            ->with('success', (string) trans('firefly.revolut_enable_banking_key_uploaded', [
                'application' => '' === $name ? $applicationId : $name,
                'kid'         => $applicationId,
            ]))
            ->withInput([
                'revolut_eb_application_id' => $applicationId,
            ])
        ;
    }

    public function completeRevolutEnableBankingAuth(Request $request, RevolutSyncService $revolutSyncService): RedirectResponse
    {
        $data = $request->validate([
            'revolut_eb_application_id' => 'required|uuid',
            'revolut_eb_country' => 'required|string|size:2',
            'revolut_eb_redirect_response_url' => 'required|url|max:2000',
        ]);

        $applicationId = trim((string) $data['revolut_eb_application_id']);
        $country = strtoupper(trim((string) $data['revolut_eb_country']));
        $redirectResponseUrl = trim((string) $data['revolut_eb_redirect_response_url']);

        /** @var User $user */
        $user = auth()->user();
        /** @var BankConnection $connection */
        $connection = $user->bankConnections()->firstOrNew(['provider' => 'revolut']);
        $connection->user_id = $user->id;
        $connection->user_group_id = $user->user_group_id;
        $connection->provider = 'revolut';
        $connection->status = 'pending';
        if (null === $connection->access_token || '' === trim((string) $connection->access_token)) {
            // DB schema requires a non-null token; Enable Banking auth is stored in provider_config.
            $connection->access_token = 'enable_banking';
        }
        $connection->provider_config = [
            'integration'     => 'enable_banking',
            'enable_banking'  => [
                'application_id'   => $applicationId,
                'country'          => $country,
            ],
        ];
        if (null === $connection->webhook_path_secret || '' === $connection->webhook_path_secret) {
            $connection->webhook_path_secret = Str::random(64);
        }
        $connection->save();

        try {
            $accounts = $revolutSyncService->completeEnableBankingAuthorization($connection, $applicationId, $redirectResponseUrl);
        } catch (RevolutException $e) {
            Log::warning(sprintf('Could not complete Enable Banking authorization for Revolut bank connection #%d: %s', $connection->id, $e->getMessage()));
            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            return redirect()->route('preferences.bank-connections.index')->withErrors(['revolut_eb_redirect_response_url' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('preferences.bank-connections.index')
            ->with('success', trans_choice('firefly.revolut_connection_saved', $accounts->count(), ['count' => $accounts->count()]))
        ;
    }

    public function revolutEnableBankingCallback(Request $request, RevolutSyncService $revolutSyncService): RedirectResponse
    {
        $error = trim((string) $request->query('error', ''));
        $errorDescription = trim((string) $request->query('error_description', ''));
        if ('' !== $error) {
            $message = '' === $errorDescription ? sprintf('Enable Banking authorization failed: %s', $error) : sprintf('Enable Banking authorization failed: %s (%s)', $error, $errorDescription);

            return redirect()->route('preferences.bank-connections.index')->withErrors(['revolut_eb_application_id' => $message]);
        }

        $appId = trim((string) $request->query('app', ''));
        $country = strtoupper(trim((string) $request->query('country', 'IE')));
        $state = trim((string) $request->query('state', ''));
        $stateData = $this->parseEnableBankingState($state);
        if (is_array($stateData)) {
            $appId = (string) ($stateData['application_id'] ?? $appId);
            $country = (string) ($stateData['country'] ?? $country);
        }
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $appId)) {
            return redirect()->route('preferences.bank-connections.index')->withErrors(['revolut_eb_application_id' => (string) trans('firefly.revolut_enable_banking_callback_missing_app')]);
        }

        $fullUrl = $request->fullUrl();

        /** @var User $user */
        $user = auth()->user();
        /** @var BankConnection $connection */
        $connection = $user->bankConnections()->firstOrNew(['provider' => 'revolut']);
        $connection->user_id = $user->id;
        $connection->user_group_id = $user->user_group_id;
        $connection->provider = 'revolut';
        $connection->status = 'pending';
        if (null === $connection->access_token || '' === trim((string) $connection->access_token)) {
            // DB schema requires a non-null token; Enable Banking auth is stored in provider_config.
            $connection->access_token = 'enable_banking';
        }
        $connection->provider_config = [
            'integration'    => 'enable_banking',
            'enable_banking' => [
                'application_id' => strtolower($appId),
                'country'        => $country,
            ],
        ];
        if (null === $connection->webhook_path_secret || '' === $connection->webhook_path_secret) {
            $connection->webhook_path_secret = Str::random(64);
        }
        $connection->save();

        try {
            $accounts = $revolutSyncService->completeEnableBankingAuthorization($connection, strtolower($appId), $fullUrl);
        } catch (RevolutException $e) {
            Log::warning(sprintf('Could not complete Enable Banking callback for Revolut bank connection #%d: %s', $connection->id, $e->getMessage()));
            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            return redirect()->route('preferences.bank-connections.index')->withErrors(['revolut_eb_application_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('preferences.bank-connections.index')
            ->with('success', trans_choice('firefly.revolut_connection_saved', $accounts->count(), ['count' => $accounts->count()]))
        ;
    }

    public function validateToken(int $id, BankSyncService $bankSyncService): RedirectResponse
    {
        $connection = $this->findConnectionByProvider($id, 'monobank');

        try {
            $bankSyncService->validateToken((string) $connection->access_token);
            $connection->status = 'active';
            $connection->last_error_message = null;
            $connection->save();
        } catch (MonobankException $e) {
            Log::warning(sprintf('Monobank token validation failed for bank connection #%d: %s', $connection->id, $e->getMessage()));
            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            return redirect()->route('preferences.bank-connections.index')->withErrors(['access_token' => $e->getMessage()]);
        }

        return redirect()->route('preferences.bank-connections.index')->with('success', (string) trans('firefly.bank_connection_validated'));
    }

    public function validateRevolutToken(int $id, RevolutSyncService $revolutSyncService): RedirectResponse
    {
        $connection = $this->findConnectionByProvider($id, 'revolut');
        $config = is_array($connection->provider_config) ? $connection->provider_config : [];
        $integration = trim((string) ($config['integration'] ?? 'manual'));
        $deviceId = trim((string) ($config['device_id'] ?? ''));

        try {
            if ('enable_banking' === $integration) {
                $revolutSyncService->refreshAccounts($connection);
            } else {
                $revolutSyncService->validateToken((string) $connection->access_token, $deviceId);
            }
            $connection->status = 'active';
            $connection->last_error_message = null;
            $connection->save();
        } catch (RevolutException $e) {
            Log::warning(sprintf('Revolut token validation failed for bank connection #%d: %s', $connection->id, $e->getMessage()));
            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            return redirect()->route('preferences.bank-connections.index')->withErrors(['revolut_access_token' => $e->getMessage()]);
        }

        return redirect()->route('preferences.bank-connections.index')->with('success', (string) trans('firefly.revolut_connection_validated'));
    }

    public function refreshAccounts(int $id, BankSyncService $bankSyncService): RedirectResponse
    {
        $connection = $this->findConnectionByProvider($id, 'monobank');

        try {
            $accounts = $bankSyncService->refreshAccounts($connection);
        } catch (MonobankException $e) {
            Log::warning(sprintf('Could not refresh Monobank accounts for bank connection #%d: %s', $connection->id, $e->getMessage()));
            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            return redirect()->route('preferences.bank-connections.index')->withErrors(['access_token' => $e->getMessage()]);
        }

        return redirect()
            ->route('preferences.bank-connections.index')
            ->with('success', trans_choice('firefly.bank_accounts_refreshed', $accounts->count(), ['count' => $accounts->count()]))
        ;
    }

    public function refreshRevolutAccounts(int $id, RevolutSyncService $revolutSyncService): RedirectResponse
    {
        $connection = $this->findConnectionByProvider($id, 'revolut');

        try {
            $accounts = $revolutSyncService->refreshAccounts($connection);
        } catch (RevolutException $e) {
            Log::warning(sprintf('Could not refresh Revolut accounts for bank connection #%d: %s', $connection->id, $e->getMessage()));
            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();

            return redirect()->route('preferences.bank-connections.index')->withErrors(['revolut_access_token' => $e->getMessage()]);
        }

        return redirect()
            ->route('preferences.bank-connections.index')
            ->with('success', trans_choice('firefly.revolut_accounts_refreshed', $accounts->count(), ['count' => $accounts->count()]))
        ;
    }

    public function updateMapping(Request $request, int $id, int $accountId): RedirectResponse
    {
        $connection = $this->findConnection($id);
        /** @var BankConnectionAccount $mapping */
        $mapping = $connection->bankConnectionAccounts()->findOrFail($accountId);

        $data = $request->validate([
            'firefly_account_id' => 'nullable|integer|exists:accounts,id',
            'enabled'            => 'nullable|boolean',
            'first_import_from_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ]);

        /** @var User $user */
        $user = auth()->user();
        $fireflyAccountId = null;
        if (array_key_exists('firefly_account_id', $data) && null !== $data['firefly_account_id']) {
            $fireflyAccountId = $user->accounts()->findOrFail((int) $data['firefly_account_id'])->id;
        }

        $mapping->firefly_account_id = $fireflyAccountId;
        $mapping->enabled = (bool) ($data['enabled'] ?? false);
        $firstImportDate = trim((string) ($data['first_import_from_date'] ?? ''));
        if (null === $mapping->last_synced_statement_ts && '' !== $firstImportDate) {
            $mapping->sync_from_ts = Carbon::createFromFormat('Y-m-d', $firstImportDate, (string) config('app.timezone'))
                ->startOfDay()
                ->timestamp
            ;
        }
        if (null === $mapping->sync_from_ts) {
            $mapping->sync_from_ts = now()->subDays(31)->startOfDay()->timestamp;
        }
        $mapping->save();

        return redirect()->route('preferences.bank-connections.index')->with('success', (string) trans('firefly.bank_account_mapping_saved'));
    }

    /**
     * @throws JsonException
     */
    public function sync(int $id, BankSyncService $bankSyncService): RedirectResponse
    {
        $connection = $this->findConnectionByProvider($id, 'monobank');

        try {
            $run = $bankSyncService->syncConnection($connection, 'manual');
        } catch (FireflyException|MonobankException $e) {
            Log::warning(sprintf('Could not sync Monobank bank connection #%d: %s', $connection->id, $e->getMessage()));

            return redirect()->route('preferences.bank-connections.index')->withErrors(['access_token' => $e->getMessage()]);
        }

        $stats = is_array($run->stats_json) ? $run->stats_json : [];
        $count = (int) ($stats['imported'] ?? 0);

        return redirect()
            ->route('preferences.bank-connections.index')
            ->with('success', trans_choice('firefly.bank_connection_synced', max(1, $count), ['count' => $count]))
        ;
    }

    /**
     * @throws JsonException
     */
    public function syncRevolut(int $id, RevolutSyncService $revolutSyncService): RedirectResponse
    {
        $connection = $this->findConnectionByProvider($id, 'revolut');

        try {
            $run = $revolutSyncService->syncConnection($connection, 'manual');
        } catch (FireflyException|RevolutException $e) {
            Log::warning(sprintf('Could not sync Revolut bank connection #%d: %s', $connection->id, $e->getMessage()));

            return redirect()->route('preferences.bank-connections.index')->withErrors(['revolut_access_token' => $e->getMessage()]);
        }

        $stats = is_array($run->stats_json) ? $run->stats_json : [];
        $count = (int) ($stats['imported'] ?? 0);

        return redirect()
            ->route('preferences.bank-connections.index')
            ->with('success', trans_choice('firefly.revolut_connection_synced', max(1, $count), ['count' => $count]))
        ;
    }

    private function findConnection(int $id): BankConnection
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->bankConnections()->with(['bankConnectionAccounts.fireflyAccount'])->findOrFail($id);
    }

    private function findConnectionByProvider(int $id, string $provider): BankConnection
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->bankConnections()
            ->with(['bankConnectionAccounts.fireflyAccount'])
            ->where('provider', $provider)
            ->findOrFail($id)
        ;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function uploadedEnableBankingApplications(): array
    {
        $directory = storage_path('app/enable-banking-keys');
        if (!is_dir($directory)) {
            return [];
        }

        /** @var EnableBankingClient $client */
        $client = app(EnableBankingClient::class);
        $applications = [];
        $files = glob(sprintf('%s/*.pem', $directory));
        if (false === $files) {
            return [];
        }

        foreach ($files as $filePath) {
            if (!is_string($filePath)) {
                continue;
            }
            $applicationId = pathinfo($filePath, PATHINFO_FILENAME);
            if (!preg_match('/^[0-9a-fA-F-]{36}$/', $applicationId)) {
                continue;
            }

            try {
                $application = $client->getApplication($applicationId, $filePath);
                $name = trim((string) ($application['name'] ?? $applicationId));
                $environment = strtoupper(trim((string) ($application['environment'] ?? 'UNKNOWN')));
                $countries = isset($application['countries']) && is_array($application['countries']) ? $application['countries'] : [];
                $applications[] = [
                    'application_id' => $applicationId,
                    'name' => $name,
                    'environment' => $environment,
                    'countries' => $countries,
                    'label' => sprintf('%s (%s, %s)', $name, $applicationId, $environment),
                    'uploaded_at' => date('Y-m-d H:i:s', (int) filemtime($filePath)),
                    'status' => 'ok',
                ];
            } catch (RevolutException $e) {
                $applications[] = [
                    'application_id' => $applicationId,
                    'name' => $applicationId,
                    'environment' => 'UNKNOWN',
                    'countries' => [],
                    'label' => sprintf('%s (invalid key)', $applicationId),
                    'uploaded_at' => date('Y-m-d H:i:s', (int) filemtime($filePath)),
                    'status' => 'invalid',
                    'error' => $e->getMessage(),
                ];
            }
        }

        usort($applications, static function (array $left, array $right): int {
            return strcmp((string) ($right['uploaded_at'] ?? ''), (string) ($left['uploaded_at'] ?? ''));
        });

        return $applications;
    }

    private function buildEnableBankingState(string $applicationId, string $country): string
    {
        $payload = [
            'nonce' => (string) Str::uuid(),
            'application_id' => strtolower(trim($applicationId)),
            'country' => strtoupper(trim($country)),
        ];
        $json = json_encode($payload);
        if (!is_string($json) || '' === $json) {
            return (string) Str::uuid();
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{application_id:string,country:string}|null
     */
    private function parseEnableBankingState(string $state): ?array
    {
        $state = trim($state);
        if ('' === $state) {
            return null;
        }
        $normalized = strtr($state, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if (0 !== $padding) {
            $normalized .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($normalized, true);
        if (!is_string($decoded) || '' === $decoded) {
            return null;
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return null;
        }
        $applicationId = strtolower(trim((string) ($payload['application_id'] ?? '')));
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $applicationId)) {
            return null;
        }
        $country = strtoupper(trim((string) ($payload['country'] ?? 'IE')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $country = 'IE';
        }

        return [
            'application_id' => $applicationId,
            'country' => $country,
        ];
    }
}
