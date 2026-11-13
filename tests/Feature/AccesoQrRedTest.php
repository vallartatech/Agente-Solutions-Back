<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Red: acceso a la propiedad (QR y/o código) que sube el cliente; el técnico lo ve al marcar "Llegué".
// Esquema mínimo en SQLite en memoria + las migraciones del QR, como AccesoQrTest.
class AccesoQrRedTest extends TestCase
{
    private User $cliente;
    private User $tecnico;
    private User $otro;
    private int $orden;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        Notification::fake();
        config(['services.onesignal.app_id' => null, 'services.onesignal.rest_api_key' => null]);
        \Closure::bind(fn () => \App\Services\PerfilProfesional::$tablaLista = null, null, \App\Services\PerfilProfesional::class)();
        $this->crearEsquema();
        (require database_path('migrations/2026_11_04_100000_create_access_qrs_tables.php'))->up();
        (require database_path('migrations/2026_11_13_120000_add_codigo_to_access_qrs.php'))->up();

        $this->cliente = $this->usuario(4, 'cliente@red.mx');
        $this->tecnico = $this->usuario(6, 'tecnico@red.mx');
        $this->otro = $this->usuario(6, 'otro@red.mx');

        $clienteId = DB::table('clients')->insertGetId(['user_id' => $this->cliente->id, 'email' => 'cliente@red.mx']);
        $propiedad = DB::table('properties')->insertGetId(['property_name' => 'Casa', 'address' => 'Centro', 'client_id' => $clienteId]);
        $this->orden = DB::table('work_orders')->insertGetId([
            'property_id' => $propiedad, 'type' => 'Mantenimiento', 'status' => 'Asignado', 'tecnico_id' => $this->tecnico->id,
        ]);
    }

    private function crearEsquema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->default(1);
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('email')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('property_name')->nullable();
            $t->string('address')->nullable();
            $t->timestamps();
        });
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id');
            $t->string('batch_id')->nullable();
            $t->string('type')->nullable();
            $t->string('equipment')->nullable();
            $t->string('status')->default('Por Hacer');
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->string('arrival_status', 30)->default('PENDIENTE');
            $t->timestamp('arrived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id')->nullable();
            $t->timestamps();
        });
        Schema::create('network_quotes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
            $t->decimal('price', 10, 2);
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        Schema::create('work_order_technician', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
        });
    }

    private function usuario(int $rol, string $email): User
    {
        $id = DB::table('users')->insertGetId(['role_id' => $rol, 'email' => $email, 'first_name' => 'U' . $rol]);
        return User::withoutGlobalScopes()->find($id);
    }

    private function subir(array $datos = [])
    {
        Sanctum::actingAs($this->cliente);
        return $this->post("/api/red/trabajos/{$this->orden}/acceso", $datos + [
            'slot' => 1,
            'imagen' => UploadedFile::fake()->image('qr.png', 200, 200),
            'codigo' => 'PIN 4821',
            'label' => 'Caseta',
            'valid_until' => now()->addDays(2)->toDateTimeString(),
        ], ['Accept' => 'application/json']);
    }

    public function test_el_cliente_sube_qr_y_codigo_cifrados(): void
    {
        $this->subir()->assertOk()
            ->assertJsonPath('puede_editar', true)
            ->assertJsonPath('accesos.0.label', 'Caseta')
            ->assertJsonPath('accesos.0.codigo', 'PIN 4821')
            ->assertJsonPath('accesos.0.tiene_imagen', true);

        $guardado = DB::table('access_qrs')->first();
        $this->assertStringNotContainsString('4821', $guardado->codigo);
        $this->assertNotNull($guardado->valid_until);
    }

    public function test_solo_codigo_sin_imagen(): void
    {
        $this->subir(['imagen' => null])->assertOk()
            ->assertJsonPath('accesos.0.tiene_imagen', false)
            ->assertJsonPath('accesos.0.codigo', 'PIN 4821');
    }

    public function test_vigencia_obligatoria_y_maximo_7_dias(): void
    {
        $this->subir(['valid_until' => ''])->assertStatus(422)->assertJsonValidationErrors('valid_until');
        $this->subir(['valid_until' => now()->addDays(8)->toDateTimeString()])->assertStatus(422)->assertJsonValidationErrors('valid_until');
        $this->subir(['imagen' => null, 'codigo' => ''])->assertStatus(422);
        $this->assertSame(0, DB::table('access_qrs')->count());
    }

    public function test_el_tecnico_lo_ve_solo_al_marcar_llegue(): void
    {
        $this->subir()->assertOk();

        Sanctum::actingAs($this->tecnico);
        $this->getJson("/api/red/trabajos/{$this->orden}/acceso")->assertOk()
            ->assertJsonPath('puede_ver', false)
            ->assertJsonPath('disponible_al_llegar', true)
            ->assertJsonPath('accesos.0.codigo', null)
            ->assertJsonPath('accesos.0.imagen_url', null);
        $this->get("/api/red/trabajos/{$this->orden}/acceso/1/imagen", ['Accept' => 'application/json'])->assertStatus(403);

        DB::table('work_orders')->update(['arrived_at' => now()]);
        $this->getJson("/api/red/trabajos/{$this->orden}/acceso")->assertOk()
            ->assertJsonPath('puede_ver', true)
            ->assertJsonPath('accesos.0.codigo', 'PIN 4821');
        $this->get("/api/red/trabajos/{$this->orden}/acceso/1/imagen")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseHas('access_qr_logs', ['action' => 'visto', 'user_id' => $this->tecnico->id]);

        // El técnico no puede subir ni borrar
        $this->post("/api/red/trabajos/{$this->orden}/acceso", ['slot' => 2, 'codigo' => 'X', 'valid_until' => now()->addDay()->toDateTimeString()], ['Accept' => 'application/json'])->assertStatus(403);
        $this->deleteJson("/api/red/trabajos/{$this->orden}/acceso/1")->assertStatus(403);
    }

    public function test_otro_tecnico_no_tiene_acceso(): void
    {
        $this->subir()->assertOk();
        Sanctum::actingAs($this->otro);
        $this->getJson("/api/red/trabajos/{$this->orden}/acceso")->assertStatus(403);
    }

    public function test_se_borra_al_vencer_y_al_cancelar(): void
    {
        $this->subir()->assertOk();
        DB::table('access_qrs')->update(['valid_until' => now()->subMinute()]);
        $this->getJson("/api/red/trabajos/{$this->orden}/acceso")->assertOk()->assertJsonCount(0, 'accesos');

        $this->subir()->assertOk();
        DB::table('work_orders')->update(['status' => 'Cancelado_Cliente']);
        $this->getJson("/api/red/trabajos/{$this->orden}/acceso")->assertOk()
            ->assertJsonCount(0, 'accesos')->assertJsonPath('puede_editar', false);
    }
}
