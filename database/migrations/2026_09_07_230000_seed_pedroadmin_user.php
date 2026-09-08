<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure role 1 exists
        DB::table('roles')->insertOrIgnore([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $email = 'pedroadmin@agente.com';
        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        if (!$user) {
            $user = new User();
            $user->email = $email;
        }

        $user->first_name = 'Pedro';
        $user->last_name = 'Admin';
        $user->password = Hash::make('gr123456');
        $user->role_id = 1; // Administrador General de Agente Solutions
        $user->tenant_id = null; // Admin global
        $user->approval_status = 'approved';
        $user->is_active = 1;
        $user->subscription_status = 'exempt';
        $user->email_verified_at = now();
        $user->save();
    }

    public function down(): void
    {
        User::withoutGlobalScopes()->where('email', 'pedroadmin@agente.com')->delete();
    }
};