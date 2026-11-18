<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Propiedad compartida (property_shares): el invitado la usa como dueño aunque sea de otro tenant.
 * Ve sus órdenes, opera las cotizaciones y recibe los mismos avisos que el dueño.
 * Solo el dueño real borra, edita o comparte la propiedad. Un extraño no ve nada.
 */
class PropiedadCompartidaTest extends TestCase
{
    private User $duena;
    private User $invitado;
    private User $extrano;
    private User $tecnico;
    private int $propiedad;
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
        \App\Services\AccesoPropiedad::limpiarCache();
        $this->crearEsquema();
        foreach ([
            '2026_11_04_100000_create_access_qrs_tables.php',
            '2026_11_13_100000_create_network_quote_ajustes_table.php',
            '2026_11_13_110000_cancelacion_cliente_y_cotizaciones_vigentes.php',
            '2026_11_13_120000_add_codigo_to_access_qrs.php',
        ] as $migracion) {
            (require database_path("migrations/{$migracion}"))->up();
        }

        // Como en producción: la dueña (tenant 19) le comparte la casa a un cliente de otro tenant (6)
        $this->duena = $this->usuario(4, 19, 'duena@red.mx');
        $this->invitado = $this->usuario(4, 6, 'invitado@red.mx');
        $this->extrano = $this->usuario(4, 7, 'extrano@red.mx');
        $this->tecnico = $this->usuario(6, null, 'tecnico@red.mx');

        $clienteDuena = DB::table('clients')->insertGetId(['user_id' => $this->duena->id, 'email' => 'duena@red.mx', 'tenant_id' => 19]);
        $clienteInvitado = DB::table('clients')->insertGetId(['user_id' => $this->invitado->id, 'email' => 'invitado@red.mx', 'tenant_id' => 6]);
        DB::table('clients')->insert(['user_id' => $this->extrano->id, 'email' => 'extrano@red.mx', 'tenant_id' => 7]);

        $this->propiedad = DB::table('properties')->insertGetId(['tenant_id' => 19, 'property_name' => 'Casa compartida', 'address' => 'Norte', 'client_id' => $clienteDuena]);
        DB::table('property_shares')->insert(['property_id' => $this->propiedad, 'client_id' => $clienteInvitado]);
        $this->orden = DB::table('work_orders')->insertGetId([
            'tenant_id' => 19, 'property_id' => $this->propiedad, 'type' => 'Mantenimiento', 'status' => 'Por Hacer', 'publish_network' => 1,
        ]);
    }

    // Mismo esquema mínimo que NotificacionesRedTest, más property_shares
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
            $t->string('name')->nullable();
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
        Schema::create('property_shares', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id');
            $t->unsignedBigInteger('client_id');
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
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('status')->nullable();
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

    private function como(User $user): void
    {
        \App\Services\AccesoPropiedad::limpiarCache();
        Sanctum::actingAs($user);
    }

    private function cotizar(): int
    {
        $this->como($this->tecnico);
        $this->postJson("/api/mercado-trabajos/{$this->orden}/cotizar", ['price' => 120, 'message' => 'Lo reviso hoy'])->assertOk();
        return (int) DB::table('network_quotes')->value('id');
    }

    /** Ids de "mis trabajos" de la red para el usuario actual */
    private function misTrabajos(): array
    {
        $json = $this->getJson('/api/mercado-trabajos?only_mine=1')->assertOk()->json();
        $lista = $json['data'] ?? $json['jobs'] ?? $json;
        return collect($lista)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_el_invitado_ve_la_propiedad_y_sus_ordenes_aunque_sea_de_otro_tenant(): void
    {
        $this->como($this->invitado);
        $this->assertNotNull(Property::find($this->propiedad), 'El TenantScope debe dejar ver la propiedad compartida');
        $this->assertNotNull(WorkOrder::find($this->orden), 'El TenantScope debe dejar ver la orden de la propiedad compartida');
        $this->assertContains($this->orden, $this->misTrabajos(), 'El invitado debe ver la orden en "mis trabajos"');
    }

    public function test_un_extrano_no_ve_nada(): void
    {
        $this->como($this->extrano);
        $this->assertNull(Property::find($this->propiedad));
        $this->assertNull(WorkOrder::find($this->orden));
        $this->assertNotContains($this->orden, $this->misTrabajos());
    }

    public function test_la_cotizacion_nueva_avisa_a_la_duena_y_al_invitado(): void
    {
        $this->cotizar();

        Notification::assertSentTo($this->duena, \App\Notifications\NetworkQuoteReceived::class);
        Notification::assertSentTo($this->invitado, \App\Notifications\NetworkQuoteReceived::class);
        Notification::assertNothingSentTo($this->extrano);
    }

    public function test_el_invitado_acepta_la_cotizacion_como_dueno_y_ambos_reciben_el_aviso(): void
    {
        $quoteId = $this->cotizar();
        Notification::fake();

        $this->como($this->invitado);
        $this->postJson("/api/network-quotes/{$quoteId}/accept")->assertOk();

        Notification::assertSentTo($this->tecnico, \App\Notifications\NetworkQuoteAccepted::class);
        Notification::assertSentTo($this->duena, \App\Notifications\NetworkQuoteAcceptedClient::class);
        Notification::assertSentTo($this->invitado, \App\Notifications\NetworkQuoteAcceptedClient::class);
        Notification::assertNothingSentTo($this->extrano);
    }

    public function test_un_extrano_no_puede_aceptar_la_cotizacion(): void
    {
        $quoteId = $this->cotizar();

        $this->como($this->extrano);
        $estado = $this->postJson("/api/network-quotes/{$quoteId}/accept")->status();
        $this->assertContains($estado, [403, 404]);
        $this->assertSame('pending', DB::table('network_quotes')->where('id', $quoteId)->value('status'));
    }

    public function test_el_chat_del_tecnico_avisa_a_la_duena_y_al_invitado(): void
    {
        $quoteId = $this->cotizar();
        Notification::fake();

        $this->como($this->tecnico);
        $this->postJson("/api/network-quotes/{$quoteId}/chat", ['message' => 'Voy en camino'])->assertOk();

        Notification::assertSentTo($this->duena, \App\Notifications\NewNetworkQuoteChatMessageNotification::class);
        Notification::assertSentTo($this->invitado, \App\Notifications\NewNetworkQuoteChatMessageNotification::class);
        Notification::assertNothingSentTo($this->extrano);
    }

    public function test_un_extrano_no_lee_ni_escribe_el_chat_de_la_cotizacion(): void
    {
        $quoteId = $this->cotizar();

        $this->como($this->extrano);
        $this->getJson("/api/network-quotes/{$quoteId}/chat")->assertStatus(404);
        $this->postJson("/api/network-quotes/{$quoteId}/chat", ['message' => 'Hola'])->assertStatus(404);

        // El invitado sí, como dueño
        $this->como($this->invitado);
        $this->getJson("/api/network-quotes/{$quoteId}/chat")->assertOk();
    }

    public function test_un_extrano_no_puede_pedir_trabajos_para_la_propiedad(): void
    {
        $this->como($this->extrano);
        $this->postJson('/api/work-orders/cliente', [
            'property_id' => $this->propiedad, 'type' => 'Mantenimiento', 'zone' => 'Cocina', 'description' => 'Fuga',
        ])->assertStatus(403);
        $this->assertSame(1, DB::table('work_orders')->count());
    }

    public function test_mis_trabajos_no_incluye_clientes_con_nombre_parecido(): void
    {
        // Otro cliente que se llama como el primer nombre del extraño ("U4")
        $otroCliente = DB::table('clients')->insertGetId(['user_id' => null, 'email' => 'otro@red.mx', 'name' => 'U4 Pérez', 'tenant_id' => 30]);
        $otraCasa = DB::table('properties')->insertGetId(['tenant_id' => 30, 'property_name' => 'Ajena', 'address' => 'Sur', 'client_id' => $otroCliente]);
        $ordenAjena = DB::table('work_orders')->insertGetId([
            'tenant_id' => 30, 'property_id' => $otraCasa, 'type' => 'Pintura', 'status' => 'Por Hacer', 'publish_network' => 1,
        ]);

        $this->como($this->extrano);
        $this->assertNotContains($ordenAjena, $this->misTrabajos());
    }

    public function test_el_invitado_no_puede_borrar_la_propiedad(): void
    {
        $this->como($this->invitado);
        $this->deleteJson("/api/propiedades/{$this->propiedad}")->assertStatus(403);
        $this->assertNotNull(DB::table('properties')->where('id', $this->propiedad)->first());
    }

    public function test_el_invitado_no_puede_compartirla_ni_quitar_invitados(): void
    {
        $this->como($this->invitado);
        $this->postJson("/api/propiedades/{$this->propiedad}/share", ['email' => 'extrano@red.mx'])->assertStatus(403);

        $clienteInvitado = (int) DB::table('clients')->where('user_id', $this->invitado->id)->value('id');
        $this->deleteJson("/api/propiedades/{$this->propiedad}/share/{$clienteInvitado}")->assertStatus(403);
        $this->assertSame(1, DB::table('property_shares')->count());
    }
}
