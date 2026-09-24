<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('validation_admin')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });
        DB::table('reservations')->whereIn('creneau_id', function ($q) {
            $q->select('creneaux.id')->from('creneaux')->join('missions', 'missions.id', '=', 'creneaux.mission_id')->where('missions.isSensible', true);
        })->update(['validation_admin' => 'acceptee']);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['validation_admin']);
            $table->dropColumn(['validation_admin', 'created_at']);
        });
    }
};
