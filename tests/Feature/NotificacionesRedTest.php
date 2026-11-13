<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Red: las notificaciones del flujo cliente ↔ técnico solo le llegan al cliente dueño del trabajo
 * y al técnico involucrado. Nunca a Root, a los Admin, a otros clientes del mismo tenant ni a otros técnicos.
 * (Antes, varias rutas avisaban a todo el tenant con roles 0 y 1, o al primer usuario del tenant.)
 */
class NotificacionesRedTest extends TestCase
{
    private User $root;
    private User $admin;
    private User $cliente;
    private User $otroCliente;
    private User $tecnico;
    private User $otroTecnico;
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
        foreach ([
            '2026_11_04_100000_create_access_qrs_tables.php',
            '2026_11_13_100000_create_network_quote_ajustes_table.php',
            '2026_11_13_110000_cancelacion_cliente_y_cotizaciones_vigentes.php',
            '2026_11_13_120000_add_codigo_to_access_qrs.php',
        ] as $migracion) {
            (require database_path("migrations/{$migracion}"))->up();
        }

        // Mismo tenant (15) que el cliente: así se comprobaba la fuga hacia Admin y otros clientes
        $this->root = $this->usuario(0, null, 'root@agente.mx');
        $this->admin = $this->usuario(1, 15, 'admin@agente.mx');
        $this->cliente = $this->usuario(4, 15, 'cliente@red.mx');
        $this->otroCliente = $this->usuario(4, 15, 'vecino@red.mx');
        $this->tecnico = $this->usuario(6, null, 'tecnico@red.mx');
        $this->otroTecnico = $this->usuario(6, null, 'otro@red.mx');

        $clienteId = DB::table('clients')->insertGetId(['user_id' => $this->cliente->id, 'email' => 'cliente@red.mx']);
        $propiedad = DB::table('properties')->insertGetId(['tenant_id' => 15, 'property_name' => 'Casa', 'address' => 'Centro', 'client_id' => $clienteId]);
        $this->orden = DB::table('work_orders')->insertGetId([
            'tenant_id' => 15, 'property_id' => $propiedad, 'type' => 'Mantenimiento', 'status' => 'Por Hacer', 'publish_network' => 1,
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
            $t->string('nombre_propiedad')->nullable();
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
            $t->text('description')->nullable();
            $t->string('status')->default('Por Hacer');
            $t->boolean('publish_network')->default(0);
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->unsignedBigInteger('tecnico_dirigido_id')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->string('arrival_status', 30)->default('PENDIENTE');
            $t->timestamp('arrived_at')->nullable();
            $t->boolean('cancelled_by_tech')->default(false);
            $t->unsignedBigInteger('cancelled_technician_id')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
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
            $t->text('message')->nullable();
            $t->string('status')->default('pending');
            $t->json('chat_history')->nullable();
            $t->timestamps();
        });
        Schema::create('client_technician_relations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('technician_id')->nullable();
            $t->unsignedBigInteger('client_user_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('status')->nullable();
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
        Schema::create('technician_cancellations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('technician_id');
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->unsignedBigInteger('client_user_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('expires_at')->nullable();
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

    private function usuario(int $rol, ?int $tenant, string $email): User
    {
        $id = DB::table('users')->insertGetId(['role_id' => $rol, 'tenant_id' => $tenant, 'email' => $email, 'first_name' => 'U' . $rol]);
        return User::withoutGlobalScopes()->find($id);
    }

    /** Nadie fuera del cliente y el técnico recibió nada. */
    private function nadieMas(): void
    {
        foreach ([$this->root, $this->admin, $this->otroCliente, $this->otroTecnico] as $ajeno) {
            Notification::assertNothingSentTo($ajeno);
        }
    }

    private function cotizarYAceptar(): int
    {
        Sanctum::actingAs($this->tecnico);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cotizar", ['price' => 120, 'message' => 'Lo reviso hoy'])->assertOk();
        $quoteId = (int) DB::table('network_quotes')->value('id');

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/network-quotes/{$quoteId}/accept")->assertOk();
        return $quoteId;
    }

    public function test_cotizar_y_aceptar_solo_avisa_al_cliente_y_al_tecnico(): void
    {
        $quoteId = $this->cotizarYAceptar();

        Notification::assertSentTo($this->cliente, \App\Notifications\NetworkQuoteReceived::class);
        Notification::assertSentTo($this->tecnico, \App\Notifications\NetworkQuoteAccepted::class);
        Notification::assertSentTo($this->cliente, \App\Notifications\NetworkQuoteAcceptedClient::class);

        // Chat de la cotización, en ambos sentidos
        Sanctum::actingAs($this->tecnico);
        $this->postJson("/api/network-quotes/{$quoteId}/chat", ['message' => 'Voy en camino'])->assertOk();
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/network-quotes/{$quoteId}/chat", ['message' => 'Gracias'])->assertOk();
        Notification::assertSentTo($this->cliente, \App\Notifications\NewNetworkQuoteChatMessageNotification::class);
        Notification::assertSentTo($this->tecnico, \App\Notifications\NewNetworkQuoteChatMessageNotification::class);

        $this->nadieMas();
    }

    public function test_programar_visita_solo_avisa_al_cliente(): void
    {
        $this->cotizarYAceptar();
        Notification::fake();

        Sanctum::actingAs($this->tecnico);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/programar-visita", ['scheduled_at' => now()->addDay()->toDateTimeString()])->assertOk();

        Notification::assertSentTo($this->cliente, \App\Notifications\WorkOrderScheduledNotification::class);
        Notification::assertNothingSentTo($this->tecnico);
        $this->nadieMas();
    }

    public function test_ajuste_de_cotizacion_solo_entre_cliente_y_tecnico(): void
    {
        $this->cotizarYAceptar();
        Notification::fake();

        Sanctum::actingAs($this->tecnico);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/ajustes", ['precio' => 850, 'motivo' => 'El compresor está dañado.'])->assertOk();
        Notification::assertSentTo($this->cliente, \App\Notifications\MovimientoNotificacion::class);

        Sanctum::actingAs($this->cliente);
        $ajusteId = (int) DB::table('network_quote_ajustes')->value('id');
        $this->postJson("/api/ajustes-cotizacion/{$ajusteId}/responder", ['accion' => 'aceptar'])->assertOk();
        Notification::assertSentTo($this->tecnico, \App\Notifications\MovimientoNotificacion::class);

        $this->nadieMas();
    }

    public function test_acceso_a_la_propiedad_solo_avisa_al_tecnico(): void
    {
        $this->cotizarYAceptar();
        Notification::fake();

        Sanctum::actingAs($this->cliente);
        $this->post("/api/red/trabajos/{$this->orden}/acceso", [
            'slot' => 1, 'codigo' => '4821', 'valid_until' => now()->addDay()->toDateTimeString(),
        ], ['Accept' => 'application/json'])->assertOk();

        Notification::assertSentTo($this->tecnico, \App\Notifications\MovimientoNotificacion::class);
        Notification::assertNothingSentTo($this->cliente);
        $this->nadieMas();
    }

    public function test_cancelacion_del_cliente_solo_avisa_al_tecnico(): void
    {
        $this->cotizarYAceptar();
        Notification::fake();

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'Ya no lo necesito'])->assertOk();

        Notification::assertSentTo($this->tecnico, \App\Notifications\MovimientoNotificacion::class);
        Notification::assertNothingSentTo($this->cliente);
        $this->nadieMas();
    }

    public function test_cancelacion_del_tecnico_solo_avisa_al_cliente(): void
    {
        $this->cotizarYAceptar();
        Notification::fake();

        Sanctum::actingAs($this->tecnico);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-tecnico", ['reason' => 'Se me ponchó la llanta'])->assertOk();

        Notification::assertSentTo($this->cliente, \App\Notifications\WorkOrderCancelledNotification::class);
        Notification::assertNothingSentTo($this->tecnico);
        $this->nadieMas();
    }

    public function test_pedir_confirmacion_de_cotizacion_anterior_solo_avisa_a_ese_tecnico(): void
    {
        // Cotizaciones de dos técnicos; acepta la del primero y luego cancela y reenvía
        Sanctum::actingAs($this->otroTecnico);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cotizar", ['price' => 150])->assertOk();
        $deOtro = (int) DB::table('network_quotes')->where('technician_id', $this->otroTecnico->id)->value('id');
        DB::table('network_quotes')->where('id', $deOtro)->update(['cotizada_at' => now()->subDay()]);
        $this->cotizarYAceptar();

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cancelar-cliente", ['motivo' => 'Cambio de planes'])->assertOk();
        $this->postJson("/api/client/resolve-cancelled-work-order/{$this->orden}", ['action' => 'reopen'])->assertOk();

        Notification::fake();
        $this->postJson("/api/network-quotes/{$deOtro}/accept")->assertOk()->assertJsonPath('requiere_confirmacion', true);

        Notification::assertSentTo($this->otroTecnico, \App\Notifications\MovimientoNotificacion::class);
        foreach ([$this->root, $this->admin, $this->otroCliente, $this->tecnico, $this->cliente] as $ajeno) {
            Notification::assertNothingSentTo($ajeno);
        }
    }
}
