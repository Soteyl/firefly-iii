<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankConnectionAccount extends Model
{
    use ReturnsIntegerIdTrait;

    protected $fillable = [
        'bank_connection_id',
        'mono_account_id',
        'mono_account_type',
        'mono_currency_code',
        'mono_masked_pan',
        'mono_iban',
        'revolut_account_id',
        'revolut_wallet_id',
        'revolut_account_type',
        'revolut_currency_code',
        'revolut_account_name',
        'revolut_is_vault',
        'firefly_account_id',
        'enabled',
        'sync_from_ts',
        'last_synced_statement_ts',
        'last_seen_statement_id',
    ];

    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    public function fireflyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'firefly_account_id');
    }

    protected function casts(): array
    {
        return [
            'created_at'               => 'datetime',
            'updated_at'               => 'datetime',
            'bank_connection_id'       => 'integer',
            'mono_currency_code'       => 'integer',
            'revolut_is_vault'         => 'boolean',
            'firefly_account_id'       => 'integer',
            'enabled'                  => 'boolean',
            'sync_from_ts'             => 'integer',
            'last_synced_statement_ts' => 'integer',
        ];
    }
}
