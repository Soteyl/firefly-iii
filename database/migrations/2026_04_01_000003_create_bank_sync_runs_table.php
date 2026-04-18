<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('bank_sync_runs');
    }

    public function up(): void
    {
        if (!Schema::hasTable('bank_sync_runs')) {
            Schema::create('bank_sync_runs', static function (Blueprint $table): void {
                $table->increments('id');

                $table->unsignedBigInteger('bank_connection_id');
                $table->string('trigger', 50);
                $table->string('status', 50)->default('pending');
                $table->dateTime('started_at');
                $table->dateTime('finished_at')->nullable();
                $table->json('stats_json')->nullable();
                $table->text('error_message')->nullable();

                $table->foreign('bank_connection_id')->references('id')->on('bank_connections')->onDelete('cascade');
                $table->index(['bank_connection_id', 'started_at'], 'bank_sync_runs_connection_started_index');
                $table->index(['status', 'trigger'], 'bank_sync_runs_status_trigger_index');
            });
        }
    }
};
