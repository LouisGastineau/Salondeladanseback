<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->increments('id');
            $table->unsignedInteger('edition_id');
            $table->string('nom');
            $table->boolean('isSensible')->default(false);

            $table->foreign('edition_id')->references('id')->on('editions')->restrictOnDelete();
            $table->index(['edition_id', 'isSensible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
