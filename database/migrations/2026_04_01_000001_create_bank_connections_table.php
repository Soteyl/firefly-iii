<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('bank_connections');
    }

    public function up(): void
    {
        if (!Schema::hasTable('bank_connections')) {
            Schema::create('bank_connections', static function (Blueprint $table): void {
                $table->id();
                $table->timestamps();

                $table->bigInteger('user_id', false, true);
                $table->bigInteger('user_group_id', false, true);
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
    }
};
