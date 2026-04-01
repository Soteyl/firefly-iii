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
        if (!Schema::hasTable('bank_connections')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $idColumn = DB::selectOne("SHOW COLUMNS FROM `bank_connections` LIKE 'id'");
        $userIdColumn = DB::selectOne("SHOW COLUMNS FROM `bank_connections` LIKE 'user_id'");

        if (null === $idColumn || null === $userIdColumn) {
            return;
        }

        if ('int(10) unsigned' === $idColumn->Type && 'int(10) unsigned' === $userIdColumn->Type) {
            return;
        }

        $rowCount = DB::table('bank_connections')->count();
        if ($rowCount > 0) {
            throw new RuntimeException('Cannot automatically repair bank_connections with existing rows.');
        }

        Schema::drop('bank_connections');

        Schema::create('bank_connections', static function (Blueprint $table): void {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('user_group_id');
            $table->string('provider', 50);
            $table->string('status', 50)->default('pending');
            $table->text('access_token');
            $table->string('webhook_path_secret', 64)->unique();
            $table->boolean('webhook_enabled')->default(false);
            $table->dateTime('last_webhook_at')->nullable();
            $table->dateTime('last_successful_sync_at')->nullable();
            $table->dateTime('last_failed_sync_at')->nullable();
            $table->text('last_error_message')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('user_group_id')->references('id')->on('user_groups')->onDelete('cascade');
            $table->unique(['user_id', 'provider'], 'bank_connections_user_provider_unique');
            $table->index(['user_group_id', 'provider'], 'bank_connections_group_provider_index');
        });
    }
};
