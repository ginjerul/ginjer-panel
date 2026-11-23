<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ginjer_networks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->unique();
            $table->text('description')->nullable();

            // Reef sharding (reef.yml, "sharding" section)
            $table->string('world')->default('world');
            $table->text('regions')->nullable(); // YAML list, same format as reef.yml
            $table->string('shared_player_data_folder')->default('/reef-shared/playerdata');
            $table->unsignedInteger('border_protection')->default(16);
            $table->unsignedInteger('handoff_timeout_seconds')->default(5);

            // Nodes sharding (plugins/nodes/config.yml, "sharding" section)
            $table->string('redis_host')->default('127.0.0.1');
            $table->unsignedInteger('redis_port')->default(6379);
            $table->text('redis_password')->nullable(); // encrypted
            $table->unsignedInteger('redis_database')->default(0);
            $table->string('key_prefix')->default('nodes');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ginjer_networks');
    }
};
