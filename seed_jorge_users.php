<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$pw = \Illuminate\Support\Facades\Hash::make('12345678');

// Ensure roles exist
foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8] as $rId) {
    \Illuminate\Support\Facades\DB::table('roles')->insertOrIgnore([
        'id' => $rId, 
        'created_at' => now(), 
        'updated_at' => now()
    ]);
}

// 1. Create / Update Autónomo
$autonomo = \App\Models\User::withoutGlobalScopes()->where('email', 'jorgevallarta@agente.com')->first();
if (!$autonomo) {
    $autonomo = new \App\Models\User();
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

$tenant = \App\Models\Tenant::where('owner_user_id', $autonomo->id)->first();
if (!$tenant) {
    $tenant = \App\Models\Tenant::create([
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
echo "Autonomo creado/actualizado: {$autonomo->email} (ID: {$autonomo->id}, Tenant: {$tenant->id})
";

// 2. Create / Update Técnico de la Red
$tecnico = \App\Models\User::withoutGlobalScopes()->where('email', 'jorgevallarta@tecnico.com')->first();
if (!$tecnico) {
    $tecnico = new \App\Models\User();
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

// Specialties for Network Technician
$specs = ['Electricidad', 'Plomería', 'Aire Acondicionado', 'Pintura', 'Cerrajería', 'Albañilería', 'Refrigeración', 'Mantenimiento General'];
$specIds = [];
foreach ($specs as $spName) {
    $sp = \App\Models\Specialty::firstOrCreate(
        ['name' => $spName],
        ['icon' => '⚡', 'category' => 'General']
    );
    $specIds[] = $sp->id;
}
$tecnico->specialties()->sync($specIds);

echo "Tecnico de la Red creado/actualizado: {$tecnico->email} (ID: {$tecnico->id}, Role: 8)
";
