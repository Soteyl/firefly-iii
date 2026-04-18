<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Services\Monobank\BankSyncService;
use FireflyIII\User;
use Illuminate\Contracts\View\Factory;
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
        $user        = auth()->user();
        $accountRepository->setUser($user);
        $connections = $user->bankConnections()->with(['bankConnectionAccounts.fireflyAccount'])->orderBy('id')->get();
        $assetAccounts = $accountRepository->getActiveAccountsByType([
            AccountTypeEnum::ASSET->value,
            AccountTypeEnum::DEFAULT->value,
        ]);

        return view('preferences.bank-connections', [
            'connections'   => $connections,
            'assetAccounts' => $assetAccounts,
        ]);
    }

    public function store(Request $request, BankSyncService $bankSyncService): RedirectResponse
    {
        $data = $request->validate([
            'access_token' => 'required|string|min:10|max:255',
        ]);

        /** @var User $user */
        $user       = auth()->user();
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

    public function validateToken(int $id, BankSyncService $bankSyncService): RedirectResponse
    {
        $connection = $this->findConnection($id);

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

    public function refreshAccounts(int $id, BankSyncService $bankSyncService): RedirectResponse
    {
        $connection = $this->findConnection($id);

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

    public function updateMapping(Request $request, int $id, int $accountId): RedirectResponse
    {
        $connection = $this->findConnection($id);
        /** @var BankConnectionAccount $mapping */
        $mapping = $connection->bankConnectionAccounts()->findOrFail($accountId);

        $data = $request->validate([
            'firefly_account_id' => 'nullable|integer|exists:accounts,id',
            'enabled'            => 'nullable|boolean',
        ]);

        /** @var User $user */
        $user = auth()->user();
        $fireflyAccountId = null;
        if (array_key_exists('firefly_account_id', $data) && null !== $data['firefly_account_id']) {
            $fireflyAccountId = $user->accounts()->findOrFail((int) $data['firefly_account_id'])->id;
        }

        $mapping->firefly_account_id = $fireflyAccountId;
        $mapping->enabled = (bool) ($data['enabled'] ?? false);
        if (null === $mapping->sync_from_ts) {
            $mapping->sync_from_ts = now()->subDays(31)->timestamp;
        }
        $mapping->save();

        return redirect()->route('preferences.bank-connections.index')->with('success', (string) trans('firefly.bank_account_mapping_saved'));
    }

    /**
     * @throws JsonException
     */
    public function sync(int $id, BankSyncService $bankSyncService): RedirectResponse
    {
        $connection = $this->findConnection($id);

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

    private function findConnection(int $id): BankConnection
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->bankConnections()->with(['bankConnectionAccounts.fireflyAccount'])->findOrFail($id);
    }
}
