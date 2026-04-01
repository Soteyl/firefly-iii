<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\BankConnectionAccount;
use FireflyIII\User;

class MonobankTransactionMapper
{
    public function __construct(private readonly MonobankCurrencyMapper $currencyMapper)
    {
    }

    public function externalId(BankConnectionAccount $mapping, array $statement): ?string
    {
        $statementId = trim((string) ($statement['id'] ?? ''));
        if ('' === $statementId) {
            return null;
        }

        return sprintf('monobank:%s:%s', $mapping->mono_account_id, $statementId);
    }

    public function mapStatement(BankConnectionAccount $mapping, array $statement): ?array
    {
        $amount = (int) ($statement['amount'] ?? 0);
        if (0 === $amount) {
            return null;
        }

        $connection = $mapping->bankConnection;
        $user       = $connection->user;
        if (!$user instanceof User || null === $mapping->firefly_account_id) {
            return null;
        }

        $externalId   = $this->externalId($mapping, $statement);
        $currencyCode = $this->currencyMapper->alphaFromNumeric($mapping->mono_currency_code ?? $this->extractNumericCurrencyCode($statement));
        if (null === $externalId || null === $currencyCode) {
            return null;
        }

        $description    = $this->description($statement, $externalId);
        $counterparty   = $this->counterpartyName($statement, $amount);
        $formatted      = $this->formatMinorAmount(abs($amount), $currencyCode);
        $transaction    = [
            'type'            => $amount < 0 ? TransactionTypeEnum::WITHDRAWAL->value : TransactionTypeEnum::DEPOSIT->value,
            'date'            => $this->timestamp($statement),
            'amount'          => $formatted,
            'currency_code'   => $currencyCode,
            'description'     => $description,
            'external_id'     => $externalId,
            'notes'           => $this->notes($statement),
            'source_id'       => $amount < 0 ? $mapping->firefly_account_id : null,
            'source_name'     => $amount < 0 ? null : $counterparty,
            'destination_id'  => $amount < 0 ? null : $mapping->firefly_account_id,
            'destination_name' => $amount < 0 ? $counterparty : null,
        ];

        return [
            'user'             => $user,
            'user_group'       => $user->userGroup,
            'apply_rules'      => true,
            'fire_webhooks'    => true,
            'batch_submission' => false,
            'transactions'     => [$transaction],
        ];
    }

    private function counterpartyName(array $statement, int $amount): string
    {
        $candidates = [
            $statement['counterName'] ?? null,
            $statement['merchantName'] ?? null,
            $statement['description'] ?? null,
            $statement['comment'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ('' !== $value) {
                return $value;
            }
        }

        return $amount < 0 ? 'Monobank expense' : 'Monobank income';
    }

    private function description(array $statement, string $externalId): string
    {
        $candidates = [
            $statement['description'] ?? null,
            $statement['comment'] ?? null,
            $statement['merchantName'] ?? null,
            $statement['counterName'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ('' !== $value) {
                return mb_substr($value, 0, 255);
            }
        }

        return mb_substr($externalId, 0, 255);
    }

    private function extractNumericCurrencyCode(array $statement): ?int
    {
        if (isset($statement['currencyCode'])) {
            return (int) $statement['currencyCode'];
        }
        if (isset($statement['operationAmount']['currencyCode'])) {
            return (int) $statement['operationAmount']['currencyCode'];
        }

        return null;
    }

    private function formatMinorAmount(int $minorUnits, string $currencyCode): string
    {
        $decimals  = $this->currencyMapper->decimalPlaces($currencyCode);
        $divisor   = 10 ** $decimals;
        $formatted = $decimals > 0 ? number_format($minorUnits / $divisor, $decimals, '.', '') : (string) $minorUnits;

        return $formatted;
    }

    private function notes(array $statement): ?string
    {
        $parts = [];

        $comment = trim((string) ($statement['comment'] ?? ''));
        if ('' !== $comment) {
            $parts[] = sprintf('Comment: %s', $comment);
        }
        if (isset($statement['mcc']) && '' !== trim((string) $statement['mcc'])) {
            $parts[] = sprintf('MCC: %s', trim((string) $statement['mcc']));
        }
        if (isset($statement['balance'])) {
            $parts[] = sprintf('Balance after booking: %s', (string) $statement['balance']);
        }

        if ([] === $parts) {
            return null;
        }

        return implode("\n", $parts);
    }

    private function timestamp(array $statement): string
    {
        $timestamp = (int) ($statement['time'] ?? 0);
        if ($timestamp <= 0) {
            return now()->toDateTimeString();
        }

        return Carbon::createFromTimestamp($timestamp, config('app.timezone'))->toDateTimeString();
    }
}
