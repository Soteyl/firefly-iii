<?php

declare(strict_types=1);

namespace FireflyIII\Support\Cronjobs;

use Carbon\Carbon;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Support\Facades\FireflyConfig;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Services\Monobank\BankSyncService;
use Illuminate\Support\Facades\Log;
use Safe\Exceptions\JsonException;

class MonobankPollCronjob extends AbstractCronjob
{
    public int $timeBetweenRuns = 300;

    /**
     * @throws FireflyException
     */
    public function fire(): void
    {
        Log::debug(sprintf('Now in %s', __METHOD__));

        $config        = FireflyConfig::get('last_monobank_poll_job', 0);
        $lastTime      = (int) $config->data;
        $diff          = now(config('app.timezone'))->getTimestamp() - $lastTime;
        $diffForHumans = now(config('app.timezone'))->diffForHumans(Carbon::createFromTimestamp($lastTime), null, true);

        if ($lastTime > 0 && $diff <= $this->timeBetweenRuns && false === $this->force) {
            $this->message = sprintf('It has been %s since the Monobank polling cron-job last fired. It will not fire now.', $diffForHumans);

            return;
        }

        $this->pollConnections();
        Preferences::mark();
    }

    /**
     * @throws FireflyException
     */
    private function pollConnections(): void
    {
        /** @var BankSyncService $service */
        $service = app(BankSyncService::class);

        $processed = 0;
        $failed    = 0;
        $set       = BankConnection::query()
            ->where('provider', 'monobank')
            ->where('status', '!=', 'disabled')
            ->orderBy('id')
            ->get()
        ;

        /** @var BankConnection $connection */
        foreach ($set as $connection) {
            try {
                $service->syncConnection($connection, 'poll');
                ++$processed;
            } catch (FireflyException|JsonException|MonobankException $e) {
                ++$failed;
                Log::warning(sprintf('Monobank polling failed for bank connection #%d: %s', $connection->id, $e->getMessage()));
            }
        }

        $this->jobFired     = true;
        $this->jobErrored   = $failed > 0;
        $this->jobSucceeded = 0 === $failed;
        $this->message      = sprintf('Polled %d Monobank connection(s), %d failure(s).', $processed, $failed);

        FireflyConfig::set('last_monobank_poll_job', (int) $this->date->format('U'));
    }
}
