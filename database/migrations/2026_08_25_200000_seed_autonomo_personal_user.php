<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Tenant;

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

        // Create / Update Autónomo Personal (3 propiedades)
        $email = '1vallarta@agentesolutions.com';
        $autonomo = User::withoutGlobalScopes()->where('email', $email)->first();
        if (!$autonomo) {
            $autonomo = new User();
            $autonomo->email = $email;
        }
        $autonomo->first_name = 'Jorge (Personal)';
        $autonomo->last_name = 'Vallarta';
        $autonomo->password = $pw;
        $autonomo->role_id = 5; // Autónomo Personal
        $autonomo->approval_status = 'approved';
        $autonomo->is_active = 1;
        $autonomo->subscription_status = 'active';
        $autonomo->subscription_start = now();
        $autonomo->subscription_expires_at = now()->addMonths(6);
        $autonomo->subscription_amount = 299.00;
        $autonomo->save();

        $tenant = Tenant::where('owner_user_id', $autonomo->id)->first();
        if (!$tenant) {
            $tenant = Tenant::create([
                'name' => 'Jorge Vallarta Personal',
                'code' => 'AUT_P_' . $autonomo->id,
                'owner_user_id' => $autonomo->id,
                'email' => $autonomo->email,
                'status' => 'active',
                'membership_type' => 'autonomo_personal',
                'max_properties' => 3,
                'max_clients' => 0,
                'subscription_status' => 'active',
                'subscription_start' => now(),
                'subscription_expires_at' => now()->addMonths(6),
                'subscription_amount' => 299.00
            ]);
        } else {
            $tenant->max_properties = 3;
            $tenant->max_clients = 0;
            $tenant->membership_type = 'autonomo_personal';
            $tenant->subscription_status = 'active';
            $tenant->save();
        }
        $autonomo->tenant_id = $tenant->id;
        $autonomo->save();
    }

    public function down(): void
    {
    }
};
