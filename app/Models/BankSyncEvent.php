<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankSyncEvent extends Model
{
    use ReturnsIntegerIdTrait;

    public const UPDATED_AT = null;

    public $timestamps = true;

    protected $fillable = [
        'bank_connection_id',
        'event_type',
        'payload_json',
        'processed_at',
    ];

    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    protected function casts(): array
    {
        return [
            'created_at'         => 'datetime',
            'processed_at'       => 'datetime',
            'bank_connection_id' => 'integer',
            'payload_json'       => 'array',
        ];
    }
}
