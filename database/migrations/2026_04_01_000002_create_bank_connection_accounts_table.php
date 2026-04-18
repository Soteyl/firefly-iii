<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('bank_connection_accounts');
    }

    public function up(): void
    {
        if (!Schema::hasTable('bank_connection_accounts')) {
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
    }
};
