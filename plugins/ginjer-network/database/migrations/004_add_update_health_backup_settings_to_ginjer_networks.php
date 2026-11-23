<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ginjer_networks', function (Blueprint $table) {
            // where one-click updates download from
            $table->string('reef_jar_url')->nullable();
            $table->string('nodes_repo')->nullable();
            $table->string('nodes_release')->default('dev-master');
            $table->text('github_token')->nullable(); // encrypted; only for private repositories
            $table->unsignedInteger('restart_settle_seconds')->default(20);

            // health checks
            $table->boolean('health_alerts')->default(true);
            $table->unsignedInteger('lag_tps')->default(15);
            $table->unsignedInteger('lag_minutes')->default(5);
            $table->boolean('restart_on_lag')->default(false);

            // network backups
            $table->unsignedInteger('backup_every_hours')->nullable();
            $table->unsignedInteger('backup_keep')->default(5);
            $table->timestamp('last_backup_at')->nullable();
        });

        // one network backup = one Pelican backup per member, taken together
        Schema::create('ginjer_network_backups', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('network_id');
            $table->foreign('network_id')->references('id')->on('ginjer_networks')->cascadeOnDelete();
            $table->string('name');
            $table->json('backups'); // server id => backup uuid
            $table->string('status')->default('running'); // running, done, failed
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ginjer_network_backups');
        Schema::table('ginjer_networks', function (Blueprint $table) {
            $table->dropColumn(['reef_jar_url', 'nodes_repo', 'nodes_release', 'github_token', 'restart_settle_seconds',
                'health_alerts', 'lag_tps', 'lag_minutes', 'restart_on_lag', 'backup_every_hours', 'backup_keep', 'last_backup_at']);
        });
    }
};
