<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // what the optimizer changed, so it can be put back exactly
        Schema::create('ginjer_optimizer_backups', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->unsignedInteger('user_id')->nullable();
            $table->string('profile');
            $table->json('changes'); // rule ids applied
            $table->longText('files'); // json: path => original content
            $table->text('startup')->nullable(); // original startup command, if it was changed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ginjer_optimizer_backups');
    }
};
