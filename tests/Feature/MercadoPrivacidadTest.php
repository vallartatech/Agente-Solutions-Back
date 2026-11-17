<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Red: en el mercado abierto, el técnico no recibe la dirección, las coordenadas ni los datos del cliente
// hasta que le aceptan la cotización. Esquema mínimo en SQLite en memoria, como AccesoQrRedTest.
class MercadoPrivacidadTest extends TestCase
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
        $this->crearEsquema();

        $this->cliente = $this->usuario(4, 'cliente@red.mx', 'Juan', 'Pérez');
        $this->tecnico = $this->usuario(6, 'tecnico@red.mx', 'Juan', 'Técnico');
        $this->otro = $this->usuario(8, 'otro@red.mx', 'Luis', 'Otro');

        $clienteId = DB::table('clients')->insertGetId([
            'user_id' => $this->cliente->id, 'email' => 'cliente@red.mx',
            'first_name' => 'Juan', 'last_name' => 'Pérez', 'name' => 'Juan Pérez', 'phone' => '9991234567',
        ]);
        $propiedad = DB::table('properties')->insertGetId([
            'property_name' => 'Casa de progreso', 'address' => 'Calle 10 #123, Col. Centro, Mérida',
            'coordinates' => '20.9876543,-89.6123456', 'client_id' => $clienteId,
        ]);
        $this->orden = DB::table('work_orders')->insertGetId([
            'property_id' => $propiedad, 'type' => 'Problema', 'status' => 'Por Hacer', 'publish_network' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('network_quotes')->insert([
            ['work_order_id' => $this->orden, 'technician_id' => $this->tecnico->id, 'price' => 500, 'status' => 'pending'],
            ['work_order_id' => $this->orden, 'technician_id' => $this->otro->id, 'price' => 450, 'status' => 'pending'],
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
            $t->string('profile_picture')->nullable();
            $t->decimal('client_trato_avg', 3, 1)->nullable();
            $t->decimal('client_pago_avg', 3, 1)->nullable();
            $t->unsignedInteger('client_reviews_count')->default(0);
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('email')->nullable();
            $t->string('name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('phone')->nullable();
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('property_name')->nullable();
            $t->string('address')->nullable();
            $t->string('coordinates')->nullable();
            $t->timestamps();
        });
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id');
            $t->string('type')->nullable();
            $t->string('zone')->nullable();
            $t->string('equipment')->nullable();
            $t->text('description')->nullable();
            $t->string('status')->default('Por Hacer');
            $t->string('priority')->nullable();
            $t->boolean('publish_network')->default(0);
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->unsignedBigInteger('tecnico_dirigido_id')->nullable();
            $t->unsignedBigInteger('specialty_id')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->boolean('cancelled_by_tech')->default(0);
            $t->unsignedBigInteger('cancelled_technician_id')->nullable();
            $t->string('cancellation_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('arrived_at')->nullable();
            $t->string('arrival_status', 30)->nullable();
            $t->timestamp('arrival_confirmed_at')->nullable();
            $t->string('evidence_path')->nullable();
            $t->string('evidence_path_2')->nullable();
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
        Schema::create('specialties', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('icon')->nullable();
            $t->timestamps();
        });
        Schema::create('technician_specialties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('specialty_id');
            $t->string('custom_name')->nullable();
        });
        Schema::create('technician_reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->decimal('rating_stars', 3, 1)->nullable();
            $t->decimal('rating_time', 3, 1)->nullable();
            $t->text('comment')->nullable();
            $t->timestamps();
        });
        Schema::create('client_technician_relations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('technician_id');
            $t->unsignedBigInteger('client_user_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
    }

    private function usuario(int $rol, string $email, string $nombre, string $apellido): User
    {
        $id = DB::table('users')->insertGetId(['role_id' => $rol, 'email' => $email, 'first_name' => $nombre, 'last_name' => $apellido]);
        return User::withoutGlobalScopes()->find($id);
    }

    private function trabajo($respuesta): array
    {
        $respuesta->assertOk()->assertJsonPath('success', true);
        return collect($respuesta->json('data'))->firstWhere('id', $this->orden);
    }

    private function assertSinDatosPrivados(array $job): void
    {
        $json = json_encode($job, JSON_UNESCAPED_UNICODE);
        $this->assertArrayNotHasKey('property', $job);
        $this->assertStringNotContainsString('Calle 10', $json);
        $this->assertStringNotContainsString('20.9876543', $json);
        $this->assertStringNotContainsString('9991234567', $json);
        $this->assertStringNotContainsString('cliente@red.mx', $json);
        $this->assertStringNotContainsString('Pérez', $json);
        $this->assertSame('Cliente de la Red', $job['owner_name']);
        $this->assertNull($job['owner_user_id']);
        // Solo la zona aproximada
        $this->assertEquals(20.988, $job['area_lat']);
    }

    public function test_el_tecnico_no_ve_datos_de_la_propiedad_antes_de_que_lo_acepten(): void
    {
        Sanctum::actingAs($this->tecnico);
        $job = $this->trabajo($this->getJson('/api/mercado-trabajos'));

        $this->assertSinDatosPrivados($job);
        // Ve solo su cotización, no el precio de los demás; el total sí
        $this->assertCount(1, $job['network_quotes']);
        $this->assertSame($this->tecnico->id, (int) $job['network_quotes'][0]['technician_id']);
        $this->assertSame(2, (int) $job['network_quotes_count']);
    }

    public function test_sin_sesion_tampoco_se_ven(): void
    {
        $job = $this->trabajo($this->getJson('/api/mercado-trabajos'));
        $this->assertSinDatosPrivados($job);
        $this->assertCount(0, $job['network_quotes']);
    }

    public function test_only_mine_no_cuela_trabajos_por_parecido_de_nombre(): void
    {
        // El técnico se llama "Juan" igual que el cliente: el filtro por nombre lo incluía con todos los datos
        Sanctum::actingAs($this->tecnico);
        $job = collect($this->getJson('/api/mercado-trabajos?only_mine=1')->assertOk()->json('data'))->firstWhere('id', $this->orden);
        if ($job) {
            $this->assertSinDatosPrivados($job);
        } else {
            $this->assertNull($job);
        }
    }

    public function test_el_dueno_si_ve_su_propiedad(): void
    {
        Sanctum::actingAs($this->cliente);
        $job = collect($this->getJson('/api/mercado-trabajos?only_mine=1')->assertOk()->json('data'))->firstWhere('id', $this->orden);

        $this->assertSame('Calle 10 #123, Col. Centro, Mérida', $job['property']['address']);
        $this->assertSame('Juan Pérez', $job['owner_name']);
        $this->assertCount(2, $job['network_quotes']);
    }

    public function test_el_trabajo_trae_la_habilidad_que_necesita(): void
    {
        $hvac = DB::table('specialties')->insertGetId(['name' => 'Aire Acondicionado (HVAC)', 'icon' => '❄️']);
        DB::table('work_orders')->update(['specialty_id' => $hvac]);

        Sanctum::actingAs($this->tecnico);
        $job = $this->trabajo($this->getJson('/api/mercado-trabajos'));
        $this->assertSame('Aire Acondicionado (HVAC)', $job['specialty']['name']);
        $this->assertSame('❄️', $job['specialty']['icon']);

        // "No estoy seguro": sin habilidad
        DB::table('work_orders')->update(['specialty_id' => null]);
        $this->assertNull($this->trabajo($this->getJson('/api/mercado-trabajos'))['specialty']);
    }

    public function test_al_aceptar_la_cotizacion_el_tecnico_recibe_la_direccion(): void
    {
        DB::table('network_quotes')->where('technician_id', $this->tecnico->id)->update(['status' => 'accepted']);
        DB::table('work_orders')->update(['status' => 'Asignado']);

        Sanctum::actingAs($this->tecnico);
        $res = $this->getJson('/api/mercado-trabajos')->assertOk();

        $this->assertNull(collect($res->json('data'))->firstWhere('id', $this->orden));
        $aceptado = collect($res->json('accepted_jobs'))->firstWhere('id', $this->orden);
        $this->assertSame('Calle 10 #123, Col. Centro, Mérida', $aceptado['full_address']);
        $this->assertSame('9991234567', $aceptado['client_phone']);
    }
}
