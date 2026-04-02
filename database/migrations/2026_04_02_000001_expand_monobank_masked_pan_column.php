<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('bank_connection_accounts')) {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM `bank_connection_accounts` LIKE 'mono_masked_pan'");
        if (!is_object($column) || !property_exists($column, 'Type')) {
            return;
        }

        if ('varchar(255)' === strtolower((string) $column->Type)) {
            return;
        }

        DB::statement('ALTER TABLE `bank_connection_accounts` MODIFY `mono_masked_pan` VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('bank_connection_accounts')) {
            return;
        }

        DB::statement('ALTER TABLE `bank_connection_accounts` MODIFY `mono_masked_pan` VARCHAR(32) NULL');
    }
};
