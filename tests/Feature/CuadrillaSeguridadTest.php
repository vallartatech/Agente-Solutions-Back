<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Cuadrilla;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Etapa 0: solo Root/Admin cambian roles; el contratista solo agrega, aprueba, rechaza y quita
// técnicos de su cuadrilla (con cupo), y quitar no borra el historial.
class CuadrillaSeguridadTest extends TestCase
{
    private User $root;
    private User $admin;
    private User $contratista;
    private User $tecnico;
    private int $tenantContratista;
    private int $tenantOtro;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        Notification::fake();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->default(1);
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('password')->nullable();
            $t->string('profile_picture')->nullable();
            $t->boolean('is_active')->default(1);
            $t->string('approval_status', 50)->default('approved');
            $t->string('subscription_status')->nullable();
            $t->timestamp('subscription_start')->nullable();
            $t->timestamp('subscription_expires_at')->nullable();
            $t->decimal('subscription_amount', 8, 2)->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('membership_type')->nullable();
            $t->integer('max_properties')->nullable();
            $t->integer('max_clients')->nullable();
            $t->integer('extra_properties_count')->nullable();
            $t->decimal('subscription_amount', 8, 2)->nullable();
            $t->timestamp('subscription_expires_at')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_11_21_100000_add_max_cuadrilla_to_tenants.php'))->up();
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('work_order_technician', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
        });
        Schema::create('specialties', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('technician_specialties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('specialty_id');
            $t->string('custom_name')->nullable();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        $this->tenantOtro = DB::table('tenants')->insertGetId(['name' => 'Otra empresa', 'code' => 'OTRA']);
        $this->root = $this->usuario(0, null, 'root@agente.mx');
        $this->admin = $this->usuario(1, $this->tenantOtro, 'admin@agente.mx');

        $this->tenantContratista = DB::table('tenants')->insertGetId([
            'name' => 'Cuadrilla López', 'code' => 'AUT_C_1', 'membership_type' => 'market_contractor',
        ]);
        $this->contratista = $this->usuario(7, $this->tenantContratista, 'jefe@red.mx');
        DB::table('tenants')->where('id', $this->tenantContratista)->update(['owner_user_id' => $this->contratista->id]);
        $this->tecnico = $this->usuario(8, $this->tenantContratista, 'tec@red.mx');
    }

    private function usuario(int $rol, ?int $tenant, string $email, string $estado = 'approved', int $activo = 1): User
    {
        $id = DB::table('users')->insertGetId([
            'role_id' => $rol, 'tenant_id' => $tenant, 'email' => $email, 'first_name' => 'U' . $rol,
            'approval_status' => $estado, 'is_active' => $activo,
        ]);
        return User::withoutGlobalScopes()->find($id);
    }

    private function llenarCuadrilla(): void
    {
        $faltan = Cuadrilla::LIMITE_INICIAL - Cuadrilla::ocupados($this->tenantContratista);
        for ($i = 0; $i < $faltan; $i++) {
            $this->usuario(8, $this->tenantContratista, "relleno{$i}@red.mx");
        }
    }

    // ── Cambio de rol ──

    public function test_ni_el_contratista_ni_el_tecnico_cambian_roles(): void
    {
        foreach ([$this->contratista, $this->tecnico] as $quien) {
            Sanctum::actingAs($quien);
            $this->putJson("/api/usuarios/u_{$this->tecnico->id}/rol", ['role_id' => 1])->assertForbidden();
        }
        $this->assertSame(8, (int) $this->tecnico->fresh()->role_id);
    }

    public function test_el_admin_solo_cambia_roles_de_su_empresa_y_root_de_todos(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/usuarios/u_{$this->tecnico->id}/rol", ['role_id' => 2])->assertForbidden();

        $deSuEmpresa = $this->usuario(3, $this->tenantOtro, 'cliente@agente.mx');
        $this->putJson("/api/usuarios/u_{$deSuEmpresa->id}/rol", ['role_id' => 2])->assertOk();

        Sanctum::actingAs($this->root);
        $this->putJson("/api/usuarios/u_{$this->tecnico->id}/rol", ['role_id' => 6])->assertOk();
        $this->assertSame(6, (int) $this->tecnico->fresh()->role_id);
    }

    // ── Altas desde el panel ──

    public function test_el_contratista_solo_agrega_tecnicos_de_cuadrilla_a_su_empresa(): void
    {
        Sanctum::actingAs($this->contratista);
        $datos = ['first_name' => 'Juan', 'last_name' => 'Pérez', 'password' => 'secreto1'];

        $this->postJson('/api/admin/usuarios', $datos + ['email' => 'admin2@red.mx', 'role_id' => 1])->assertForbidden();
        $this->postJson('/api/admin/usuarios', $datos + ['email' => 'otro7@red.mx', 'role_id' => 7])->assertForbidden();

        $this->postJson('/api/admin/usuarios', $datos + ['email' => 'juan@red.mx', 'role_id' => 8, 'tenant_id' => $this->tenantOtro])
            ->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'juan@red.mx', 'role_id' => 8, 'tenant_id' => $this->tenantContratista]);
    }

    public function test_con_la_cuadrilla_llena_no_puede_agregar_ni_aprobar(): void
    {
        $pendiente = $this->usuario(8, $this->tenantContratista, 'pide@red.mx', 'pending', 0);
        $this->llenarCuadrilla();
        Sanctum::actingAs($this->contratista);

        $this->postJson('/api/admin/usuarios', [
            'first_name' => 'Uno', 'last_name' => 'Más', 'password' => 'secreto1', 'email' => 'mas@red.mx', 'role_id' => 8,
        ])->assertStatus(422);
        $this->postJson("/api/tenants/approve-technician/{$pendiente->id}")->assertStatus(422);
        $this->assertSame('pending', $pendiente->fresh()->approval_status);

        // Root amplía el cupo y ya se puede
        DB::table('tenants')->where('id', $this->tenantContratista)->update(['max_cuadrilla' => 8]);
        $this->postJson("/api/tenants/approve-technician/{$pendiente->id}")->assertOk();
        $this->assertSame('approved', $pendiente->fresh()->approval_status);
    }

    public function test_root_amplia_el_cupo_sin_cambiarle_el_rol_al_contratista(): void
    {
        Sanctum::actingAs($this->root);
        $this->postJson("/api/tenants/{$this->tenantContratista}/update-subscription-plan", ['max_cuadrilla' => 10])->assertOk();

        $this->assertSame(10, Cuadrilla::limite($this->tenantContratista));
        $this->assertSame(7, (int) $this->contratista->fresh()->role_id);
    }

    // ── Sala de espera ──

    public function test_la_sala_del_contratista_solo_muestra_su_cuadrilla_y_puede_rechazar(): void
    {
        $suyo = $this->usuario(8, $this->tenantContratista, 'suyo@red.mx', 'pending', 0);
        $this->usuario(2, $this->tenantContratista, 'interno@agente.mx', 'pending', 0);
        $ajeno = $this->usuario(8, $this->tenantOtro, 'ajeno@red.mx', 'pending', 0);

        Sanctum::actingAs($this->contratista);
        $lista = $this->getJson('/api/tenants/pending-technicians')->assertOk();
        $this->assertSame([$suyo->id], collect($lista->json('pending_technicians'))->pluck('id')->all());
        $this->assertSame(['ocupados' => 1, 'limite' => 7], $lista->json('cupo'));

        $this->postJson("/api/tenants/reject-technician/{$ajeno->id}")->assertForbidden();
        $this->postJson("/api/tenants/reject-technician/{$suyo->id}")->assertOk();
        $suyo = $suyo->fresh();
        $this->assertSame(Cuadrilla::RECHAZADO, $suyo->approval_status);
        $this->assertNull($suyo->tenant_id);
        $this->assertSame(0, (int) $suyo->is_active);
    }

    public function test_un_tecnico_de_la_cuadrilla_no_aprueba_a_otros(): void
    {
        $pendiente = $this->usuario(8, $this->tenantContratista, 'pide@red.mx', 'pending', 0);
        Sanctum::actingAs($this->tecnico);

        $this->getJson('/api/tenants/pending-technicians')->assertForbidden();
        $this->postJson("/api/tenants/approve-technician/{$pendiente->id}")->assertForbidden();
    }

    // ── Quitar, bloquear y eliminar ──

    public function test_quitar_desactiva_sin_borrar_y_conserva_el_historial(): void
    {
        $terminado = DB::table('work_orders')->insertGetId([
            'tenant_id' => $this->tenantOtro, 'tecnico_id' => $this->tecnico->id, 'status' => 'Terminado',
        ]);
        $this->tecnico->createToken('AgenteToken');
        Sanctum::actingAs($this->contratista);

        $this->postJson("/api/cuadrilla/u_{$this->tecnico->id}/quitar")->assertOk()->assertJsonPath('cupo.ocupados', 0);

        $tecnico = $this->tecnico->fresh();
        $this->assertSame(Cuadrilla::SIN_CUADRILLA, $tecnico->approval_status);
        $this->assertSame(0, (int) $tecnico->is_active);
        $this->assertSame($this->tenantContratista, (int) $tecnico->tenant_id);
        $this->assertSame(8, (int) $tecnico->role_id);
        $this->assertSame(0, $tecnico->tokens()->count());
        $this->assertSame($this->tecnico->id, (int) DB::table('work_orders')->where('id', $terminado)->value('tecnico_id'));

        // Ya no puede reactivarlo con "desbloquear"
        $this->putJson("/api/usuarios/u_{$this->tecnico->id}/toggle-bloqueo")->assertStatus(422);
    }

    public function test_no_se_quita_a_un_tecnico_con_trabajos_en_curso(): void
    {
        $orden = DB::table('work_orders')->insertGetId(['tenant_id' => $this->tenantOtro, 'status' => 'En Progreso']);
        DB::table('work_order_technician')->insert(['work_order_id' => $orden, 'technician_id' => $this->tecnico->id]);
        Sanctum::actingAs($this->contratista);

        $this->postJson("/api/cuadrilla/u_{$this->tecnico->id}/quitar")->assertStatus(422)->assertJsonPath('trabajos_en_curso', 1);
        $this->assertSame('approved', $this->tecnico->fresh()->approval_status);
    }

    public function test_el_contratista_no_quita_ni_bloquea_ni_elimina_fuera_de_su_cuadrilla(): void
    {
        $ajeno = $this->usuario(8, $this->tenantOtro, 'ajeno@red.mx');
        Sanctum::actingAs($this->contratista);

        $this->postJson("/api/cuadrilla/u_{$ajeno->id}/quitar")->assertForbidden();
        $this->putJson("/api/usuarios/u_{$ajeno->id}/toggle-bloqueo")->assertForbidden();
        $this->deleteJson("/api/usuarios/u_{$this->tecnico->id}")->assertForbidden();
        $this->assertSame('approved', $this->tecnico->fresh()->approval_status);

        // A los suyos sí los puede bloquear y desbloquear
        $this->putJson("/api/usuarios/u_{$this->tecnico->id}/toggle-bloqueo")->assertOk();
        $this->assertSame(0, (int) $this->tecnico->fresh()->is_active);
    }

    public function test_mi_cuadrilla_solo_lista_al_equipo_del_contratista(): void
    {
        $this->usuario(8, $this->tenantOtro, 'ajeno@red.mx');
        Sanctum::actingAs($this->contratista);

        $ids = collect($this->getJson('/api/usuarios')->assertOk()->json())->pluck('id')->sort()->values()->all();
        $this->assertSame(['u_' . $this->contratista->id, 'u_' . $this->tecnico->id], $ids);
    }

    public function test_un_tecnico_no_bloquea_a_nadie(): void
    {
        $otro = $this->usuario(8, $this->tenantContratista, 'otro@red.mx');
        Sanctum::actingAs($this->tecnico);

        $this->putJson("/api/usuarios/u_{$otro->id}/toggle-bloqueo")->assertForbidden();
        $this->assertSame(1, (int) $otro->fresh()->is_active);
    }

    public function test_el_tecnico_quitado_ve_el_aviso_al_iniciar_sesion(): void
    {
        DB::table('users')->where('id', $this->tecnico->id)->update([
            'password' => bcrypt('secreto1'), 'approval_status' => Cuadrilla::SIN_CUADRILLA, 'is_active' => 0,
        ]);

        $this->postJson('/api/login', ['email' => 'tec@red.mx', 'password' => 'secreto1'])
            ->assertForbidden()
            ->assertJsonPath('status', Cuadrilla::SIN_CUADRILLA);
    }
}
