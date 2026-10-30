<?php

namespace Tests\Feature;

use App\Models\MaintenanceEquipment;
use App\Models\MaintenanceVisit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkReport;
use App\Services\FotosMantenimiento;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Las migraciones históricas no arman la BD completa: aquí se crea lo mínimo en SQLite en memoria
// y se aplica la migración nueva. Nunca debe correr contra otra conexión.
class ElementosYMantenimientoTest extends TestCase
{
    private User $admin;
    private int $propiedad;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        Notification::fake();
        $this->crearEsquema();
        (require database_path('migrations/2026_10_30_100000_create_work_order_elements_and_maintenance.php'))->up();
        (require database_path('migrations/2026_10_30_180000_make_property_optional_on_maintenance_equipment.php'))->up();

        $this->admin = $this->usuario(1);
        $this->propiedad = DB::table('properties')->insertGetId(['property_name' => 'Casa', 'address' => 'Centro', 'tenant_id' => null]);

        $this->instance(FotosMantenimiento::class, new class extends FotosMantenimiento {
            public function subir(UploadedFile $archivo): string
            {
                return 'https://fotos.test/' . $archivo->getClientOriginalName();
            }
        });
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
            $t->boolean('valida_trabajos')->default(false);
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
        Schema::create('property_areas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('property_components', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_area_id');
            $t->string('category');
            $t->string('sub_category');
            $t->string('brand')->nullable();
            $t->string('model_or_color')->nullable();
            $t->timestamps();
        });
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id');
            $t->string('batch_id')->nullable();
            $t->string('type')->nullable();
            $t->string('zone')->nullable();
            $t->string('equipment')->nullable();
            $t->text('description')->nullable();
            $t->string('evidence_path')->nullable();
            $t->string('evidence_path_2')->nullable();
            $t->string('status')->default('Por Hacer');
            $t->string('priority')->nullable();
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->boolean('publish_network')->default(0);
            $t->string('ticket_status')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('work_order_technician', function (Blueprint $t) {
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('technician_id');
        });
        Schema::create('work_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('service_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->unsignedBigInteger('technician_id');
            $t->string('stage')->nullable();
            $t->string('image_url');
            $t->text('description')->nullable();
            $t->timestamps();
        });
    }

    private function usuario(int $rol, ?int $tenant = null): User
    {
        $usuario = new User();
        $usuario->forceFill(['role_id' => $rol, 'tenant_id' => $tenant, 'first_name' => 'Prueba', 'last_name' => 'Usuario', 'email' => uniqid() . '@test.mx', 'password' => 'x', 'is_active' => 1])->save();
        return $usuario;
    }

    private function agregar(array $datos = [])
    {
        return $this->postJson('/api/mantenimiento/equipos', $datos + [
            'property_id' => $this->propiedad,
            'tipo' => 'Aire acondicionado',
            'fecha' => now()->addMonths(6)->toDateString(),
        ]);
    }

    private function orden(array $elementos = []): WorkOrder
    {
        $orden = WorkOrder::create(['property_id' => $this->propiedad, 'type' => 'Mantenimiento', 'zone' => 'Sala', 'description' => 'x', 'status' => 'En Proceso']);
        foreach ($elementos as $label) {
            $orden->elementos()->create(['label' => $label]);
        }
        return $orden;
    }

    private function evidencia(WorkOrder $orden, string $etapa, int $elemento): void
    {
        WorkReport::create(['work_order_id' => $orden->id, 'work_order_component_id' => $elemento, 'technician_id' => $this->admin->id, 'stage' => $etapa, 'image_url' => 'u', 'description' => 'd']);
    }

    public function test_agrega_un_equipo_y_su_fecha_sale_en_la_agenda_sin_levantamiento(): void
    {
        Sanctum::actingAs($this->admin);
        $fecha = now()->addMonths(6)->toDateString();

        $this->agregar(['fecha' => $fecha])->assertCreated();

        $this->getJson('/api/mantenimiento/visitas')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.fecha_programada', $fecha)
            ->assertJsonPath('0.equipment.tipo', 'Aire acondicionado')
            ->assertJsonPath('0.equipment.property.property_name', 'Casa');

        $this->getJson('/api/mantenimiento/equipos')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.visits.0.fecha_programada', $fecha);
    }

    public function test_las_fotos_son_opcionales_y_se_guardan_si_vienen(): void
    {
        Sanctum::actingAs($this->admin);

        $this->post('/api/mantenimiento/equipos', [
            'property_id' => $this->propiedad,
            'tipo' => 'Minis',
            'cantidad' => 3,
            'fecha' => now()->addMonth()->toDateString(),
            'foto_datos' => UploadedFile::fake()->image('datos.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $equipo = MaintenanceEquipment::firstOrFail();
        $this->assertSame('https://fotos.test/datos.jpg', $equipo->foto_datos_url);
        $this->assertNull($equipo->foto_equipo_url);
        $this->assertSame('3 × Minis', $equipo->etiqueta());
    }

    public function test_pide_propiedad_equipo_y_fecha_y_rechaza_fechas_pasadas(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/mantenimiento/equipos', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['property_id', 'tipo', 'fecha']);
        $this->agregar(['fecha' => now()->subDays(3)->toDateString()])->assertStatus(422);

        $this->assertSame(0, MaintenanceEquipment::count());
    }

    public function test_cambiar_la_fecha_reemplaza_la_pendiente(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->agregar()->json('equipo.id');
        $nueva = now()->addMonths(3)->toDateString();

        $this->putJson("/api/mantenimiento/equipos/{$id}", ['tipo' => 'Aire acondicionado', 'fecha' => $nueva])->assertOk();

        $pendientes = MaintenanceVisit::where('status', 'pendiente')->get();
        $this->assertCount(1, $pendientes);
        $this->assertSame($nueva, $pendientes[0]->fecha_programada->toDateString());
    }

    public function test_editar_cambia_los_datos_y_conserva_la_fecha_si_no_se_toca(): void
    {
        Sanctum::actingAs($this->admin);
        $fecha = now()->addMonths(6)->toDateString();
        $id = $this->agregar(['fecha' => $fecha, 'detalle' => 'Viejo'])->json('equipo.id');
        $visita = MaintenanceVisit::firstOrFail();

        $this->post("/api/mantenimiento/equipos/{$id}", [
            '_method' => 'PUT',
            'tipo' => 'Minisplit',
            'cantidad' => 2,
            'detalle' => '',
            'fecha' => $fecha,
            'foto_equipo' => UploadedFile::fake()->image('nueva.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('equipo.visits.0.fecha_programada', $fecha);

        $equipo = MaintenanceEquipment::findOrFail($id);
        $this->assertSame('2 × Minisplit', $equipo->etiqueta());
        $this->assertNull($equipo->detalle);
        $this->assertSame('https://fotos.test/nueva.jpg', $equipo->foto_equipo_url);
        $this->assertSame($visita->id, MaintenanceVisit::firstOrFail()->id);
    }

    public function test_una_fecha_vencida_se_conserva_al_editar_pero_no_se_agenda_otra_en_el_pasado(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->agregar()->json('equipo.id');
        $vencida = now()->subDays(5)->toDateString();
        MaintenanceVisit::firstOrFail()->update(['fecha_programada' => $vencida]);

        $this->putJson("/api/mantenimiento/equipos/{$id}", ['tipo' => 'Otro nombre', 'fecha' => $vencida])->assertOk();
        $this->putJson("/api/mantenimiento/equipos/{$id}", ['tipo' => 'Otro nombre', 'fecha' => now()->subDay()->toDateString()])->assertStatus(422);
        $this->assertSame($vencida, MaintenanceVisit::firstOrFail()->fecha_programada->toDateString());
    }

    public function test_la_propiedad_muestra_solo_sus_mantenimientos(): void
    {
        Sanctum::actingAs($this->admin);
        $otra = DB::table('properties')->insertGetId(['property_name' => 'Otra']);
        $this->agregar();
        $this->agregar(['property_id' => $otra, 'tipo' => 'De la otra']);

        $this->getJson("/api/properties/{$this->propiedad}/mantenimiento")->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.tipo', 'Aire acondicionado');

        Sanctum::actingAs($this->usuario(2));
        $this->getJson("/api/properties/{$this->propiedad}/mantenimiento")->assertForbidden();
        Sanctum::actingAs($this->usuario(1, 15));
        $this->getJson("/api/properties/{$this->propiedad}/mantenimiento")->assertNotFound();
    }

    public function test_una_solicitud_con_equipos_elegidos_atiende_sus_fechas_y_une_el_levantamiento(): void
    {
        Sanctum::actingAs($this->admin);
        $area = DB::table('property_areas')->insertGetId(['property_id' => $this->propiedad, 'name' => 'Sala']);
        $elemento = DB::table('property_components')->insertGetId(['property_area_id' => $area, 'category' => 'CLIMA', 'sub_category' => 'AA', 'brand' => 'LG']);
        $equipo = $this->agregar()->json('equipo.id');

        $this->getJson("/api/properties/{$this->propiedad}/elementos")->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 'AA (LG) - Sala')
            ->assertJsonPath('0.area', 'Sala');

        $res = $this->postJson('/api/work-orders/cliente', [
            'property_id' => $this->propiedad, 'type' => 'Mantenimiento', 'zone' => 'Varias zonas', 'description' => 'Servicio',
            'component_ids' => [$elemento],
            'equipo_ids' => [$equipo],
        ])->assertCreated();

        $orden = WorkOrder::findOrFail($res->json('id'));
        $this->assertSame(['AA (LG) - Sala', 'Aire acondicionado'], $orden->elementos()->pluck('label')->all());
        $this->assertSame(0, MaintenanceVisit::where('status', 'pendiente')->count());
        $this->assertSame($orden->id, MaintenanceVisit::firstOrFail()->work_order_id);
        $this->getJson('/api/mantenimiento/visitas')->assertExactJson([]);
    }

    public function test_los_elementos_de_la_propiedad_son_solo_para_admins_internos(): void
    {
        Sanctum::actingAs($this->usuario(2));
        $this->getJson("/api/properties/{$this->propiedad}/elementos")->assertForbidden();
        Sanctum::actingAs($this->usuario(1, 15));
        $this->getJson("/api/properties/{$this->propiedad}/elementos")->assertNotFound();
    }

    public function test_convertir_une_lo_del_mismo_dia_y_despues_acepta_una_fecha_nueva(): void
    {
        Sanctum::actingAs($this->admin);
        $fecha = now()->addMonth()->toDateString();
        $id = $this->agregar(['fecha' => $fecha])->json('equipo.id');
        $this->agregar(['fecha' => $fecha, 'tipo' => 'Bomba de agua']);

        $res = $this->postJson('/api/mantenimiento/visitas/' . MaintenanceVisit::first()->id . '/convertir')->assertCreated();

        $orden = WorkOrder::findOrFail($res->json('work_order_id'));
        $this->assertSame(2, $orden->elementos()->count());
        $this->assertSame(0, MaintenanceVisit::where('status', 'pendiente')->count());

        // Atendida la fecha no se repite sola: se detalla la siguiente
        $this->putJson("/api/mantenimiento/equipos/{$id}", ['tipo' => 'Aire', 'fecha' => $fecha])->assertStatus(422);
        $this->putJson("/api/mantenimiento/equipos/{$id}", ['tipo' => 'Aire', 'fecha' => now()->addMonths(7)->toDateString()])->assertOk();
        $this->assertSame(1, MaintenanceVisit::where('status', 'pendiente')->count());
    }

    public function test_una_visita_no_se_convierte_dos_veces(): void
    {
        Sanctum::actingAs($this->admin);
        $this->agregar();
        $visita = MaintenanceVisit::first();

        $this->postJson("/api/mantenimiento/visitas/{$visita->id}/convertir")->assertCreated();
        $this->postJson("/api/mantenimiento/visitas/{$visita->id}/convertir")->assertStatus(422);
        $this->assertSame(1, WorkOrder::count());
    }

    public function test_quitar_un_equipo_lo_saca_de_la_lista_y_de_la_agenda(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->agregar()->json('equipo.id');

        $this->deleteJson("/api/mantenimiento/equipos/{$id}")->assertOk();

        $this->getJson('/api/mantenimiento/visitas')->assertExactJson([]);
        $this->getJson('/api/mantenimiento/equipos')->assertExactJson([]);
    }

    public function test_root_ve_equipos_de_cualquier_tenant_y_el_admin_solo_los_de_su_tenant(): void
    {
        $ajena = DB::table('properties')->insertGetId(['property_name' => 'Smart Fit', 'tenant_id' => 15]);
        $area = DB::table('property_areas')->insertGetId(['property_id' => $ajena, 'name' => 'Azotea']);
        DB::table('property_components')->insert(['property_area_id' => $area, 'category' => 'CLIMA', 'sub_category' => 'Condensador']);

        Sanctum::actingAs($this->usuario(0));
        $this->getJson("/api/properties/{$ajena}/elementos")->assertOk()->assertJsonPath('0.label', 'Condensador - Azotea');
        $this->getJson("/api/properties/{$ajena}/mantenimiento")->assertOk();
        $this->getJson('/api/mantenimiento/propiedades')->assertOk()->assertJsonCount(2);
        $id = $this->agregar(['property_id' => $ajena])->assertCreated()->json('equipo.id');

        // El admin de ese tenant trabaja con lo suyo; el interno no lo ve
        Sanctum::actingAs($this->usuario(1, 15));
        $this->getJson("/api/properties/{$ajena}/elementos")->assertOk();
        $this->getJson('/api/mantenimiento/equipos')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/properties/{$ajena}/elementos")->assertNotFound();
    }

    public function test_solo_admins_del_equipo_interno(): void
    {
        DB::table('properties')->insert(['property_name' => 'De la red', 'tenant_id' => 15]);
        Sanctum::actingAs($this->admin);
        $id = $this->agregar()->json('equipo.id');
        $this->getJson('/api/mantenimiento/propiedades')->assertOk()->assertJsonCount(1)->assertJsonPath('0.property_name', 'Casa');

        foreach ([2, 3] as $rol) {
            Sanctum::actingAs($this->usuario($rol));
            $this->getJson('/api/mantenimiento/equipos')->assertForbidden();
            $this->agregar()->assertForbidden();
        }

        // Un admin de otro tenant no ve ni toca lo interno
        Sanctum::actingAs($this->usuario(1, 15));
        $this->agregar()->assertNotFound();
        $this->getJson('/api/mantenimiento/equipos')->assertExactJson([]);
        $this->deleteJson("/api/mantenimiento/equipos/{$id}")->assertNotFound();
    }

    public function test_el_fantasma_solo_cuenta_reportes_de_tecnicos_en_trabajos_sin_cerrar_para_las_cuentas_elegidas(): void
    {
        $cuenta = $this->usuario(1);
        $cuenta->forceFill(['email' => 'ppechmena@gmail.com'])->save();
        $tecnico = $this->usuario(2);
        $reporte = fn ($orden, $autor) => WorkReport::create(['work_order_id' => $orden->id, 'technician_id' => $autor->id, 'stage' => 'antes', 'image_url' => 'u', 'description' => 'd']);

        $conReporte = $this->orden();
        $reporte($conReporte, $tecnico);
        $this->orden(); // sin evidencia
        $reporte($this->orden(), $this->admin); // la subió un admin
        $cerrada = $this->orden();
        $reporte($cerrada, $tecnico);
        $cerrada->forceFill(['status' => 'Listo'])->save();

        Sanctum::actingAs($cuenta);
        $this->getJson('/api/validacion/pendientes')->assertOk()->assertJsonPath('reportes_por_revisar', 1);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/validacion/pendientes')->assertOk()->assertJsonPath('reportes_por_revisar', null);
    }

    public function test_con_varios_elementos_no_se_termina_sin_antes_y_despues_de_cada_uno(): void
    {
        Sanctum::actingAs($this->usuario(2));
        $orden = $this->orden(['AA Sala', 'AA Cuarto']);
        [$a, $b] = $orden->elementos()->pluck('id')->all();

        $this->evidencia($orden, 'antes', $a);
        $this->evidencia($orden, 'despues', $a);
        $this->evidencia($orden, 'antes', $b);

        $this->putJson("/api/work-orders/{$orden->id}/status", ['status' => 'Listo'])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Falta la evidencia de antes o después de: AA Cuarto.']);

        $this->evidencia($orden, 'despues', $b);
        $this->putJson("/api/work-orders/{$orden->id}/status", ['status' => 'Listo'])
            ->assertOk()
            ->assertJsonFragment(['status' => 'Por Aprobar']);
    }

    public function test_una_orden_vieja_y_una_de_un_elemento_no_exigen_nada_nuevo(): void
    {
        Sanctum::actingAs($this->usuario(2));

        foreach ([$this->orden(), $this->orden(['AA Sala'])] as $orden) {
            $this->putJson("/api/work-orders/{$orden->id}/status", ['status' => 'Listo'])
                ->assertOk()
                ->assertJsonFragment(['status' => 'Por Aprobar']);
        }
    }
}
