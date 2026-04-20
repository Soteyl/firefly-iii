<?php

declare(strict_types=1);

namespace FireflyIII\Jobs;

use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankSyncRun;
use FireflyIII\Services\Monobank\BankSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMonobankConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        private readonly int $connectionId,
        private readonly int $runId,
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(sprintf('bank-sync:%d', $this->connectionId)))->releaseAfter(10)->expireAfter(3600),
        ];
    }

    public function handle(BankSyncService $bankSyncService): void
    {
        /** @var null|BankConnection $connection */
        $connection = BankConnection::query()->find($this->connectionId);
        /** @var null|BankSyncRun $run */
        $run = BankSyncRun::query()->find($this->runId);
        if (!$connection instanceof BankConnection || !$run instanceof BankSyncRun) {
            return;
        }
        if ((int) $run->bank_connection_id !== (int) $connection->id) {
            return;
        }
        if (!in_array((string) $run->status, ['queued', 'running'], true)) {
            return;
        }

        try {
            $bankSyncService->syncConnection($connection, (string) $run->trigger, $run);
        } catch (Throwable $e) {
            Log::warning(sprintf('Queued Monobank sync job failed for bank connection #%d: %s', $connection->id, $e->getMessage()));

            $run->status = 'failed';
            $run->finished_at = now();
            $run->error_message = $e->getMessage();
            $run->save();

            $connection->status = 'error';
            $connection->last_failed_sync_at = now();
            $connection->last_error_message = $e->getMessage();
            $connection->save();
        }
    }
}
