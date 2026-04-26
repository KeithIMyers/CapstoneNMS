<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('favourite') && !Schema::hasTable('favorite')) {
            Schema::rename('favourite', 'favorite');
            return;
        }

        if (!Schema::hasTable('favorite')) {
            Schema::create('favorite', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('post_id')->index();
                $table->unique(['user_id', 'post_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('favorite') && !Schema::hasTable('favourite')) {
            Schema::rename('favorite', 'favourite');
        }
    }
};
