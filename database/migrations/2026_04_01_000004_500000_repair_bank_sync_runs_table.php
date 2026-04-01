<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        // Existing deployments may already depend on the repaired table definition.
    }

    public function up(): void
    {
        if (!Schema::hasTable('bank_sync_runs')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $connectionIdColumn = DB::selectOne("SHOW COLUMNS FROM `bank_sync_runs` LIKE 'bank_connection_id'");
        if (null === $connectionIdColumn) {
            return;
        }

        $needsRepair = 'bigint(20) unsigned' !== $connectionIdColumn->Type
            || 0 === count(DB::select("SHOW INDEX FROM `bank_sync_runs` WHERE Key_name = 'bank_sync_runs_connection_started_index'"))
            || 0 === count(DB::select("SHOW INDEX FROM `bank_sync_runs` WHERE Key_name = 'bank_sync_runs_status_trigger_index'"))
            || 0 === count(DB::select("
                SELECT 1
                FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE()
                  AND table_name = 'bank_sync_runs'
                  AND constraint_name = 'bank_sync_runs_bank_connection_id_foreign'
                  AND constraint_type = 'FOREIGN KEY'
            "))
        ;

        if (!$needsRepair) {
            return;
        }

        if (DB::table('bank_sync_runs')->count() > 0) {
            throw new RuntimeException('Cannot automatically repair bank_sync_runs with existing rows.');
        }

        Schema::drop('bank_sync_runs');

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
};
