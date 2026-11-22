<?php

namespace Tests\Feature;

use App\Http\Controllers\AccesoQrRedController;
use App\Http\Controllers\RedCancelacionController;
use App\Models\NetworkQuote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use App\Services\Cuadrilla;
use App\Services\PerfilProfesional;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Etapas 2 a 5 de la cuadrilla:
// 2) el técnico de cuadrilla (8) no ve la red y su perfil no pide cobro;
// 3) el contratista decide quién de su cuadrilla va a cada trabajo;
// 4) el cliente ve quién va a su casa;
// 5) el técnico 9 (cuadrilla + red), quitar, unirse con código y los cambios de rol de Root.
class CuadrillaFlujoTest extends TestCase
{
    private int $tenantJefe;
    private User $root;
    private User $jefe;
    private User $ana;   // técnica de cuadrilla (8)
    private User $beto;  // técnico de cuadrilla con red (9)
    private User $cliente;
    private int $propiedad;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        self::olvidarTablaPerfiles();
        Notification::fake();
        $this->crearEsquema();

        $this->root = $this->usuario(0, null, 'Root');
        $this->tenantJefe = DB::table('tenants')->insertGetId(['name' => 'Cuadrilla López', 'code' => 'AUT_C_7', 'membership_type' => 'market_contractor']);
        $this->jefe = $this->usuario(7, $this->tenantJefe, 'Jefe');
        DB::table('tenants')->where('id', $this->tenantJefe)->update(['owner_user_id' => $this->jefe->id]);
        $this->ana = $this->usuario(8, $this->tenantJefe, 'Ana');
        $this->beto = $this->usuario(9, $this->tenantJefe, 'Beto');
        foreach ([$this->jefe, $this->ana, $this->beto] as $t) {
            $this->validar($t);
        }

        $this->cliente = $this->usuario(4, null, 'Clara');
        $clienteId = DB::table('clients')->insertGetId(['user_id' => $this->cliente->id, 'email' => 'clara@red.mx', 'phone' => '9991234567', 'name' => 'Clara Prueba']);
        $this->propiedad = DB::table('properties')->insertGetId(['client_id' => $clienteId, 'property_name' => 'Casa Clara', 'address' => 'Calle 10']);
    }

    protected function tearDown(): void
    {
        self::olvidarTablaPerfiles();
        parent::tearDown();
    }

    private static function olvidarTablaPerfiles(): void
    {
        \Closure::bind(fn () => PerfilProfesional::$tablaLista = null, null, PerfilProfesional::class)();
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
            $t->string('phone_number')->nullable();
            $t->string('password')->nullable();
            $t->string('profile_picture')->nullable();
            $t->date('birth_date')->nullable();
            $t->boolean('is_active')->default(1);
            $t->string('approval_status', 50)->default('approved');
            $t->decimal('rating_stars_avg', 3, 1)->nullable();
            $t->decimal('rating_time_avg', 3, 1)->nullable();
            $t->integer('total_reviews_count')->default(0);
            $t->decimal('client_trato_avg', 3, 1)->nullable();
            $t->decimal('client_pago_avg', 3, 1)->nullable();
            $t->unsignedInteger('client_reviews_count')->default(0);
            $t->string('subscription_status')->nullable();
            $t->timestamp('subscription_start')->nullable();
            $t->timestamp('subscription_expires_at')->nullable();
            $t->decimal('subscription_amount', 8, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->string('membership_type')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_11_21_100000_add_max_cuadrilla_to_tenants.php'))->up();
        (require database_path('migrations/2026_11_21_110000_create_solicitudes_cuadrilla_table.php'))->up();
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
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
            $t->unsignedBigInteger('property_id')->nullable();
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
        Schema::create('work_order_technician', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
            $t->timestamps();
        });
        Schema::create('network_quotes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
            $t->decimal('price', 10, 2)->default(0);
            $t->string('status')->default('pending');
            $t->text('chat_history')->nullable();
            $t->timestamp('retirada_at')->nullable();
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
            $t->unsignedBigInteger('technician_id')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
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
            $t->string('set_by')->nullable();
            $t->timestamps();
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
        (require database_path('migrations/2026_11_06_100000_create_technician_professional_profiles.php'))->up();
        (require database_path('migrations/2026_11_09_120000_add_documentos_to_technician_profiles.php'))->up();
    }

    private function usuario(int $rol, ?int $tenant, string $nombre, string $estado = 'approved', int $activo = 1): User
    {
        $id = DB::table('users')->insertGetId([
            'role_id' => $rol, 'tenant_id' => $tenant, 'first_name' => $nombre, 'last_name' => 'Prueba',
            'email' => strtolower($nombre) . '@red.mx', 'phone_number' => '99900' . random_int(10000, 99999),
            'approval_status' => $estado, 'is_active' => $activo,
        ]);
        return User::withoutGlobalScopes()->find($id);
    }

    private function validar(User $tecnico, array $extra = []): void
    {
        DB::table('technician_profiles')->updateOrInsert(['user_id' => $tecnico->id], array_merge([
            'obligatorio' => true, 'estado' => 'aprobado', 'aprobado_at' => now(),
        ], $extra));
    }

    private function publicada(array $extra = []): int
    {
        return DB::table('work_orders')->insertGetId(array_merge([
            'property_id' => $this->propiedad, 'type' => 'Fuga', 'equipment' => 'Tubería', 'status' => 'Por Hacer', 'publish_network' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    /** Orden que ganó el contratista: titular él, con su cotización aceptada y él en el equipo. */
    private function ordenDelJefe(array $extra = []): int
    {
        $id = $this->publicada(array_merge(['status' => 'Asignado', 'tecnico_id' => $this->jefe->id, 'scheduled_at' => '2026-12-01 10:00:00'], $extra));
        DB::table('network_quotes')->insert(['work_order_id' => $id, 'technician_id' => $this->jefe->id, 'price' => 1200, 'status' => 'accepted']);
        DB::table('work_order_technician')->insert(['work_order_id' => $id, 'technician_id' => $this->jefe->id]);
        return $id;
    }

    private function idsDelMercado(User $quien): array
    {
        Sanctum::actingAs($quien);
        return collect($this->getJson('/api/mercado-trabajos')->assertOk()->json('data'))->pluck('id')->all();
    }

    // ───────────────────────── Etapa 2: el técnico de cuadrilla solo recibe ─────────────────────────

    public function test_el_tecnico_de_cuadrilla_no_ve_la_red_ni_cotiza_ni_chatea(): void
    {
        $orden = $this->publicada();

        $this->assertSame([], $this->idsDelMercado($this->ana));
        $this->postJson("/api/mercado-trabajos/{$orden}/cotizar", ['price' => 900])->assertForbidden();
        $this->postJson("/api/mercado-trabajos/{$orden}/iniciar-chat")->assertForbidden();

        // El contratista sí ve la red
        $this->assertContains($orden, $this->idsDelMercado($this->jefe));
    }

    public function test_el_9_ve_la_red_pero_no_compite_con_su_contratista(): void
    {
        $cotizadaPorJefe = $this->publicada();
        $libre = $this->publicada();
        DB::table('network_quotes')->insert(['work_order_id' => $cotizadaPorJefe, 'technician_id' => $this->jefe->id, 'price' => 700]);

        $ids = $this->idsDelMercado($this->beto);
        $this->assertContains($libre, $ids);
        $this->assertNotContains($cotizadaPorJefe, $ids);

        $this->postJson("/api/mercado-trabajos/{$cotizadaPorJefe}/cotizar", ['price' => 650])->assertForbidden();
        // Sin datos de cobro todavía no cotiza por su cuenta
        $this->postJson("/api/mercado-trabajos/{$libre}/cotizar", ['price' => 650])
            ->assertForbidden()->assertJsonPath('reason', 'perfil_cobro_pendiente');
    }

    public function test_el_contratista_ve_que_su_tecnico_ya_cotizo(): void
    {
        $orden = $this->publicada();
        DB::table('network_quotes')->insert(['work_order_id' => $orden, 'technician_id' => $this->beto->id, 'price' => 650]);

        Sanctum::actingAs($this->jefe);
        $job = collect($this->getJson('/api/mercado-trabajos')->assertOk()->json('data'))->firstWhere('id', $orden);
        $this->assertSame(['Beto Prueba'], $job['cotizado_por_cuadrilla']);
    }

    public function test_el_perfil_del_8_no_pide_cobro_y_el_del_9_si(): void
    {
        $this->validar($this->ana, ['aprobado_at' => null, 'estado' => 'incompleto']);
        $faltan8 = PerfilProfesional::faltantes(PerfilProfesional::perfilDe($this->ana), $this->ana);
        foreach (['banco', 'clabe', 'titular_cuenta', 'emite_factura', 'comprobante_domicilio', 'presentacion'] as $clave) {
            $this->assertArrayNotHasKey($clave, $faltan8);
        }
        $this->assertArrayHasKey('antecedentes_penales', $faltan8);
        $this->assertArrayHasKey('ine_frente', $faltan8);

        $faltan9 = PerfilProfesional::faltantes(PerfilProfesional::perfilDe($this->beto), $this->beto);
        $this->assertArrayHasKey('clabe', $faltan9);
        $this->assertArrayHasKey('comprobante_domicilio', $faltan9);
    }

    // ───────────────────────── Etapa 3: asignar a mi cuadrilla ─────────────────────────

    public function test_el_contratista_asigna_a_su_cuadrilla_y_el_tecnico_ve_el_trabajo_sin_precio(): void
    {
        $orden = $this->ordenDelJefe();

        Sanctum::actingAs($this->jefe);
        $miembros = collect($this->getJson("/api/mercado-trabajos/{$orden}/cuadrilla")->assertOk()->json('miembros'))->keyBy('id');
        $this->assertTrue($miembros[$this->ana->id]['perfil_listo']);

        $this->postJson("/api/mercado-trabajos/{$orden}/cuadrilla", ['tecnicos' => [$this->ana->id]])
            ->assertOk()->assertJsonPath('equipo', [$this->ana->id]);
        Notification::assertSentTo($this->ana, MovimientoNotificacion::class);

        Sanctum::actingAs($this->ana);
        $res = $this->getJson('/api/mercado-trabajos')->assertOk();
        $job = collect($res->json('accepted_jobs'))->firstWhere('id', $orden);
        $this->assertNotNull($job);
        $this->assertTrue($job['es_cuadrilla']);
        $this->assertEquals(0, $job['agreed_price']);
        $this->assertSame('', $job['client_phone']);
        $this->assertNull($job['myQuote']);
        $this->assertSame('Jefe Prueba', $job['contratista']['nombre']);
        $this->assertFalse($job['puede_asignar_cuadrilla']);
        $this->assertTrue(AccesoQrRedController::esTecnicoDelTrabajo($this->ana, WorkOrder::withoutGlobalScopes()->find($orden)));

        // El contratista sí ve el precio y puede cambiar a quién manda
        Sanctum::actingAs($this->jefe);
        $suyo = collect($this->getJson('/api/mercado-trabajos')->json('accepted_jobs'))->firstWhere('id', $orden);
        $this->assertEquals(1200, $suyo['agreed_price']);
        $this->assertTrue($suyo['puede_asignar_cuadrilla']);
    }

    public function test_solo_asigna_a_su_cuadrilla_con_perfil_validado_y_en_sus_trabajos(): void
    {
        $orden = $this->ordenDelJefe();
        $otraCuadrilla = $this->usuario(8, DB::table('tenants')->insertGetId(['name' => 'Otra', 'code' => 'OTRA']), 'Dani');
        $this->validar($otraCuadrilla);
        $sinValidar = $this->usuario(8, $this->tenantJefe, 'Eli');

        Sanctum::actingAs($this->jefe);
        $this->postJson("/api/mercado-trabajos/{$orden}/cuadrilla", ['tecnicos' => [$otraCuadrilla->id]])->assertStatus(422);
        $this->postJson("/api/mercado-trabajos/{$orden}/cuadrilla", ['tecnicos' => [$sinValidar->id]])->assertStatus(422);
        $this->postJson("/api/mercado-trabajos/{$orden}/cuadrilla", ['tecnicos' => []])->assertStatus(422);

        // Un trabajo que no es suyo
        $ajena = $this->publicada(['status' => 'Asignado', 'tecnico_id' => $this->beto->id]);
        $this->postJson("/api/mercado-trabajos/{$ajena}/cuadrilla", ['tecnicos' => [$this->ana->id]])->assertForbidden();

        // El técnico de cuadrilla no asigna
        Sanctum::actingAs($this->ana);
        $this->postJson("/api/mercado-trabajos/{$orden}/cuadrilla", ['tecnicos' => [$this->ana->id]])->assertForbidden();
        $this->assertSame([$this->jefe->id], Cuadrilla::equipoDeOrden($orden));
    }

    public function test_el_contratista_puede_ir_el_mismo_con_su_tecnico(): void
    {
        $orden = $this->ordenDelJefe();
        Sanctum::actingAs($this->jefe);
        $this->postJson("/api/mercado-trabajos/{$orden}/cuadrilla", ['tecnicos' => [$this->jefe->id, $this->ana->id]])->assertOk();

        $equipo = Cuadrilla::equipoDeOrden($orden);
        sort($equipo);
        $esperado = [$this->jefe->id, $this->ana->id];
        sort($esperado);
        $this->assertSame($esperado, $equipo);
    }

    public function test_solo_el_titular_cancela_el_trabajo(): void
    {
        $orden = $this->ordenDelJefe();
        DB::table('work_order_technician')->insert(['work_order_id' => $orden, 'technician_id' => $this->ana->id]);
        $extrano = $this->usuario(6, null, 'Extraño');
        $this->validar($extrano);

        Sanctum::actingAs($this->ana);
        $this->postJson("/api/mercado-trabajos/{$orden}/cancelar-tecnico", ['reason' => 'No puedo'])->assertForbidden();
        Sanctum::actingAs($extrano);
        $this->postJson("/api/mercado-trabajos/{$orden}/cancelar-tecnico", ['reason' => 'Porque sí'])->assertForbidden();

        $this->assertSame('Asignado', DB::table('work_orders')->where('id', $orden)->value('status'));
    }

    public function test_los_horarios_ocupados_no_dicen_de_que_cliente_son(): void
    {
        $orden = $this->ordenDelJefe();
        // Trabajo propio del 9 en la red, con otro cliente
        $this->publicada(['status' => 'Asignado', 'tecnico_id' => $this->beto->id, 'scheduled_at' => '2026-12-01 09:00:00']);

        Sanctum::actingAs($this->jefe);
        $res = $this->getJson("/api/mercado-trabajos/{$orden}/cuadrilla")->assertOk();
        $beto = collect($res->json('miembros'))->firstWhere('id', $this->beto->id);
        $this->assertSame(['2026-12-01 09:00'], $beto['ocupado']);
        $this->assertStringNotContainsString('Clara', $res->getContent());
        $this->assertStringNotContainsString('Calle 10', $res->getContent());
    }

    public function test_al_aceptar_otra_cotizacion_el_equipo_anterior_ya_no_va(): void
    {
        $orden = $this->ordenDelJefe(['status' => 'Por Hacer', 'tecnico_id' => null]);
        DB::table('work_order_technician')->insert(['work_order_id' => $orden, 'technician_id' => $this->ana->id]);
        DB::table('network_quotes')->where('work_order_id', $orden)->update(['status' => 'rejected']);
        $nueva = DB::table('network_quotes')->insertGetId(['work_order_id' => $orden, 'technician_id' => $this->beto->id, 'price' => 800]);

        RedCancelacionController::asignarCotizacion(NetworkQuote::withoutGlobalScopes()->find($nueva), $this->cliente);

        $this->assertSame([$this->beto->id], Cuadrilla::equipoDeOrden($orden));
    }

    // ───────────────────────── Etapa 4: el cliente ─────────────────────────

    public function test_el_cliente_ve_quien_va_a_su_casa_sin_telefonos(): void
    {
        $orden = $this->ordenDelJefe();
        DB::table('work_order_technician')->insert(['work_order_id' => $orden, 'technician_id' => $this->ana->id]);

        Sanctum::actingAs($this->cliente);
        $res = $this->getJson('/api/mercado-trabajos?only_mine=1')->assertOk();
        $job = collect($res->json('data'))->firstWhere('id', $orden);
        $nombres = collect($job['equipo_en_sitio'])->pluck('nombre')->all();
        $this->assertSame(['Jefe Prueba', 'Ana Prueba'], $nombres);
        $this->assertTrue($job['equipo_en_sitio'][0]['es_titular']);
        $this->assertStringNotContainsString($this->ana->phone_number, json_encode($job['equipo_en_sitio']));
    }

    // ───────────────────────── Etapa 5: 9, salidas y uniones ─────────────────────────

    public function test_quitar_a_un_9_lo_vuelve_independiente(): void
    {
        Sanctum::actingAs($this->jefe);
        $this->postJson("/api/cuadrilla/u_{$this->beto->id}/quitar")->assertOk()->assertJsonPath('resultado', 'independiente');

        $beto = $this->beto->fresh();
        $this->assertSame(6, (int) $beto->role_id);
        $this->assertNull($beto->tenant_id);
        $this->assertSame(1, (int) $beto->is_active);
        $this->assertSame('approved', $beto->approval_status);
    }

    public function test_los_trabajos_propios_de_un_9_no_impiden_quitarlo(): void
    {
        $this->publicada(['status' => 'En Progreso', 'tecnico_id' => $this->beto->id]);
        Sanctum::actingAs($this->jefe);
        $this->postJson("/api/cuadrilla/u_{$this->beto->id}/quitar")->assertOk();
    }

    public function test_un_independiente_pide_unirse_y_el_contratista_lo_acepta(): void
    {
        $eva = $this->usuario(6, null, 'Eva');
        $this->validar($eva);
        $pendiente = DB::table('network_quotes')->insertGetId(['work_order_id' => $this->publicada(), 'technician_id' => $eva->id, 'price' => 300]);

        Sanctum::actingAs($eva);
        $this->postJson('/api/cuadrilla/unirme', ['codigo' => 'NO_EXISTE'])->assertStatus(422);
        $this->postJson('/api/cuadrilla/unirme', ['codigo' => 'AUT_C_7'])->assertOk();
        $this->assertSame(6, (int) $eva->fresh()->role_id); // mientras espera sigue siendo independiente
        Notification::assertSentTo($this->jefe, MovimientoNotificacion::class);

        Sanctum::actingAs($this->jefe);
        $sala = collect($this->getJson('/api/tenants/pending-technicians')->assertOk()->json('pending_technicians'));
        $this->assertTrue((bool) $sala->firstWhere('id', $eva->id)['solicitud_cuadrilla']);
        $this->postJson("/api/tenants/approve-technician/{$eva->id}")->assertOk();

        $eva = $eva->fresh();
        $this->assertSame(8, (int) $eva->role_id);
        $this->assertSame($this->tenantJefe, (int) $eva->tenant_id);
        $this->assertNotNull(DB::table('network_quotes')->where('id', $pendiente)->value('retirada_at'));
    }

    public function test_no_se_une_con_trabajos_propios_en_curso_y_el_rechazo_no_le_quita_nada(): void
    {
        $eva = $this->usuario(6, null, 'Eva');
        $this->validar($eva);
        $suya = $this->publicada(['status' => 'Asignado', 'tecnico_id' => $eva->id]);

        Sanctum::actingAs($eva);
        $this->postJson('/api/cuadrilla/unirme', ['codigo' => 'AUT_C_7'])->assertStatus(422);

        DB::table('work_orders')->where('id', $suya)->update(['status' => 'Terminado']);
        $this->postJson('/api/cuadrilla/unirme', ['codigo' => 'AUT_C_7'])->assertOk();

        Sanctum::actingAs($this->jefe);
        $this->postJson("/api/tenants/reject-technician/{$eva->id}")->assertOk();
        $eva = $eva->fresh();
        $this->assertSame(6, (int) $eva->role_id);
        $this->assertNull($eva->tenant_id);
        $this->assertSame('approved', $eva->approval_status);
    }

    public function test_sin_cuadrilla_entra_con_una_sesion_limitada(): void
    {
        DB::table('users')->where('id', $this->ana->id)->update([
            'password' => bcrypt('secreto1'), 'approval_status' => Cuadrilla::SIN_CUADRILLA, 'is_active' => 0,
        ]);

        $token = $this->postJson('/api/login', ['email' => 'ana@red.mx', 'password' => 'secreto1'])
            ->assertOk()->assertJsonPath('sin_cuadrilla', true)->json('token');
        $this->assertNotEmpty($token);

        // Las tres con la misma sesión limitada
        $this->withToken($token)->getJson('/api/mercado-trabajos')->assertForbidden()->assertJsonPath('reason', 'sin_cuadrilla');
        $this->withToken($token)->getJson('/api/cuadrilla/mi-estado')->assertOk()->assertJsonPath('sin_cuadrilla', true);
        $this->withToken($token)->postJson('/api/cuadrilla/unirme', ['codigo' => 'AUT_C_7'])->assertOk();
        $this->assertNotNull(Cuadrilla::solicitudPendiente($this->ana->id, $this->tenantJefe));
    }

    public function test_root_activa_el_9_y_el_contratista_se_entera(): void
    {
        Sanctum::actingAs($this->root);
        $this->putJson("/api/usuarios/u_{$this->ana->id}/rol", ['role_id' => 9])->assertOk();
        $this->assertSame(9, (int) $this->ana->fresh()->role_id);
        Notification::assertSentTo($this->jefe, MovimientoNotificacion::class);

        // El 9 es solo para quien está en una cuadrilla
        $eva = $this->usuario(6, null, 'Eva');
        $this->putJson("/api/usuarios/u_{$eva->id}/rol", ['role_id' => 9])->assertStatus(422);
    }

    public function test_root_vuelve_independiente_a_un_8_si_no_tiene_trabajos_con_su_contratista(): void
    {
        $orden = $this->ordenDelJefe();
        DB::table('work_order_technician')->insert(['work_order_id' => $orden, 'technician_id' => $this->ana->id]);

        Sanctum::actingAs($this->root);
        $this->putJson("/api/usuarios/u_{$this->ana->id}/rol", ['role_id' => 6])->assertStatus(422);

        DB::table('work_orders')->where('id', $orden)->update(['status' => 'Terminado']);
        $this->putJson("/api/usuarios/u_{$this->ana->id}/rol", ['role_id' => 6])->assertOk();
        $ana = $this->ana->fresh();
        $this->assertSame(6, (int) $ana->role_id);
        $this->assertNull($ana->tenant_id);
    }

    public function test_el_contratista_no_da_de_alta_un_9(): void
    {
        Sanctum::actingAs($this->jefe);
        $this->postJson('/api/admin/usuarios', [
            'first_name' => 'Uno', 'last_name' => 'Más', 'password' => 'secreto1', 'email' => 'nueve@red.mx', 'role_id' => 9,
        ])->assertForbidden();
    }

    public function test_el_8_no_tiene_qr_para_que_lo_contraten_directo(): void
    {
        Sanctum::actingAs($this->ana);
        $this->getJson('/api/mi-qr')->assertForbidden();
    }
}
