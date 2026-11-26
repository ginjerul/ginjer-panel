<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ginjer_networks', function (Blueprint $table) {
            $table->boolean('backup_before_risky')->default(true); // a network backup before updates, rebalances and restores
            $table->text('last_rebalance')->nullable();             // what the last rebalance changed, so it can be undone
            $table->boolean('status_public')->default(false);
            $table->string('status_token', 40)->nullable()->unique(); // the public status page's address
        });

        // announcements and commands on a schedule
        Schema::create('ginjer_network_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('network_id')->index();
            $table->string('name');
            $table->text('commands');                       // one per line
            $table->string('targets')->default('shards');   // shards, proxy, all
            $table->string('at_time', 5)->nullable();       // HH:MM, panel time
            $table->string('days')->nullable();             // ISO weekdays "1,3,5"; null: every day
            $table->unsignedInteger('every_minutes')->nullable(); // instead of a time
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ginjer_network_schedules');
        Schema::table('ginjer_networks', function (Blueprint $table) {
            $table->dropUnique(['status_token']);
            $table->dropColumn(['backup_before_risky', 'last_rebalance', 'status_public', 'status_token']);
        });
    }
};
