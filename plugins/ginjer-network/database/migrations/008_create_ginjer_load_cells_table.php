<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // where players spend their time, per day, in 128-block squares: what the rebalancer splits by
        Schema::create('ginjer_load_cells', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('network_id');
            $table->date('day');
            $table->integer('cell_x');
            $table->integer('cell_z');
            $table->float('weight')->default(0); // player-minutes, heavier on shards that were lagging
            $table->unique(['network_id', 'day', 'cell_x', 'cell_z']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ginjer_load_cells');
    }
};
