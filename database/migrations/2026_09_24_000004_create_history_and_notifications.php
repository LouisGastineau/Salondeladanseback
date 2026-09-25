<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('admin_id')->index();
            $table->string('admin_nom');
            $table->string('action', 20)->index();
            $table->string('entite', 40);
            $table->unsignedInteger('entite_id');
            $table->json('avant')->nullable();
            $table->json('apres')->nullable();
            $table->string('route');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['entite', 'entite_id']);
        });
        Schema::create('email_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('cle')->unique();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('reservation_id')->nullable()->index();
            $table->string('type', 40);
            $table->json('contenu');
            $table->string('statut', 20)->default('a_envoyer')->index();
            $table->unsignedInteger('tentatives')->default(0);
            $table->timestamp('envoye_at')->nullable();
            $table->string('erreur')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_notifications');
        Schema::dropIfExists('admin_histories');
    }
};
