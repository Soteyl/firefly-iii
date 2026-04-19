<?php

declare(strict_types=1);

namespace FireflyIII\Services\Banking;

use FireflyIII\Models\BankConnection;
use FireflyIII\Models\Category;

class ImportCategoryRuleService
{
    /** @var array<int, array<int, string>> */
    private array $categoryCache = [];

    public function apply(BankConnection $connection, array $rawPayload, array $mappedTransaction): array
    {
        $type = (string) ($mappedTransaction['type'] ?? '');
        if ('Withdrawal' !== $type && 'Deposit' !== $type) {
            return $mappedTransaction;
        }

        $categoryName = $this->categoryFromOverride($connection, $rawPayload, $mappedTransaction);
        if (null === $categoryName) {
            $categoryName = $this->categoryFromMcc($connection, $rawPayload);
        }
        if (null === $categoryName || '' === trim($categoryName)) {
            return $mappedTransaction;
        }
        $mappedTransaction['category_name'] = $categoryName;

        return $mappedTransaction;
    }

    private function categoryFromMcc(BankConnection $connection, array $rawPayload): ?string
    {
        $mcc = $this->extractMcc($rawPayload);
        if (null === $mcc) {
            return null;
        }

        $config = $this->normalizedRules($connection);
        /** @var array<int, array{mcc: string, category_id: int, enabled: bool}> $rules */
        $rules = $config['mcc'];
        foreach ($rules as $rule) {
            if (!$rule['enabled']) {
                continue;
            }
            if ($rule['mcc'] !== $mcc) {
                continue;
            }

            return $this->categoryNameById($connection, $rule['category_id']);
        }

        return null;
    }

    private function categoryFromOverride(BankConnection $connection, array $rawPayload, array $mappedTransaction): ?string
    {
        $config = $this->normalizedRules($connection);
        /** @var array<int, array{external_id: string, description_contains: string, mcc: string, category_id: int, enabled: bool}> $rules */
        $rules  = $config['overrides'];

        $externalId = trim((string) ($mappedTransaction['external_id'] ?? ''));
        $description = mb_strtolower(trim((string) ($mappedTransaction['description'] ?? '')));
        $mcc = $this->extractMcc($rawPayload);

        foreach ($rules as $rule) {
            if (!$rule['enabled']) {
                continue;
            }
            if ('' !== $rule['external_id'] && $rule['external_id'] !== $externalId) {
                continue;
            }
            if ('' !== $rule['description_contains']) {
                if ('' === $description || !str_contains($description, mb_strtolower($rule['description_contains']))) {
                    continue;
                }
            }
            if ('' !== $rule['mcc']) {
                if (null === $mcc || $rule['mcc'] !== $mcc) {
                    continue;
                }
            }

            return $this->categoryNameById($connection, $rule['category_id']);
        }

        return null;
    }

    private function categoryNameById(BankConnection $connection, int $categoryId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }
        $userId = (int) $connection->user_id;
        if (!array_key_exists($userId, $this->categoryCache)) {
            $this->categoryCache[$userId] = Category::query()
                ->where('user_id', $userId)
                ->pluck('name', 'id')
                ->map(static fn ($name): string => (string) $name)
                ->all()
            ;
        }

        return $this->categoryCache[$userId][$categoryId] ?? null;
    }

    /**
     * @return array{mcc: array<int, array{mcc: string, category_id: int, enabled: bool}>, overrides: array<int, array{external_id: string, description_contains: string, mcc: string, category_id: int, enabled: bool}>}
     */
    private function normalizedRules(BankConnection $connection): array
    {
        $providerConfig = is_array($connection->provider_config) ? $connection->provider_config : [];
        $rules = is_array($providerConfig['category_rules'] ?? null) ? $providerConfig['category_rules'] : [];
        $mccRules = is_array($rules['mcc'] ?? null) ? $rules['mcc'] : [];
        $overrideRules = is_array($rules['overrides'] ?? null) ? $rules['overrides'] : [];

        $cleanMcc = [];
        foreach ($mccRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $mcc = preg_replace('/\D+/', '', trim((string) ($rule['mcc'] ?? '')));
            $categoryId = (int) ($rule['category_id'] ?? 0);
            if ('' === $mcc || $categoryId <= 0) {
                continue;
            }
            $cleanMcc[] = [
                'mcc'         => $mcc,
                'category_id' => $categoryId,
                'enabled'     => (bool) ($rule['enabled'] ?? true),
            ];
        }

        $cleanOverrides = [];
        foreach ($overrideRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $externalId = trim((string) ($rule['external_id'] ?? ''));
            $descriptionContains = trim((string) ($rule['description_contains'] ?? ''));
            $mcc = preg_replace('/\D+/', '', trim((string) ($rule['mcc'] ?? '')));
            $categoryId = (int) ($rule['category_id'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            if ('' === $externalId && '' === $descriptionContains && '' === $mcc) {
                continue;
            }
            $cleanOverrides[] = [
                'external_id'          => $externalId,
                'description_contains' => $descriptionContains,
                'mcc'                  => $mcc,
                'category_id'          => $categoryId,
                'enabled'              => (bool) ($rule['enabled'] ?? true),
            ];
        }

        return ['mcc' => $cleanMcc, 'overrides' => $cleanOverrides];
    }

    private function extractMcc(array $rawPayload): ?string
    {
        $candidates = [
            $rawPayload['mcc'] ?? null,
            $rawPayload['originalMcc'] ?? null,
            $rawPayload['merchant']['categoryCode'] ?? null,
            $rawPayload['merchant']['mcc'] ?? null,
            $rawPayload['merchant_category_code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (null === $candidate) {
                continue;
            }
            $normalized = preg_replace('/\D+/', '', trim((string) $candidate));
            if ('' !== $normalized) {
                return $normalized;
            }
        }

        return null;
    }
}

