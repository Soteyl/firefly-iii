<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Exceptions\MonobankException;
use FireflyIII\Models\BankConnection;
use FireflyIII\Models\BankSyncEvent;
use FireflyIII\Services\Monobank\BankSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Safe\Exceptions\JsonException;

class MonobankWebhookController extends Controller
{
    public function handle(string $secret, Request $request, BankSyncService $bankSyncService): Response
    {
        /** @var null|BankConnection $connection */
        $connection = BankConnection::where('provider', 'monobank')
            ->where('webhook_path_secret', $secret)
            ->first()
        ;

        if (!$connection instanceof BankConnection) {
            return response('not found', 404);
        }

        $connection->last_webhook_at = now();
        $connection->save();

        $payload = $request->json()->all();
        if ([] === $payload) {
            $payload = $request->all();
        }

        $event = new BankSyncEvent([
            'bank_connection_id' => $connection->id,
            'event_type'         => (string) ($payload['type'] ?? strtolower($request->method()).'_probe'),
            'payload_json'       => $payload,
        ]);
        $event->save();

        if ($request->isMethod('post')) {
            try {
                $bankSyncService->syncConnection($connection, 'webhook');
            } catch (FireflyException|JsonException|MonobankException $e) {
                Log::warning(sprintf('Monobank webhook sync failed for bank connection #%d: %s', $connection->id, $e->getMessage()));
            }
        }

        $event->processed_at = now();
        $event->save();

        return response('ok', 200);
    }
}
