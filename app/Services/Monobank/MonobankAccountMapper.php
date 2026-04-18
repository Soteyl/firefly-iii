<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankConnectionAccount;
use Illuminate\Support\Collection;

class MonobankAccountMapper
{
    public function syncConnectionAccounts(BankConnection $connection, array $clientInfo): Collection
    {
        $mapped = collect();

        foreach ($this->extractAccounts($clientInfo) as $account) {
            $monoAccountId = (string) ($account['id'] ?? '');
            if ('' === $monoAccountId) {
                continue;
            }

            /** @var BankConnectionAccount $model */
            $model = BankConnectionAccount::firstOrNew([
                'bank_connection_id' => $connection->id,
                'mono_account_id'    => $monoAccountId,
            ]);

            $model->mono_account_type = $this->detectAccountType($account);
            $model->mono_currency_code = isset($account['currencyCode']) ? (int) $account['currencyCode'] : null;
            $model->mono_masked_pan = $this->normalizeMaskedPan($account['maskedPan'] ?? null);
            $model->mono_iban = $this->normalizeNullableString($account['iban'] ?? null);
            $model->save();

            $mapped->push($model);
        }

        return $mapped;
    }

    private function detectAccountType(array $account): ?string
    {
        $type = $this->normalizeNullableString($account['type'] ?? null);
        if (null !== $type) {
            return $type;
        }
        if (array_key_exists('title', $account)) {
            return 'jar';
        }

        return null;
    }

    private function extractAccounts(array $clientInfo): array
    {
        $accounts = [];

        if (isset($clientInfo['accounts']) && is_array($clientInfo['accounts'])) {
            $accounts = array_merge($accounts, $clientInfo['accounts']);
        }
        if (isset($clientInfo['jars']) && is_array($clientInfo['jars'])) {
            $accounts = array_merge($accounts, $clientInfo['jars']);
        }
        if (isset($clientInfo['managedClients']) && is_array($clientInfo['managedClients'])) {
            foreach ($clientInfo['managedClients'] as $managedClient) {
                if (isset($managedClient['accounts']) && is_array($managedClient['accounts'])) {
                    $accounts = array_merge($accounts, $managedClient['accounts']);
                }
            }
        }

        return $accounts;
    }

    private function normalizeMaskedPan(mixed $value): ?string
    {
        if (is_array($value)) {
            $filtered = array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $value
            )));
            if ([] === $filtered) {
                return null;
            }

            return implode(', ', $filtered);
        }

        return $this->normalizeNullableString($value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }
}
