<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        // Existing deployments may already depend on the repaired table definitions.
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

        $userIdColumn = DB::selectOne("SHOW COLUMNS FROM `bank_connections` LIKE 'user_id'");
        $accountConnectionColumn = Schema::hasTable('bank_connection_accounts')
            ? DB::selectOne("SHOW COLUMNS FROM `bank_connection_accounts` LIKE 'bank_connection_id'")
            : null;

        $needsConnectionRepair = null !== $userIdColumn && 'int(10) unsigned' !== $userIdColumn->Type;
        $needsAccountRepair = null !== $accountConnectionColumn && 'bigint(20) unsigned' !== $accountConnectionColumn->Type;

        if (!$needsConnectionRepair && !$needsAccountRepair) {
            return;
        }

        if (DB::table('bank_connections')->count() > 0) {
            throw new RuntimeException('Cannot automatically repair bank_connections with existing rows.');
        }

        if (Schema::hasTable('bank_connection_accounts') && DB::table('bank_connection_accounts')->count() > 0) {
            throw new RuntimeException('Cannot automatically repair bank_connection_accounts with existing rows.');
        }

        Schema::dropIfExists('bank_connection_accounts');
        Schema::drop('bank_connections');

        Schema::create('bank_connections', static function (Blueprint $table): void {
            $table->id();
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

        Schema::create('bank_connection_accounts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedBigInteger('bank_connection_id');
            $table->string('mono_account_id', 255);
            $table->string('mono_account_type', 50)->nullable();
            $table->unsignedInteger('mono_currency_code')->nullable();
            $table->string('mono_masked_pan', 255)->nullable();
            $table->string('mono_iban', 64)->nullable();
            $table->unsignedInteger('firefly_account_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->bigInteger('sync_from_ts')->nullable();
            $table->bigInteger('last_synced_statement_ts')->nullable();
            $table->string('last_seen_statement_id', 255)->nullable();

            $table->foreign('bank_connection_id')->references('id')->on('bank_connections')->onDelete('cascade');
            $table->foreign('firefly_account_id')->references('id')->on('accounts')->onDelete('set null');
            $table->unique(['bank_connection_id', 'mono_account_id'], 'bank_connection_accounts_unique_account');
            $table->index(['firefly_account_id', 'enabled'], 'bank_connection_accounts_firefly_enabled_index');
        });
    }
};
