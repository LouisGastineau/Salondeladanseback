<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('creneau_id');
            $table->string('statut')->default('brouillon');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('creneau_id')->references('id')->on('creneaux')->restrictOnDelete();
            $table->unique(['user_id', 'creneau_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
