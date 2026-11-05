<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// QR de acceso a la propiedad: permisos, llegada del técnico, lotes y borrado al finalizar.
// Igual que ElementosYMantenimientoTest: esquema mínimo en SQLite en memoria + la migración nueva.
class AccesoQrTest extends TestCase
{
    private User $admin;
    private User $tecnico;
    private int $propiedad;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        Notification::fake();
        $this->crearEsquema();
        (require database_path('migrations/2026_11_04_100000_create_access_qrs_tables.php'))->up();

        $this->admin = $this->usuario(1);
        $this->tecnico = $this->usuario(2);
        $this->propiedad = DB::table('properties')->insertGetId(['property_name' => 'Torre B', 'address' => 'Centro']);
    }

    private function crearEsquema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->default(1);
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
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
            $t->string('status')->default('Por Hacer');
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->string('arrival_status', 30)->default('PENDIENTE');
            $t->timestamp('arrived_at')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->text('custom_checklist')->nullable();
            $t->timestamps();
        });
        Schema::create('work_order_technician', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
        });
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id');
            $t->string('title')->nullable();
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('arrival_status', 30)->default('PENDIENTE');
            $t->timestamps();
        });
        Schema::create('service_technician', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('service_id');
            $t->unsignedBigInteger('technician_id');
        });
    }

    private function usuario(int $rol): User
    {
        return User::withoutGlobalScopes()->findOrFail(
            DB::table('users')->insertGetId(['role_id' => $rol, 'first_name' => "Rol {$rol}", 'email' => uniqid() . '@test.mx'])
        );
    }

    private function orden(array $datos = []): WorkOrder
    {
        return WorkOrder::withoutGlobalScopes()->findOrFail(
            DB::table('work_orders')->insertGetId($datos + ['property_id' => $this->propiedad, 'tecnico_id' => $this->tecnico->id])
        );
    }

    private function subirQr(string $tipo, int $id, int $slot = 1, array $extra = [])
    {
        Sanctum::actingAs($this->admin);
        return $this->post("/api/trabajos/{$tipo}/{$id}/acceso-qr", $extra + [
            'slot' => $slot,
            'imagen' => UploadedFile::fake()->image('qr.png', 300, 300),
            'label' => 'Acceso principal',
            'note' => 'Mostrar en recepción',
        ], ['Accept' => 'application/json']);
    }

    public function test_admin_sube_el_qr_y_queda_cifrado(): void
    {
        $orden = $this->orden();

        $this->subirQr('work_order', $orden->id)
            ->assertOk()
            ->assertJsonPath('cantidad', 1)
            ->assertJsonPath('puede_editar', true)
            ->assertJsonPath('qrs.0.label', 'Acceso principal')
            ->assertJsonMissingPath('qrs.0.image');

        $guardado = DB::table('access_qrs')->first();
        $this->assertSame($orden->id, (int) $guardado->work_order_id);
        // Lo guardado no es la imagen ni su base64: está cifrado
        $this->assertStringNotContainsString('PNG', base64_decode($guardado->image, false) ?: '');
        $this->assertNotFalse(base64_decode(decrypt($guardado->image, false), true));

        $this->assertDatabaseHas('access_qr_logs', ['action' => 'subido', 'user_id' => $this->admin->id]);
    }

    public function test_el_admin_ve_la_imagen_sin_cache(): void
    {
        $orden = $this->orden();
        $this->subirQr('work_order', $orden->id);

        $res = $this->get("/api/trabajos/work_order/{$orden->id}/acceso-qr/1/imagen")->assertOk();
        $this->assertStringStartsWith('image/', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertNotEmpty($res->getContent());
    }

    public function test_tecnico_asignado_no_ve_la_imagen_hasta_llegar(): void
    {
        $orden = $this->orden();
        $this->subirQr('work_order', $orden->id);

        Sanctum::actingAs($this->tecnico);
        $this->getJson("/api/trabajos/work_order/{$orden->id}/acceso-qr")
            ->assertOk()
            ->assertJsonPath('cantidad', 1)
            ->assertJsonPath('bloqueado', true)
            ->assertJsonPath('qrs.0.note', null);
        $this->getJson("/api/trabajos/work_order/{$orden->id}/acceso-qr/1/imagen")->assertForbidden();

        DB::table('work_orders')->where('id', $orden->id)->update(['arrival_status' => 'EN_SITIO']);

        $this->getJson("/api/trabajos/work_order/{$orden->id}/acceso-qr")
            ->assertJsonPath('bloqueado', false)
            ->assertJsonPath('qrs.0.note', 'Mostrar en recepción');
        $this->get("/api/trabajos/work_order/{$orden->id}/acceso-qr/1/imagen")->assertOk();
        $this->assertDatabaseHas('access_qr_logs', ['action' => 'visto', 'user_id' => $this->tecnico->id]);
    }

    public function test_tecnico_de_la_cuadrilla_tambien_lo_ve(): void
    {
        $companero = $this->usuario(2);
        $orden = $this->orden(['arrival_status' => 'EN_SITIO']);
        DB::table('work_order_technician')->insert(['work_order_id' => $orden->id, 'technician_id' => $companero->id]);
        $this->subirQr('work_order', $orden->id);

        Sanctum::actingAs($companero);
        $this->get("/api/trabajos/work_order/{$orden->id}/acceso-qr/1/imagen")->assertOk();
    }

    public function test_tecnico_no_asignado_cliente_y_red_no_tienen_acceso(): void
    {
        $orden = $this->orden(['arrival_status' => 'EN_SITIO']);
        $this->subirQr('work_order', $orden->id);

        foreach ([$this->usuario(2), $this->usuario(3), $this->usuario(6)] as $intruso) {
            Sanctum::actingAs($intruso);
            $this->getJson("/api/trabajos/work_order/{$orden->id}/acceso-qr")->assertForbidden();
            $this->getJson("/api/trabajos/work_order/{$orden->id}/acceso-qr/1/imagen")->assertForbidden();
        }
    }

    public function test_solo_root_y_admin_suben_o_eliminan(): void
    {
        $orden = $this->orden();
        $this->subirQr('work_order', $orden->id);

        Sanctum::actingAs($this->tecnico);
        $this->post("/api/trabajos/work_order/{$orden->id}/acceso-qr", [
            'slot' => 2, 'imagen' => UploadedFile::fake()->image('qr.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("/api/trabajos/work_order/{$orden->id}/acceso-qr/1")->assertForbidden();
        $this->assertDatabaseCount('access_qrs', 1);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/trabajos/work_order/{$orden->id}/acceso-qr/1")->assertOk()->assertJsonPath('cantidad', 0);
        $this->assertDatabaseHas('access_qr_logs', ['action' => 'eliminado', 'user_id' => $this->admin->id]);
    }

    public function test_maximo_dos_qr_y_reemplazar_no_duplica(): void
    {
        $orden = $this->orden();
        $this->subirQr('work_order', $orden->id, 1)->assertOk();
        $this->subirQr('work_order', $orden->id, 2)->assertOk()->assertJsonPath('cantidad', 2);
        $this->subirQr('work_order', $orden->id, 3)->assertStatus(422);
        $this->subirQr('work_order', $orden->id, 1, ['label' => 'Nuevo'])->assertOk()->assertJsonPath('cantidad', 2);

        $this->assertDatabaseCount('access_qrs', 2);
        $this->assertDatabaseHas('access_qrs', ['slot' => 1, 'label' => 'Nuevo']);
        $this->assertDatabaseHas('access_qr_logs', ['action' => 'reemplazado']);
    }

    public function test_finalizar_la_orden_borra_el_qr(): void
    {
        $orden = $this->orden();
        $this->subirQr('work_order', $orden->id);

        $orden->update(['status' => 'Listo']);

        $this->assertDatabaseCount('access_qrs', 0);
        $this->assertDatabaseHas('access_qr_logs', ['action' => 'eliminado_auto', 'work_order_id' => $orden->id]);
    }

    public function test_cancelar_o_rechazar_tambien_lo_borra(): void
    {
        foreach (['Cancelado', 'Rechazado'] as $estado) {
            $orden = $this->orden();
            $this->subirQr('work_order', $orden->id);
            $orden->update(['status' => $estado]);
            $this->assertSame(0, DB::table('access_qrs')->where('work_order_id', $orden->id)->count(), $estado);
        }
    }

    public function test_la_segunda_visita_no_borra_el_qr(): void
    {
        $orden = $this->orden();
        $this->subirQr('work_order', $orden->id);

        $orden->update(['status' => 'Segunda Visita Programada']);
        $orden->update(['status' => 'En Proceso']);

        $this->assertDatabaseCount('access_qrs', 1);
    }

    public function test_el_lote_comparte_el_qr_y_se_borra_al_cerrar_la_ultima_orden(): void
    {
        $primera = $this->orden(['batch_id' => 'LOTE-AB12']);
        $segunda = $this->orden(['batch_id' => 'LOTE-AB12', 'arrival_status' => 'EN_SITIO']);
        $otraPropiedad = DB::table('properties')->insertGetId(['property_name' => 'Otra']);
        $ajena = $this->orden(['batch_id' => 'LOTE-AB12', 'property_id' => $otraPropiedad]);

        $this->subirQr('work_order', $primera->id)->assertJsonPath('es_lote', true)->assertJsonPath('trabajos_en_lote', 2);

        // La segunda orden del lote ve el mismo QR; la de otra propiedad con el mismo código no
        Sanctum::actingAs($this->tecnico);
        $this->getJson("/api/trabajos/work_order/{$segunda->id}/acceso-qr")->assertJsonPath('cantidad', 1);
        $this->getJson("/api/trabajos/work_order/{$ajena->id}/acceso-qr")->assertJsonPath('cantidad', 0);
        // Llegó en la segunda: también puede verlo desde la primera
        $this->get("/api/trabajos/work_order/{$primera->id}/acceso-qr/1/imagen")->assertOk();

        $primera->update(['status' => 'Listo']);
        $this->assertDatabaseCount('access_qrs', 1);

        $segunda->update(['status' => 'Listo']);
        $this->assertDatabaseCount('access_qrs', 0);
    }

    public function test_finalizar_un_servicio_borra_su_qr(): void
    {
        $servicio = Service::withoutGlobalScopes()->findOrFail(DB::table('services')->insertGetId([
            'property_id' => $this->propiedad, 'title' => 'Revisión', 'assigned_to' => $this->tecnico->id,
        ]));
        $this->subirQr('servicio', $servicio->id)->assertOk();

        $servicio->update(['status' => 'Finalizado']);

        $this->assertDatabaseCount('access_qrs', 0);
    }

    public function test_no_se_sube_qr_a_un_trabajo_finalizado(): void
    {
        $orden = $this->orden(['status' => 'Listo']);
        $this->subirQr('work_order', $orden->id)->assertStatus(422);
        $this->assertDatabaseCount('access_qrs', 0);
    }

    public function test_respaldos_limpian_cierres_sin_modelo_y_vencidos(): void
    {
        $cerrada = $this->orden();
        $vencida = $this->orden();
        $vigente = $this->orden();
        $this->subirQr('work_order', $cerrada->id);
        $this->subirQr('work_order', $vencida->id, 1, ['valid_until' => now()->addHour()->toIso8601String()]);
        $this->subirQr('work_order', $vigente->id);

        // Cierre que no pasa por el modelo y vigencia que ya pasó
        DB::table('work_orders')->where('id', $cerrada->id)->update(['status' => 'Finalizado']);
        DB::table('access_qrs')->where('work_order_id', $vencida->id)->update(['valid_until' => now()->subMinute()]);

        $this->artisan('acceso-qr:purgar')->assertSuccessful();

        $this->assertSame([$vigente->id], DB::table('access_qrs')->pluck('work_order_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_leer_un_trabajo_cerrado_tambien_lo_limpia(): void
    {
        $orden = $this->orden();
        $this->subirQr('work_order', $orden->id);
        DB::table('work_orders')->where('id', $orden->id)->update(['status' => 'Listo']);

        $this->getJson("/api/trabajos/work_order/{$orden->id}/acceso-qr")
            ->assertOk()
            ->assertJsonPath('cantidad', 0)
            ->assertJsonPath('cerrado', true);
        $this->assertDatabaseCount('access_qrs', 0);
    }
}
