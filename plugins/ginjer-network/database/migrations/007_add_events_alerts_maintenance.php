<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ginjer_network_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('network_id')->index();
            $table->unsignedInteger('user_id')->nullable(); // null: the panel itself (health checks, schedules)
            $table->string('kind');   // power, update, health.down, crash, sync, ...
            $table->string('level');  // info, success, warning, danger
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::table('ginjer_networks', function (Blueprint $table) {
            $table->text('discord_webhook')->nullable();
            $table->string('discord_level')->default('alerts'); // alerts, all
            $table->boolean('maintenance')->default(false);
            $table->string('maintenance_motd')->nullable();
            $table->text('maintenance_allow')->nullable(); // player names, one per line
            $table->unsignedInteger('test_of_network_id')->nullable(); // a test copy of this network
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ginjer_network_events');
        Schema::table('ginjer_networks', function (Blueprint $table) {
            $table->dropColumn(['discord_webhook', 'discord_level', 'maintenance', 'maintenance_motd', 'maintenance_allow', 'test_of_network_id']);
        });
    }
};
