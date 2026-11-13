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

// Red: ajuste de una cotización ya aceptada (proponer, responder, retirar, decidir tras rechazo)
// y el candado de dueño en /network-quotes/{id}/accept y /reject.
// Esquema mínimo en SQLite en memoria + la migración nueva, como el resto de pruebas.
class AjusteCotizacionTest extends TestCase
{
    private User $cliente;
    private User $tecnico;
    private User $intruso;
    private int $orden;
    private int $cotizacion;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        Notification::fake();
        // Otra prueba pudo dejar en caché que existe technician_profiles (candado del perfil profesional)
        \Closure::bind(fn () => \App\Services\PerfilProfesional::$tablaLista = null, null, \App\Services\PerfilProfesional::class)();
        config(['services.onesignal.app_id' => null, 'services.onesignal.rest_api_key' => null]);
        $this->crearEsquema();
        (require database_path('migrations/2026_11_13_100000_create_network_quote_ajustes_table.php'))->up();

        $this->cliente = $this->usuario(4, 'cliente@red.mx');
        $this->tecnico = $this->usuario(6, 'tecnico@red.mx');
        $this->intruso = $this->usuario(6, 'otro@red.mx');

        $clienteId = DB::table('clients')->insertGetId(['user_id' => $this->cliente->id, 'email' => 'cliente@red.mx', 'first_name' => 'Ana']);
        $propiedad = DB::table('properties')->insertGetId(['property_name' => 'Casa', 'address' => 'Centro', 'client_id' => $clienteId]);
        $this->orden = DB::table('work_orders')->insertGetId([
            'property_id' => $propiedad, 'type' => 'Mantenimiento', 'equipment' => 'Aire',
            'status' => 'Asignado', 'tecnico_id' => $this->tecnico->id,
        ]);
        $this->cotizacion = DB::table('network_quotes')->insertGetId([
            'work_order_id' => $this->orden, 'technician_id' => $this->tecnico->id, 'price' => 120, 'status' => 'accepted',
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
            $t->boolean('cancelled_by_tech')->default(false);
            $t->unsignedBigInteger('cancelled_technician_id')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('network_quotes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
            $t->decimal('price', 10, 2);
            $t->text('message')->nullable();
            $t->string('status')->default('pending');
            $t->json('chat_history')->nullable();
            $t->timestamps();
        });
        Schema::create('work_order_technician', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
            $t->timestamps();
        });
    }

    private function usuario(int $rol, string $email): User
    {
        $id = DB::table('users')->insertGetId(['role_id' => $rol, 'email' => $email, 'first_name' => 'U' . $rol]);
        return User::withoutGlobalScopes()->find($id);
    }

    private function proponer(array $datos = [])
    {
        Sanctum::actingAs($this->tecnico);
        return $this->postJson("/api/mercado-trabajos/{$this->orden}/ajustes", array_merge([
            'precio' => 850, 'motivo' => 'El compresor está dañado y hay que cambiarlo.',
        ], $datos));
    }

    public function test_el_tecnico_propone_y_el_cliente_acepta_con_historial(): void
    {
        $this->proponer()->assertOk()
            ->assertJsonPath('ajuste_pendiente.version', 2)
            ->assertJsonPath('ajuste_pendiente.precio_anterior', 120)
            ->assertJsonPath('puede_ajustar', false);

        // Mientras está pendiente no se puede mandar otro
        $this->proponer(['precio' => 900])->assertStatus(422);

        $ajusteId = DB::table('network_quote_ajustes')->value('id');
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/ajustes-cotizacion/{$ajusteId}/responder", ['accion' => 'aceptar'])
            ->assertOk()
            ->assertJsonPath('precio_vigente', 850)
            ->assertJsonPath('precio_original', 120);

        $this->assertEquals(850, (float) DB::table('network_quotes')->value('price'));

        // Un segundo ajuste es la v3 y parte del precio vigente
        $this->proponer(['precio' => 1000])->assertOk()
            ->assertJsonPath('ajuste_pendiente.version', 3)
            ->assertJsonPath('ajuste_pendiente.precio_anterior', 850);
    }

    public function test_con_desglose_el_total_es_la_suma_de_las_filas(): void
    {
        $desglose = json_encode([
            'servicios' => [['descripcion' => 'Cambio de compresor', 'cantidad' => 1, 'precio' => 600]],
            'materiales' => [['descripcion' => 'Gas', 'cantidad' => 2, 'precio' => 150], ['descripcion' => '', 'cantidad' => 1, 'precio' => 0]],
        ]);

        $this->proponer(['precio' => 1, 'desglose' => $desglose])->assertOk()
            ->assertJsonPath('ajuste_pendiente.precio', 900)
            ->assertJsonCount(1, 'ajuste_pendiente.desglose.materiales');
    }

    public function test_solo_el_dueno_responde_y_solo_el_tecnico_propone(): void
    {
        Sanctum::actingAs($this->intruso);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/ajustes", ['precio' => 1, 'motivo' => 'Quiero cobrar más'])
            ->assertStatus(403);

        $this->proponer()->assertOk();
        $ajusteId = DB::table('network_quote_ajustes')->value('id');

        // El propio técnico no puede aceptar su ajuste
        Sanctum::actingAs($this->tecnico);
        $this->postJson("/api/ajustes-cotizacion/{$ajusteId}/responder", ['accion' => 'aceptar'])->assertStatus(403);

        Sanctum::actingAs($this->intruso);
        $this->getJson("/api/mercado-trabajos/{$this->orden}/ajustes")->assertStatus(403);
    }

    public function test_tras_rechazo_el_tecnico_continua_con_el_precio_vigente(): void
    {
        $this->proponer()->assertOk();
        $ajusteId = DB::table('network_quote_ajustes')->value('id');

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/ajustes-cotizacion/{$ajusteId}/responder", ['accion' => 'rechazar'])->assertOk();
        $this->assertEquals(120, (float) DB::table('network_quotes')->value('price'));

        Sanctum::actingAs($this->tecnico);
        $this->getJson("/api/mercado-trabajos/{$this->orden}/ajustes")
            ->assertJsonPath('ajuste_por_decidir.id', $ajusteId)
            ->assertJsonPath('puede_ajustar', false);

        $this->postJson("/api/ajustes-cotizacion/{$ajusteId}/decidir", ['decision' => 'continuar'])
            ->assertOk()
            ->assertJsonPath('ajuste_por_decidir', null)
            ->assertJsonPath('puede_ajustar', true);
        $this->assertSame('Asignado', DB::table('work_orders')->value('status'));
    }

    public function test_tras_rechazo_el_tecnico_cancela_sin_penalizacion(): void
    {
        $this->proponer()->assertOk();
        $ajusteId = DB::table('network_quote_ajustes')->value('id');

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/ajustes-cotizacion/{$ajusteId}/responder", ['accion' => 'rechazar'])->assertOk();

        Sanctum::actingAs($this->tecnico);
        $this->postJson("/api/ajustes-cotizacion/{$ajusteId}/decidir", ['decision' => 'cancelar'])
            ->assertOk()->assertJsonPath('cancelado', true);

        $orden = DB::table('work_orders')->first();
        $this->assertSame('Cancelado_Tecnico', $orden->status);
        $this->assertNull($orden->tecnico_id);
        $this->assertSame('rejected', DB::table('network_quotes')->value('status'));
        // Sin penalización: no se crea el bloqueo de 24 h (la tabla ni siquiera existe en esta prueba)
        $this->assertFalse(Schema::hasTable('technician_cancellations'));
    }

    public function test_el_tecnico_retira_su_ajuste_pendiente(): void
    {
        $this->proponer()->assertOk();
        $ajusteId = DB::table('network_quote_ajustes')->value('id');

        $this->postJson("/api/ajustes-cotizacion/{$ajusteId}/retirar")->assertOk()
            ->assertJsonPath('ajuste_pendiente', null)
            ->assertJsonPath('puede_ajustar', true);
        $this->assertSame('retirado', DB::table('network_quote_ajustes')->value('status'));
    }

    public function test_no_se_ajusta_un_trabajo_terminado_ni_con_el_mismo_precio(): void
    {
        $this->proponer(['precio' => 120])->assertStatus(422);

        DB::table('work_orders')->update(['status' => 'Listo']);
        $this->proponer()->assertStatus(422);
    }

    public function test_fotos_opcionales_se_validan(): void
    {
        $fotos = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 5));
        $this->proponer(['fotos' => $fotos])->assertStatus(422)->assertJsonValidationErrors('fotos');
    }

    public function test_aceptar_o_rechazar_una_cotizacion_de_la_red_solo_el_dueno(): void
    {
        $pendiente = DB::table('network_quotes')->insertGetId([
            'work_order_id' => $this->orden, 'technician_id' => $this->intruso->id, 'price' => 99, 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->intruso);
        $this->postJson("/api/network-quotes/{$pendiente}/accept")->assertStatus(403);
        $this->postJson("/api/network-quotes/{$pendiente}/reject")->assertStatus(403);
        $this->assertSame('pending', DB::table('network_quotes')->where('id', $pendiente)->value('status'));

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/network-quotes/{$pendiente}/reject")->assertOk();
        $this->assertSame('rejected', DB::table('network_quotes')->where('id', $pendiente)->value('status'));
    }
}
