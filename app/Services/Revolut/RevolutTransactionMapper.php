<?php

declare(strict_types=1);

namespace FireflyIII\Services\Revolut;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\User;

class RevolutTransactionMapper
{
    public function __construct(private readonly RevolutCurrencyMapper $currencyMapper)
    {
    }

    public function externalId(BankConnectionAccount $mapping, array $transaction): ?string
    {
        $legId = trim((string) ($transaction['legId'] ?? $transaction['id'] ?? ''));
        if ('' === $legId) {
            return null;
        }

        return sprintf('revolut:%s:%s', $mapping->revolut_account_id, $legId);
    }

    public function externalIdForEnable(BankConnectionAccount $mapping, array $transaction): string
    {
        $entryReference = trim((string) ($transaction['entry_reference'] ?? ''));
        if ('' !== $entryReference) {
            return sprintf('revolut-eb:%s:%s', $mapping->revolut_account_id, $entryReference);
        }

        $transactionId = trim((string) ($transaction['transaction_id'] ?? ''));
        if ('' !== $transactionId) {
            return sprintf('revolut-eb:%s:%s', $mapping->revolut_account_id, $transactionId);
        }

        $date = trim((string) ($transaction['booking_date'] ?? $transaction['value_date'] ?? $transaction['transaction_date'] ?? ''));
        $amount = trim((string) ($transaction['transaction_amount']['amount'] ?? '0'));
        $currency = trim((string) ($transaction['transaction_amount']['currency'] ?? $mapping->revolut_currency_code ?? ''));
        $name = trim((string) ($transaction['creditor']['name'] ?? $transaction['debtor']['name'] ?? 'unknown'));
        $fingerprint = hash('sha256', implode('|', [$mapping->revolut_account_id, $date, $amount, $currency, $name]));

        return sprintf('revolut-eb:%s:%s', $mapping->revolut_account_id, $fingerprint);
    }

    public function mapTransaction(BankConnectionAccount $mapping, array $transaction): ?array
    {
        $amount = (int) ($transaction['amount'] ?? 0);
        if (0 === $amount) {
            return null;
        }

        $connection = $mapping->bankConnection;
        $user = $connection->user;
        if (!$user instanceof User || null === $mapping->firefly_account_id) {
            return null;
        }

        $externalId = $this->externalId($mapping, $transaction);
        $currencyCode = strtoupper((string) ($mapping->revolut_currency_code ?? ($transaction['currency'] ?? '')));
        if (null === $externalId || '' === $currencyCode) {
            return null;
        }

        $description = $this->description($transaction, $externalId);
        $counterparty = $this->counterpartyName($transaction, $amount);
        $formatted = $this->formatMinorAmount(abs($amount), $currencyCode);
        $fireflyType = $amount < 0 ? TransactionTypeEnum::WITHDRAWAL->value : TransactionTypeEnum::DEPOSIT->value;

        $mapped = [
            'type'             => $fireflyType,
            'date'             => $this->timestamp($transaction),
            'amount'           => $formatted,
            'currency_code'    => $currencyCode,
            'description'      => $description,
            'external_id'      => $externalId,
            'notes'            => $this->notes($transaction),
            'source_id'        => $amount < 0 ? $mapping->firefly_account_id : null,
            'source_name'      => $amount < 0 ? null : $counterparty,
            'destination_id'   => $amount < 0 ? null : $mapping->firefly_account_id,
            'destination_name' => $amount < 0 ? $counterparty : null,
        ];

        return [
            'user'             => $user,
            'user_group'       => $user->userGroup,
            'apply_rules'      => true,
            'fire_webhooks'    => true,
            'batch_submission' => false,
            'transactions'     => [$mapped],
        ];
    }

    public function mapEnableBankingTransaction(BankConnectionAccount $mapping, array $transaction): ?array
    {
        $connection = $mapping->bankConnection;
        $user = $connection->user;
        if (!$user instanceof User || null === $mapping->firefly_account_id) {
            return null;
        }

        $amountRaw = trim((string) ($transaction['transaction_amount']['amount'] ?? ''));
        if ('' === $amountRaw || !is_numeric($amountRaw)) {
            return null;
        }
        $amountPositive = ltrim((string) abs((float) $amountRaw), '+');
        if ('0' === $amountPositive || '0.0' === $amountPositive || '0.00' === $amountPositive) {
            return null;
        }

        $indicator = strtoupper(trim((string) ($transaction['credit_debit_indicator'] ?? '')));
        $isDebit = 'DBIT' === $indicator;
        $fireflyType = $isDebit ? TransactionTypeEnum::WITHDRAWAL->value : TransactionTypeEnum::DEPOSIT->value;
        $currencyCode = strtoupper(trim((string) ($transaction['transaction_amount']['currency'] ?? $mapping->revolut_currency_code ?? 'EUR')));

        $externalId = $this->externalIdForEnable($mapping, $transaction);
        $description = $this->enableDescription($transaction, $externalId);
        $counterparty = $this->enableCounterparty($transaction, $isDebit);
        $notes = $this->enableNotes($transaction);

        $mapped = [
            'type'             => $fireflyType,
            'date'             => $this->enableTimestamp($transaction),
            'amount'           => $amountPositive,
            'currency_code'    => $currencyCode,
            'description'      => $description,
            'external_id'      => $externalId,
            'notes'            => $notes,
            'source_id'        => $isDebit ? $mapping->firefly_account_id : null,
            'source_name'      => $isDebit ? null : $counterparty,
            'destination_id'   => $isDebit ? null : $mapping->firefly_account_id,
            'destination_name' => $isDebit ? $counterparty : null,
        ];

        return [
            'user'             => $user,
            'user_group'       => $user->userGroup,
            'apply_rules'      => true,
            'fire_webhooks'    => true,
            'batch_submission' => false,
            'transactions'     => [$mapped],
        ];
    }

    public function matchesMapping(BankConnectionAccount $mapping, array $transaction): bool
    {
        $transactionAccountId = trim((string) ($transaction['account']['id'] ?? ''));
        if ('' !== $transactionAccountId) {
            $mappingPocketId = $this->mappingPocketId((string) $mapping->revolut_account_id);
            if (null !== $mappingPocketId && $transactionAccountId !== $mappingPocketId) {
                return false;
            }
        }

        $currency = strtoupper(trim((string) ($transaction['currency'] ?? '')));
        if ('' !== trim((string) $mapping->revolut_currency_code) && $currency !== strtoupper((string) $mapping->revolut_currency_code)) {
            return false;
        }

        $isVaultTransaction = null !== ($transaction['vault'] ?? null);

        return (bool) $mapping->revolut_is_vault === $isVaultTransaction;
    }

    private function mappingPocketId(string $revolutAccountId): ?string
    {
        $value = trim($revolutAccountId);
        if ('' === $value) {
            return null;
        }

        $parts = explode(':', $value);
        $last = trim((string) end($parts));

        return '' === $last ? null : $last;
    }

    private function counterpartyName(array $transaction, int $amount): string
    {
        $merchant = $transaction['merchant']['name'] ?? null;
        $candidates = [
            $merchant,
            $transaction['description'] ?? null,
            $transaction['category'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ('' !== $value) {
                return $value;
            }
        }

        return $amount < 0 ? 'Revolut expense' : 'Revolut income';
    }

    private function description(array $transaction, string $externalId): string
    {
        $merchant = $transaction['merchant']['name'] ?? null;
        $candidates = [
            $transaction['category'] ?? null,
            $merchant,
            $transaction['description'] ?? null,
            $transaction['type'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ('' !== $value) {
                return mb_substr($value, 0, 255);
            }
        }

        return mb_substr($externalId, 0, 255);
    }

    private function formatMinorAmount(int $minorUnits, string $currencyCode): string
    {
        $decimals = $this->currencyMapper->decimalPlaces($currencyCode);
        $divisor = 10 ** $decimals;

        return $decimals > 0 ? number_format($minorUnits / $divisor, $decimals, '.', '') : (string) $minorUnits;
    }

    private function notes(array $transaction): ?string
    {
        $parts = [];

        $type = trim((string) ($transaction['type'] ?? ''));
        if ('' !== $type) {
            $parts[] = sprintf('Type: %s', $type);
        }

        $state = trim((string) ($transaction['state'] ?? ''));
        if ('' !== $state) {
            $parts[] = sprintf('State: %s', $state);
        }

        $id = trim((string) ($transaction['id'] ?? ''));
        if ('' !== $id) {
            $parts[] = sprintf('Transaction ID: %s', $id);
        }

        if ([] === $parts) {
            return null;
        }

        return implode("\n", $parts);
    }

    private function timestamp(array $transaction): Carbon
    {
        $timestampMs = (int) ($transaction['createdDate'] ?? $transaction['startedDate'] ?? 0);
        if ($timestampMs <= 0) {
            return now();
        }

        return Carbon::createFromTimestamp(intdiv($timestampMs, 1000), config('app.timezone'));
    }

    private function enableTimestamp(array $transaction): Carbon
    {
        $dateString = trim((string) ($transaction['booking_date'] ?? $transaction['value_date'] ?? $transaction['transaction_date'] ?? ''));
        if ('' === $dateString) {
            return now();
        }
        try {
            return Carbon::parse($dateString, config('app.timezone'))->setTime(12, 0);
        } catch (\Exception) {
            return now();
        }
    }

    private function enableDescription(array $transaction, string $fallback): string
    {
        $remittance = $transaction['remittance_information'] ?? [];
        if (is_array($remittance)) {
            foreach ($remittance as $line) {
                $value = trim((string) $line);
                if ('' !== $value) {
                    return mb_substr($value, 0, 255);
                }
            }
        }
        $note = trim((string) ($transaction['note'] ?? ''));
        if ('' !== $note) {
            return mb_substr($note, 0, 255);
        }

        return mb_substr($fallback, 0, 255);
    }

    private function enableCounterparty(array $transaction, bool $isDebit): string
    {
        $preferred = $isDebit ? ($transaction['creditor']['name'] ?? null) : ($transaction['debtor']['name'] ?? null);
        $fallback = $isDebit ? ($transaction['debtor']['name'] ?? null) : ($transaction['creditor']['name'] ?? null);
        $value = trim((string) ($preferred ?? $fallback ?? ''));
        if ('' !== $value) {
            return $value;
        }

        return $isDebit ? 'Revolut expense' : 'Revolut income';
    }

    private function enableNotes(array $transaction): ?string
    {
        $parts = [];
        $status = trim((string) ($transaction['status'] ?? ''));
        if ('' !== $status) {
            $parts[] = sprintf('Status: %s', $status);
        }
        $entryReference = trim((string) ($transaction['entry_reference'] ?? ''));
        if ('' !== $entryReference) {
            $parts[] = sprintf('Entry reference: %s', $entryReference);
        }
        $transactionId = trim((string) ($transaction['transaction_id'] ?? ''));
        if ('' !== $transactionId) {
            $parts[] = sprintf('Transaction ID: %s', $transactionId);
        }

        return [] === $parts ? null : implode("\n", $parts);
    }
}
