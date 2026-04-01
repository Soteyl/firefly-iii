<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankSyncRun extends Model
{
    use ReturnsIntegerIdTrait;

    public $timestamps = false;

    protected $fillable = [
        'bank_connection_id',
        'trigger',
        'status',
        'started_at',
        'finished_at',
        'stats_json',
        'error_message',
    ];

    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    protected function casts(): array
    {
        return [
            'bank_connection_id' => 'integer',
            'started_at'         => 'datetime',
            'finished_at'        => 'datetime',
            'stats_json'         => 'array',
        ];
    }
}
