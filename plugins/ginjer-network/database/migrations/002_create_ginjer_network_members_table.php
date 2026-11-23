<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ginjer_network_members', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('network_id');
            $table->foreign('network_id')->references('id')->on('ginjer_networks')->cascadeOnDelete();

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->string('role'); // proxy, shard, redis, other
            $table->string('shard_name')->nullable(); // this shard's name in velocity.toml
            $table->boolean('nodes_leader')->default(false);

            $table->timestamps();

            $table->unique('server_id'); // a server belongs to one network at most
            $table->unique(['network_id', 'shard_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ginjer_network_members');
    }
};
