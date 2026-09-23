<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creneaux', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->increments('id');
            $table->unsignedInteger('mission_id');
            $table->date('jour');
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->unsignedInteger('capacite_max');

            $table->foreign('mission_id')->references('id')->on('missions')->restrictOnDelete();
            $table->index(['jour', 'heure_debut', 'heure_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creneaux');
    }
};
