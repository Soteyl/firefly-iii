<?php

declare(strict_types=1);

namespace FireflyIII\Services\Revolut;

use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use Illuminate\Support\Collection;

class RevolutAccountMapper
{
    public function syncConnectionAccounts(BankConnection $connection, array $wallet): Collection
    {
        $mapped = collect();

        $walletId = $this->normalizeString($wallet['id'] ?? null);
        $pockets = $wallet['pockets'] ?? [];
        if (!is_array($pockets)) {
            return $mapped;
        }

        foreach ($pockets as $index => $pocket) {
            if (!is_array($pocket)) {
                continue;
            }
            $currency = strtoupper($this->normalizeString($pocket['currency'] ?? null) ?? '');
            if ('' === $currency) {
                continue;
            }

            $accountType = strtoupper($this->normalizeString($pocket['type'] ?? null) ?? 'CURRENT');
            $vaultName = $this->normalizeString($pocket['name'] ?? null);
            $isVault = 'SAVINGS' === $accountType;
            $pocketId = $this->normalizeString($pocket['id'] ?? null) ?? sprintf('pocket-%d-%s-%s', $index, strtolower($currency), strtolower($accountType));
            $accountId = trim(sprintf('%s:%s', $walletId ?? 'wallet', $pocketId));

            /** @var BankConnectionAccount $model */
            $model = BankConnectionAccount::firstOrNew([
                'bank_connection_id' => $connection->id,
                'revolut_account_id' => $accountId,
            ]);

            $model->revolut_wallet_id = $walletId;
            $model->revolut_account_type = $accountType;
            $model->revolut_currency_code = $currency;
            $model->revolut_account_name = $this->accountName($currency, $accountType, $vaultName);
            $model->revolut_is_vault = $isVault;
            $model->mono_account_id = $this->syntheticMonoAccountId($accountId);
            $model->save();

            $mapped->push($model);
        }

        return $mapped;
    }

    public function syncEnableBankingAccounts(BankConnection $connection, array $accounts): Collection
    {
        $mapped = collect();

        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            $uid = trim((string) ($account['uid'] ?? ''));
            if ('' === $uid) {
                continue;
            }
            $currency = strtoupper($this->normalizeString($account['currency'] ?? null) ?? '');
            if ('' === $currency) {
                $currency = 'EUR';
            }
            $usage = strtoupper($this->normalizeString($account['usage'] ?? null) ?? 'CURRENT');
            $name = $this->normalizeString($account['name'] ?? null);
            $revolutAccountId = sprintf('enable:%s', $uid);

            /** @var BankConnectionAccount $model */
            $model = BankConnectionAccount::firstOrNew([
                'bank_connection_id' => $connection->id,
                'revolut_account_id' => $revolutAccountId,
            ]);

            $model->revolut_wallet_id = 'enable-banking';
            $model->revolut_account_type = $usage;
            $model->revolut_currency_code = $currency;
            $model->revolut_account_name = $name ?? sprintf('%s %s', $currency, $usage);
            $model->revolut_is_vault = false;
            $model->mono_account_id = $this->syntheticMonoAccountId($revolutAccountId);
            $model->save();

            $mapped->push($model);
        }

        return $mapped;
    }

    private function accountName(string $currency, string $accountType, ?string $vaultName): string
    {
        if ('SAVINGS' === $accountType && null !== $vaultName) {
            return sprintf('%s %s (%s)', $currency, $accountType, $vaultName);
        }

        return sprintf('%s %s', $currency, $accountType);
    }

    private function normalizeString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }

    private function syntheticMonoAccountId(string $revolutAccountId): string
    {
        return sprintf('revolut:%s', sha1($revolutAccountId));
    }
}
