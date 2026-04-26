<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // Identity / auth
            $table->id();
            $table->string('role')->default('user');
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            // Profile fields the User model's $fillable / Filament
            // resources expect. Originally provisioned via the
            // legacy CodeCanyon SQL dump; later migrations reference
            // them (e.g. ->after('phone')) so they have to exist on
            // a fresh install.
            $table->string('slug')->nullable()->unique();
            $table->text('bio')->nullable();
            $table->string('twitter_handle')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('image')->nullable();

            // Account / role state
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->boolean('is_agent')->default(false)->index();
            $table->string('google_id')->nullable()->unique();

            // Editorial / AI scaffolding the dashboard reads
            $table->unsignedBigInteger('ai_daily_budget_microusd')->nullable();
            $table->unsignedSmallInteger('ai_calls_per_minute')->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
