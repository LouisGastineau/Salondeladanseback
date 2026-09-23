<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the existing starter migration in the migration history.
        if (DB::table('users')->exists()) {
            throw new RuntimeException('La conversion du schema users exige une table vide.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->increments('id')->change();
            $table->renameColumn('name', 'nom');
            $table->dropColumn(['email_verified_at', 'remember_token', 'created_at', 'updated_at']);
            $table->string('prenom');
            $table->string('telephone');
            $table->string('photo_path');
            $table->string('role')->default('benevole');
            $table->boolean('isMineur')->default(false);
            $table->string('statut_planning')->default('brouillon');
            $table->unsignedInteger('invitation_code_id')->nullable()->unique();

            $table->foreign('invitation_code_id')->references('id')->on('invitation_codes')->restrictOnDelete();
            $table->index(['role', 'statut_planning']);
        });
    }

    public function down(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException('Rollback refuse : des comptes existent dans users.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['invitation_code_id']);
            $table->dropUnique(['invitation_code_id']);
            $table->dropIndex(['role', 'statut_planning']);
            $table->dropColumn(['prenom', 'telephone', 'photo_path', 'role', 'isMineur', 'statut_planning', 'invitation_code_id']);
            $table->renameColumn('nom', 'name');
            $table->bigIncrements('id')->change();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
