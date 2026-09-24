<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_codes', function (Blueprint $table) {
            $table->string('email')->nullable()->unique();
            $table->string('statut_envoi')->nullable();
            $table->timestamp('envoye_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invitation_codes', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'statut_envoi', 'envoye_at']);
        });
    }
};
