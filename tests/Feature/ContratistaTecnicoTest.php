<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Cuadrilla;
use App\Services\PerfilProfesional;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Etapa 1: el contratista (7) es un técnico de la red con una cuadrilla a su cargo.
// Llena el perfil profesional, ve "Mi cuadrilla" y, como cualquier técnico, solo sus cotizaciones.
class ContratistaTecnicoTest extends TestCase
{
    private int $tenantContratista;
    private User $contratista;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        self::olvidarTablaPerfiles();
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
            $t->decimal('rating_stars_avg', 3, 1)->nullable();
            $t->decimal('rating_time_avg', 3, 1)->nullable();
            $t->integer('total_reviews_count')->default(0);
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->string('membership_type')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });
        (require database_path('migrations/2026_11_21_100000_add_max_cuadrilla_to_tenants.php'))->up();
        Schema::create('specialties', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('icon')->nullable();
            $t->timestamps();
        });
        Schema::create('technician_specialties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('specialty_id');
            $t->string('custom_name')->nullable();
            $t->timestamps();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('property_name')->nullable();
            $t->timestamps();
        });
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->timestamps();
        });
        Schema::create('service_technician', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('service_id');
            $t->unsignedBigInteger('technician_id');
        });
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id')->nullable();
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('work_order_technician', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
        });
        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('service_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->unsignedBigInteger('property_id')->nullable();
            $t->string('status')->nullable();
            $t->string('created_by_role')->nullable();
            $t->unsignedBigInteger('cash_confirmed_by')->nullable();
            $t->timestamps();
        });
        Schema::create('network_quotes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
            $t->decimal('amount', 10, 2)->nullable();
            $t->string('status')->default('pending');
            $t->text('message')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_11_06_100000_create_technician_professional_profiles.php'))->up();
        (require database_path('migrations/2026_11_09_120000_add_documentos_to_technician_profiles.php'))->up();

        $this->tenantContratista = DB::table('tenants')->insertGetId([
            'name' => 'Cuadrilla López', 'code' => 'AUT_C_9', 'membership_type' => 'market_contractor',
        ]);
        $this->contratista = $this->usuario(7, $this->tenantContratista, 'Jefe');
        DB::table('tenants')->where('id', $this->tenantContratista)->update(['owner_user_id' => $this->contratista->id]);
    }

    protected function tearDown(): void
    {
        // Esta prueba crea technician_profiles: que las siguientes no hereden "la tabla existe"
        self::olvidarTablaPerfiles();
        parent::tearDown();
    }

    private static function olvidarTablaPerfiles(): void
    {
        \Closure::bind(fn () => PerfilProfesional::$tablaLista = null, null, PerfilProfesional::class)();
    }

    private function usuario(int $rol, ?int $tenant, string $nombre, string $estado = 'approved', int $activo = 1): User
    {
        $id = DB::table('users')->insertGetId([
            'role_id' => $rol, 'tenant_id' => $tenant, 'first_name' => $nombre, 'last_name' => 'Prueba',
            'email' => strtolower($nombre) . '@red.mx', 'approval_status' => $estado, 'is_active' => $activo,
        ]);
        return User::withoutGlobalScopes()->find($id);
    }

    private function validar(User $tecnico, array $extra = []): void
    {
        DB::table('technician_profiles')->updateOrInsert(['user_id' => $tecnico->id], array_merge([
            'obligatorio' => true, 'estado' => 'aprobado', 'aprobado_at' => now(),
            'ine_frente_path' => 'privado/ine/' . $tecnico->id . '-frente.jpg',
        ], $extra));
    }

    public function test_el_contratista_es_tecnico_de_la_red_y_llena_el_perfil_antes_de_usar_su_cuenta(): void
    {
        $this->assertTrue(PerfilProfesional::esTecnicoRed($this->contratista));

        // Contratista nuevo: solo puede completar su perfil
        Sanctum::actingAs($this->contratista);
        $this->getJson('/api/cuadrilla')->assertForbidden()->assertJsonPath('reason', 'perfil_profesional_pendiente');

        $this->validar($this->contratista);
        $this->getJson('/api/cuadrilla')->assertOk();
    }

    public function test_al_contratista_se_le_pide_el_perfil_completo_con_datos_de_cobro(): void
    {
        $this->validar($this->contratista, ['estado' => 'incompleto', 'aprobado_at' => null]);
        $faltan = PerfilProfesional::faltantes(PerfilProfesional::perfilDe($this->contratista), $this->contratista);

        foreach (['clabe', 'banco', 'titular_cuenta', 'emite_factura', 'presentacion', 'antecedentes_penales'] as $clave) {
            $this->assertArrayHasKey($clave, $faltan);
        }
    }

    public function test_la_presentacion_pide_minimo_10_letras(): void
    {
        $this->validar($this->contratista, ['presentacion' => 'Plomero 10']);
        $faltan = PerfilProfesional::faltantes(PerfilProfesional::perfilDe($this->contratista), $this->contratista);
        $this->assertArrayNotHasKey('presentacion', $faltan);

        $this->validar($this->contratista, ['presentacion' => 'Plomero']);
        $faltan = PerfilProfesional::faltantes(PerfilProfesional::perfilDe($this->contratista), $this->contratista);
        $this->assertArrayHasKey('presentacion', $faltan);
    }

    public function test_mi_cuadrilla_muestra_al_equipo_sin_sus_documentos(): void
    {
        $this->validar($this->contratista);
        $ana = $this->usuario(8, $this->tenantContratista, 'Ana');
        $this->validar($ana, ['identidad_verificada_at' => now()]);
        $beto = $this->usuario(8, $this->tenantContratista, 'Beto', Cuadrilla::SIN_CUADRILLA, 0);
        $this->usuario(8, $this->tenantContratista, 'Carla', 'pending', 0);
        $this->usuario(8, DB::table('tenants')->insertGetId(['name' => 'Otra', 'code' => 'OTRA']), 'Dani');

        Sanctum::actingAs($this->contratista);
        $res = $this->getJson('/api/cuadrilla')->assertOk()
            ->assertJsonPath('cuadrilla.codigo', 'AUT_C_9')
            ->assertJsonPath('cupo', ['ocupados' => 1, 'limite' => 7])
            ->assertJsonPath('pendientes', 1);

        $equipo = collect($res->json('equipo'))->keyBy('id');
        $this->assertSame([$ana->id, $beto->id], $equipo->keys()->all());
        $this->assertSame('activo', $equipo[$ana->id]['estado']);
        $this->assertTrue($equipo[$ana->id]['perfil']['validado']);
        $this->assertTrue($equipo[$ana->id]['perfil']['identidad_verificada']);
        $this->assertSame('fuera', $equipo[$beto->id]['estado']);
        $this->assertFalse($equipo[$beto->id]['perfil']['validado']);

        // Nunca las rutas de la INE ni otros documentos
        $this->assertStringNotContainsString('privado/ine', $res->getContent());
    }

    public function test_mi_cuadrilla_es_solo_del_contratista(): void
    {
        $tecnico = $this->usuario(8, $this->tenantContratista, 'Ana');
        $this->validar($tecnico);
        Sanctum::actingAs($tecnico);
        $this->getJson('/api/cuadrilla')->assertForbidden();

        $independiente = $this->usuario(6, null, 'Eva');
        $this->validar($independiente);
        Sanctum::actingAs($independiente);
        $this->getJson('/api/cuadrilla')->assertForbidden();
    }

    public function test_cada_tecnico_ve_solo_sus_cotizaciones_de_la_red(): void
    {
        $this->validar($this->contratista);
        $independiente = $this->usuario(6, null, 'Eva');
        $this->validar($independiente);
        $orden = DB::table('work_orders')->insertGetId(['status' => 'Por Hacer']);
        $deEva = DB::table('network_quotes')->insertGetId(['work_order_id' => $orden, 'technician_id' => $independiente->id, 'amount' => 900]);
        $delJefe = DB::table('network_quotes')->insertGetId(['work_order_id' => $orden, 'technician_id' => $this->contratista->id, 'amount' => 800]);

        Sanctum::actingAs($independiente);
        $ids = collect($this->getJson('/api/cotizaciones')->assertOk()->json())->pluck('id')->all();
        $this->assertContains('net_' . $deEva, $ids);
        $this->assertNotContains('net_' . $delJefe, $ids);

        Sanctum::actingAs($this->contratista);
        $ids = collect($this->getJson('/api/cotizaciones')->assertOk()->json())->pluck('id')->all();
        $this->assertContains('net_' . $delJefe, $ids);
        $this->assertNotContains('net_' . $deEva, $ids);
    }

    public function test_el_tecnico_sin_empresa_solo_ve_cotizaciones_internas_de_sus_trabajos(): void
    {
        $independiente = $this->usuario(6, null, 'Eva');
        $this->validar($independiente);
        $suya = DB::table('work_orders')->insertGetId(['tenant_id' => 1, 'tecnico_id' => $independiente->id, 'status' => 'Asignado']);
        $ajena = DB::table('work_orders')->insertGetId(['tenant_id' => 1, 'status' => 'Asignado']);
        $cotSuya = DB::table('quotes')->insertGetId(['tenant_id' => 1, 'work_order_id' => $suya, 'status' => 'Pendiente']);
        $cotAjena = DB::table('quotes')->insertGetId(['tenant_id' => 1, 'work_order_id' => $ajena, 'status' => 'Pendiente']);

        Sanctum::actingAs($independiente);
        $ids = collect($this->getJson('/api/cotizaciones')->assertOk()->json())->pluck('id')->all();
        $this->assertContains($cotSuya, $ids);
        $this->assertNotContains($cotAjena, $ids);
    }
}
