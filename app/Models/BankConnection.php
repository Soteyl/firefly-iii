<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use FireflyIII\Support\Models\ReturnsIntegerUserIdTrait;
use FireflyIII\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class BankConnection extends Model
{
    use ReturnsIntegerIdTrait;
    use ReturnsIntegerUserIdTrait;

    protected $fillable = [
        'user_id',
        'user_group_id',
        'provider',
        'status',
        'access_token',
        'webhook_path_secret',
        'webhook_enabled',
        'last_webhook_at',
        'last_successful_sync_at',
        'last_failed_sync_at',
        'last_error_message',
    ];

    public function bankConnectionAccounts(): HasMany
    {
        return $this->hasMany(BankConnectionAccount::class);
    }

    public function bankSyncEvents(): HasMany
    {
        return $this->hasMany(BankSyncEvent::class);
    }

    public function bankSyncRuns(): HasMany
    {
        return $this->hasMany(BankSyncRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class);
    }

    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: static function (?string $value): ?string {
                if (null === $value || '' === $value) {
                    return null;
                }
                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException) {
                    return $value;
                }
            },
            set: static function (?string $value): array {
                if (null === $value || '' === $value) {
                    return ['access_token' => null];
                }

                return ['access_token' => Crypt::encryptString($value)];
            },
        );
    }

    protected function casts(): array
    {
        return [
            'created_at'              => 'datetime',
            'updated_at'              => 'datetime',
            'user_id'                 => 'integer',
            'user_group_id'           => 'integer',
            'webhook_enabled'         => 'boolean',
            'last_webhook_at'         => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'last_failed_sync_at'     => 'datetime',
        ];
    }
}
