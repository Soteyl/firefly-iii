<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        if (Schema::hasTable('bank_connection_accounts')) {
            Schema::table('bank_connection_accounts', static function (Blueprint $table): void {
                if (Schema::hasColumn('bank_connection_accounts', 'revolut_account_id')) {
                    $table->dropUnique('bank_connection_accounts_unique_revolut_account');
                    $table->dropColumn('revolut_account_id');
                }
                if (Schema::hasColumn('bank_connection_accounts', 'revolut_wallet_id')) {
                    $table->dropColumn('revolut_wallet_id');
                }
                if (Schema::hasColumn('bank_connection_accounts', 'revolut_account_type')) {
                    $table->dropColumn('revolut_account_type');
                }
                if (Schema::hasColumn('bank_connection_accounts', 'revolut_currency_code')) {
                    $table->dropColumn('revolut_currency_code');
                }
                if (Schema::hasColumn('bank_connection_accounts', 'revolut_account_name')) {
                    $table->dropColumn('revolut_account_name');
                }
                if (Schema::hasColumn('bank_connection_accounts', 'revolut_is_vault')) {
                    $table->dropColumn('revolut_is_vault');
                }
            });
        }

        if (Schema::hasTable('bank_connections') && Schema::hasColumn('bank_connections', 'provider_config')) {
            Schema::table('bank_connections', static function (Blueprint $table): void {
                $table->dropColumn('provider_config');
            });
        }
    }

    public function up(): void
    {
        if (Schema::hasTable('bank_connections') && !Schema::hasColumn('bank_connections', 'provider_config')) {
            Schema::table('bank_connections', static function (Blueprint $table): void {
                $table->json('provider_config')->nullable()->after('access_token');
            });
        }

        if (Schema::hasTable('bank_connection_accounts')) {
            Schema::table('bank_connection_accounts', static function (Blueprint $table): void {
                if (!Schema::hasColumn('bank_connection_accounts', 'revolut_account_id')) {
                    $table->string('revolut_account_id', 255)->nullable()->after('mono_iban');
                }
                if (!Schema::hasColumn('bank_connection_accounts', 'revolut_wallet_id')) {
                    $table->string('revolut_wallet_id', 255)->nullable()->after('revolut_account_id');
                }
                if (!Schema::hasColumn('bank_connection_accounts', 'revolut_account_type')) {
                    $table->string('revolut_account_type', 50)->nullable()->after('revolut_wallet_id');
                }
                if (!Schema::hasColumn('bank_connection_accounts', 'revolut_currency_code')) {
                    $table->string('revolut_currency_code', 16)->nullable()->after('revolut_account_type');
                }
                if (!Schema::hasColumn('bank_connection_accounts', 'revolut_account_name')) {
                    $table->string('revolut_account_name', 255)->nullable()->after('revolut_currency_code');
                }
                if (!Schema::hasColumn('bank_connection_accounts', 'revolut_is_vault')) {
                    $table->boolean('revolut_is_vault')->default(false)->after('revolut_account_name');
                }
            });

            Schema::table('bank_connection_accounts', static function (Blueprint $table): void {
                $table->unique(['bank_connection_id', 'revolut_account_id'], 'bank_connection_accounts_unique_revolut_account');
            });
        }
    }
};
