<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Red: el cliente cancela un trabajo asignado, lo reenvía a la red y elige una cotización anterior
// (el técnico confirma que sigue disponible); el técnico puede retirar su cotización.
// Esquema mínimo en SQLite en memoria + las migraciones nuevas, como el resto de pruebas.
class RedCancelacionTest extends TestCase
{
    private User $cliente;
    private User $tecnico;
    private User $otroTecnico;
    private int $orden;
    private int $aceptada;
    private int $otra;

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
        (require database_path('migrations/2026_11_13_110000_cancelacion_cliente_y_cotizaciones_vigentes.php'))->up();

        $this->cliente = $this->usuario(4, 'cliente@red.mx');
        $this->tecnico = $this->usuario(6, 'tecnico@red.mx');
        $this->otroTecnico = $this->usuario(6, 'otro@red.mx');

        $clienteId = DB::table('clients')->insertGetId(['user_id' => $this->cliente->id, 'email' => 'cliente@red.mx']);
        $propiedad = DB::table('properties')->insertGetId(['property_name' => 'Casa', 'address' => 'Centro', 'client_id' => $clienteId]);
        $this->orden = DB::table('work_orders')->insertGetId([
            'property_id' => $propiedad, 'type' => 'Mantenimiento', 'status' => 'Asignado', 'tecnico_id' => $this->tecnico->id,
        ]);
        $hace2dias = now()->subDays(2);
        $this->aceptada = DB::table('network_quotes')->insertGetId([
            'work_order_id' => $this->orden, 'technician_id' => $this->tecnico->id, 'price' => 120, 'status' => 'accepted', 'created_at' => $hace2dias,
        ]);
        $this->otra = DB::table('network_quotes')->insertGetId([
            'work_order_id' => $this->orden, 'technician_id' => $this->otroTecnico->id, 'price' => 150, 'status' => 'pending', 'created_at' => $hace2dias,
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
            $t->decimal('client_trato_avg', 3, 2)->nullable();
            $t->decimal('client_pago_avg', 3, 2)->nullable();
            $t->unsignedInteger('client_reviews_count')->default(0);
            $t->timestamps();
        });
        Schema::create('client_reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('technician_id');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedTinyInteger('rating_trato');
            $t->unsignedTinyInteger('rating_pago');
            $t->text('comment')->nullable();
            $t->timestamps();
        });
        Schema::create('technician_reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('technician_id');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('work_order_id')->nullable();
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
            $t->boolean('publish_network')->default(1);
            $t->timestamp('scheduled_at')->nullable();
            $t->unsignedBigInteger('tecnico_dirigido_id')->nullable();
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
        Schema::create('technician_cancellations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('technician_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->boolean('rated')->default(false);
            $t->timestamps();
        });
        Schema::create('work_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id')->nullable();
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

    private function reenviarALaRed(): void
    {
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'Cambio de planes'])->assertOk();
        $this->postJson("/api/client/resolve-cancelled-work-order/{$this->orden}", ['action' => 'reopen'])->assertOk();
    }

    public function test_el_cliente_cancela_antes_de_comenzar_y_reenvia_a_la_red(): void
    {
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'Ya no lo necesito'])->assertOk();

        $orden = DB::table('work_orders')->first();
        $this->assertSame('Cancelado_Cliente', $orden->status);
        $this->assertNull($orden->tecnico_id);
        $this->assertEquals(1, $orden->cancelled_by_client);
        $this->assertSame('rejected', DB::table('network_quotes')->where('id', $this->aceptada)->value('status'));
        $this->assertSame('pending', DB::table('network_quotes')->where('id', $this->otra)->value('status'));

        $this->postJson("/api/client/resolve-cancelled-work-order/{$this->orden}", ['action' => 'reopen'])->assertOk();
        $orden = DB::table('work_orders')->first();
        $this->assertSame('Por Hacer', $orden->status);
        $this->assertNotNull($orden->reabierta_at);
    }

    public function test_no_se_acepta_una_cotizacion_mientras_esta_cancelado(): void
    {
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'Ya no lo necesito'])->assertOk();
        $this->postJson("/api/network-quotes/{$this->otra}/accept")->assertStatus(422);
        $this->assertSame('Cancelado_Cliente', DB::table('work_orders')->value('status'));
    }

    public function test_el_cliente_cancela_por_completo_sin_borrar(): void
    {
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'Ya no lo necesito'])->assertOk();
        $this->postJson("/api/client/resolve-cancelled-work-order/{$this->orden}", ['action' => 'delete'])->assertOk();

        $this->assertSame('Cancelado', DB::table('work_orders')->value('status'));
        $this->assertSame(2, DB::table('network_quotes')->count());
    }

    public function test_no_cancela_si_ya_comenzo_ni_si_no_es_el_dueno(): void
    {
        Sanctum::actingAs($this->otroTecnico);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'No me gusta'])->assertStatus(403);
        $this->postJson("/api/client/resolve-cancelled-work-order/{$this->orden}", ['action' => 'reopen'])->assertStatus(403);

        DB::table('work_reports')->insert(['work_order_id' => $this->orden]);
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'Ya no lo necesito'])->assertStatus(422);
        $this->assertSame('Asignado', DB::table('work_orders')->value('status'));
    }

    public function test_cotizacion_anterior_pide_confirmacion_y_el_tecnico_confirma(): void
    {
        $this->reenviarALaRed();

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/network-quotes/{$this->otra}/accept")->assertOk()->assertJsonPath('requiere_confirmacion', true);
        $this->assertSame('Por Hacer', DB::table('work_orders')->value('status'));
        $this->assertNotNull(DB::table('network_quotes')->where('id', $this->otra)->value('confirmacion_pedida_at'));

        Sanctum::actingAs($this->otroTecnico);
        $this->postJson("/api/network-quotes/{$this->otra}/confirmar", ['accion' => 'confirmar'])->assertOk();

        $orden = DB::table('work_orders')->first();
        $this->assertSame('Asignado', $orden->status);
        $this->assertEquals($this->otroTecnico->id, $orden->tecnico_id);
        $this->assertSame('accepted', DB::table('network_quotes')->where('id', $this->otra)->value('status'));
    }

    public function test_el_tecnico_declina_y_su_cotizacion_queda_retirada(): void
    {
        $this->reenviarALaRed();
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/network-quotes/{$this->otra}/accept")->assertOk();

        Sanctum::actingAs($this->otroTecnico);
        $this->postJson("/api/network-quotes/{$this->otra}/confirmar", ['accion' => 'declinar'])->assertOk();

        $cot = DB::table('network_quotes')->where('id', $this->otra)->first();
        $this->assertSame('rejected', $cot->status);
        $this->assertNotNull($cot->retirada_at);

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/network-quotes/{$this->otra}/accept")->assertStatus(422);
    }

    public function test_una_cotizacion_nueva_tras_el_reenvio_se_asigna_directo(): void
    {
        $this->reenviarALaRed();
        $nueva = DB::table('network_quotes')->insertGetId([
            'work_order_id' => $this->orden, 'technician_id' => $this->tecnico->id + 100, 'price' => 200,
            'status' => 'pending', 'created_at' => now()->addMinute(),
        ]);

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/network-quotes/{$nueva}/accept")->assertOk()->assertJsonMissingPath('requiere_confirmacion');
        $this->assertSame('Asignado', DB::table('work_orders')->value('status'));
    }

    public function test_el_tecnico_retira_su_cotizacion_pendiente(): void
    {
        Sanctum::actingAs($this->otroTecnico);
        $this->postJson("/api/network-quotes/{$this->otra}/retirar")->assertOk();
        $this->assertNotNull(DB::table('network_quotes')->where('id', $this->otra)->value('retirada_at'));

        // Una aceptada no se retira, ni una ajena
        Sanctum::actingAs($this->tecnico);
        $this->postJson("/api/network-quotes/{$this->aceptada}/retirar")->assertStatus(422);
        $this->postJson("/api/network-quotes/{$this->otra}/retirar")->assertStatus(404);
    }

    public function test_solo_una_confirmacion_a_la_vez(): void
    {
        $this->reenviarALaRed();
        $tercera = DB::table('network_quotes')->insertGetId([
            'work_order_id' => $this->orden, 'technician_id' => $this->tecnico->id + 200, 'price' => 180,
            'status' => 'pending', 'created_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/network-quotes/{$this->otra}/accept")->assertOk();
        $this->postJson("/api/network-quotes/{$tercera}/accept")->assertStatus(422);

        $this->postJson("/api/network-quotes/{$this->otra}/cancelar-confirmacion")->assertOk();
        $this->postJson("/api/network-quotes/{$tercera}/accept")->assertOk()->assertJsonPath('requiere_confirmacion', true);
    }

    public function test_si_el_cliente_cancela_el_tecnico_debe_calificarlo(): void
    {
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'Ya no lo necesito'])->assertOk();

        Sanctum::actingAs($this->tecnico);
        $this->getJson('/api/tecnico/calificaciones-cliente/pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.work_order_id', $this->orden)
            ->assertJsonPath('data.0.cancelacion', true)
            ->assertJsonPath('data.0.motivo', 'Ya no lo necesito');

        $this->postJson("/api/tecnico/calificaciones-cliente/{$this->orden}", ['rating_trato' => 2, 'rating_pago' => 1])->assertOk();
        $this->getJson('/api/tecnico/calificaciones-cliente/pendientes')->assertJsonCount(0, 'data');
        $this->assertEquals(1, DB::table('users')->where('id', $this->cliente->id)->value('client_reviews_count'));

        // Otro técnico no puede calificar esa cancelación
        Sanctum::actingAs($this->otroTecnico);
        $this->postJson("/api/tecnico/calificaciones-cliente/{$this->orden}", ['rating_trato' => 5, 'rating_pago' => 5])->assertStatus(403);
    }

    public function test_si_el_tecnico_cancela_el_cliente_califica_antes_de_reenviar(): void
    {
        DB::table('work_orders')->update(['status' => 'Cancelado_Tecnico', 'tecnico_id' => null, 'cancelled_by_tech' => true]);
        DB::table('technician_cancellations')->insert(['technician_id' => $this->tecnico->id, 'work_order_id' => $this->orden, 'rated' => false]);

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/client/resolve-cancelled-work-order/{$this->orden}", ['action' => 'reopen'])
            ->assertStatus(422)->assertJsonPath('reason', 'calificacion_pendiente');
        $this->postJson("/api/mercado-trabajos/{$this->orden}/reabrir-red")->assertStatus(422);

        // Descartar el aviso no salta la calificación
        $this->postJson("/api/client/dismiss-cancellation/{$this->orden}")->assertOk();
        $this->assertEquals(0, DB::table('technician_cancellations')->value('rated'));

        DB::table('technician_reviews')->insert(['technician_id' => $this->tecnico->id, 'client_id' => $this->cliente->id, 'work_order_id' => $this->orden]);
        $this->postJson("/api/client/resolve-cancelled-work-order/{$this->orden}", ['action' => 'delete'])->assertOk();
        $this->assertSame('Cancelado', DB::table('work_orders')->value('status'));
        $this->assertEquals(1, DB::table('technician_cancellations')->value('rated'));
    }
}
