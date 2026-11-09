<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Specialty;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure roles 0 to 8 exist
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8] as $rId) {
            DB::table('roles')->insertOrIgnore([
                'id' => $rId, 
                'created_at' => now(), 
                'updated_at' => now()
            ]);
        }

        $pw = Hash::make('12345678');

        // 1. Create / Update Autónomo: jorgevallarta@agente.com
        $autonomo = User::withoutGlobalScopes()->where('email', 'jorgevallarta@agente.com')->first();
        if (!$autonomo) {
            $autonomo = new User();
            $autonomo->email = 'jorgevallarta@agente.com';
        }
        $autonomo->first_name = 'Jorge';
        $autonomo->last_name = 'Vallarta';
        $autonomo->password = $pw;
        $autonomo->role_id = 4;
        $autonomo->approval_status = 'approved';
        $autonomo->is_active = 1;
        $autonomo->subscription_status = 'active';
        $autonomo->subscription_start = now();
        $autonomo->subscription_expires_at = now()->addMonths(6);
        $autonomo->subscription_amount = 935.00;
        $autonomo->save();

        $tenant = Tenant::where('owner_user_id', $autonomo->id)->first();
        if (!$tenant) {
            $tenant = Tenant::create([
                'name' => 'Jorge Vallarta Servicios',
                'code' => 'AUT_JV_' . $autonomo->id,
                'owner_user_id' => $autonomo->id,
                'email' => $autonomo->email,
                'status' => 'active',
                'membership_type' => 'autonomo_empresarial',
                'max_properties' => 30,
                'max_clients' => 30,
                'subscription_status' => 'active',
                'subscription_start' => now(),
                'subscription_expires_at' => now()->addMonths(6),
                'subscription_amount' => 935.00
            ]);
        }
        $autonomo->tenant_id = $tenant->id;
        $autonomo->save();

        // 2. Create / Update Técnico de la Red: jorgevallarta@tecnico.com
        $tecnico = User::withoutGlobalScopes()->where('email', 'jorgevallarta@tecnico.com')->first();
        if (!$tecnico) {
            $tecnico = new User();
            $tecnico->email = 'jorgevallarta@tecnico.com';
        }
        $tecnico->first_name = 'Jorge';
        $tecnico->last_name = 'Vallarta (Red)';
        $tecnico->password = $pw;
        $tecnico->role_id = 8;
        $tecnico->tenant_id = null;
        $tecnico->approval_status = 'approved';
        $tecnico->is_active = 1;
        $tecnico->subscription_status = 'active';
        $tecnico->subscription_start = now();
        $tecnico->subscription_expires_at = now()->addYear();
        $tecnico->save();

        // Especialidades
        $specs = ['Electricidad', 'Plomería', 'Aire Acondicionado (HVAC)', 'Pintura e Impermeabilización', 'Cerrajería y Seguridad', 'Albañilería y Remodelación', 'Refrigeración', 'Mantenimiento General'];
        $specIds = [];
        foreach ($specs as $spName) {
            $sp = Specialty::firstOrCreate(
                ['name' => $spName],
                ['icon' => '⚡', 'category' => 'General']
            );
            $specIds[] = $sp->id;
        }
        $tecnico->specialties()->sync($specIds);
    }

    public function down(): void
    {
    }
};
