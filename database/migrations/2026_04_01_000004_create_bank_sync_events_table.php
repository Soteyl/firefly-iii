<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('bank_sync_events');
    }

    public function up(): void
    {
        if (!Schema::hasTable('bank_sync_events')) {
            Schema::create('bank_sync_events', static function (Blueprint $table): void {
                $table->id();

                $table->bigInteger('bank_connection_id', false, true)->nullable();
                $table->string('event_type', 50);
                $table->json('payload_json')->nullable();
                $table->dateTime('processed_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('bank_connection_id')->references('id')->on('bank_connections')->onDelete('cascade');
                $table->index(['bank_connection_id', 'created_at'], 'bank_sync_events_connection_created_index');
                $table->index(['event_type', 'processed_at'], 'bank_sync_events_type_processed_index');
            });
        }
    }
};
