<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ginjer_networks', function (Blueprint $table) {
            // how rolling restarts and updates go through the shards
            $table->string('rolling_order')->default('followers_first');
            $table->unsignedInteger('rolling_batch')->default(1);
            $table->unsignedInteger('rolling_warn_seconds')->default(0);
            $table->boolean('rolling_include_proxy')->default(false);
            $table->unsignedInteger('rolling_stop_timeout')->default(180);
            $table->unsignedInteger('rolling_start_timeout')->default(300);
            $table->string('rolling_on_failure')->default('stop'); // stop, continue

            // shared database (MySQL on a Pelican database host)
            $table->unsignedInteger('database_id')->nullable();
            // config files kept the same on every shard
            $table->text('synced_files')->nullable();
            $table->unsignedInteger('sync_source_server_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ginjer_networks', function (Blueprint $table) {
            $table->dropColumn(['rolling_order', 'rolling_batch', 'rolling_warn_seconds', 'rolling_include_proxy', 'rolling_stop_timeout',
                'rolling_start_timeout', 'rolling_on_failure', 'database_id', 'synced_files', 'sync_source_server_id']);
        });
    }
};
