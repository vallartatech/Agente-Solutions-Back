<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Tenant;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Caso específico: Jorge Vallarta (vallofacturas)
        $autonomo = User::withoutGlobalScopes()->where('email', 'jorgevallarta@agente.com')->first();
        if ($autonomo && $autonomo->tenant_id) {
            DB::table('clients')
                ->where('id', 5)
                ->orWhere('email', 'agentesolutions@yahoo.com')
                ->orWhere('email', 'jorgevallarta@agente.com')
                ->update([
                    'user_id' => $autonomo->id,
                    'tenant_id' => $autonomo->tenant_id,
                    'updated_at' => now(),
                ]);

            DB::table('properties')
                ->where('id', 22)
                ->orWhere('client_id', 5)
                ->update([
                    'tenant_id' => $autonomo->tenant_id,
                    'updated_at' => now(),
                ]);
        }

        // 2. Caso general: Para cualquier usuario Autónomo (rol 4 o 5), vincular clientes y propiedades huérfanas
        $autonomos = User::withoutGlobalScopes()->whereIn('role_id', [4, 5])->whereNotNull('tenant_id')->get();
        foreach ($autonomos as $u) {
            DB::table('clients')
                ->where('user_id', $u->id)
                ->orWhere('email', $u->email)
                ->update([
                    'user_id' => $u->id,
                    'tenant_id' => $u->tenant_id,
                ]);

            $cIds = DB::table('clients')
                ->where('user_id', $u->id)
                ->orWhere('email', $u->email)
                ->orWhere('tenant_id', $u->tenant_id)
                ->pluck('id');

            if ($cIds->isNotEmpty()) {
                DB::table('properties')
                    ->whereIn('client_id', $cIds)
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => $u->tenant_id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};