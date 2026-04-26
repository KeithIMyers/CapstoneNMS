<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'two_factor_last_used_ts')) {
                $table->unsignedBigInteger('two_factor_last_used_ts')
                    ->nullable()
                    ->after('two_factor_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'two_factor_last_used_ts')) {
                $table->dropColumn('two_factor_last_used_ts');
            }
        });
    }
};
