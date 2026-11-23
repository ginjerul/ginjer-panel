<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // one row per shard per minute, from the health check; kept for 7 days
        Schema::create('ginjer_shard_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('network_id');
            $table->foreign('network_id')->references('id')->on('ginjer_networks')->cascadeOnDelete();
            $table->string('shard');
            $table->timestamp('recorded_at');
            $table->float('tps')->nullable();
            $table->float('mspt')->nullable();
            $table->unsignedInteger('players')->nullable();
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('memory_limit_mb')->nullable();
            $table->float('cpu')->nullable();

            $table->index(['network_id', 'recorded_at']);
        });

        // daily rolling restart
        Schema::table('ginjer_networks', function (Blueprint $table) {
            $table->string('restart_daily_at', 5)->nullable(); // "HH:MM", panel time zone
            $table->date('last_daily_restart')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ginjer_shard_metrics');
        Schema::table('ginjer_networks', function (Blueprint $table) {
            $table->dropColumn(['restart_daily_at', 'last_daily_restart']);
        });
    }
};
