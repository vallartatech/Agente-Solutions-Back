<?php

namespace Tests\Feature;

use App\Models\MaintenanceEquipment;
use App\Models\MaintenanceVisit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComponent;
use App\Models\WorkReport;
use App\Services\FotosMantenimiento;
use Carbon\Carbon;
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
    private int $sala;
    private int $cuarto;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        Notification::fake();
        $this->crearEsquema();
        (require database_path('migrations/2026_10_30_100000_create_work_order_elements_and_maintenance.php'))->up();

        $this->admin = $this->usuario(1);
        $this->propiedad = DB::table('properties')->insertGetId(['property_name' => 'Casa', 'address' => 'Centro', 'tenant_id' => null]);
        $area = DB::table('property_areas')->insertGetId(['property_id' => $this->propiedad, 'name' => 'Sala']);
        $this->sala = DB::table('property_components')->insertGetId(['property_area_id' => $area, 'category' => 'CLIMA', 'sub_category' => 'AA', 'brand' => 'LG']);
        $this->cuarto = DB::table('property_components')->insertGetId(['property_area_id' => $area, 'category' => 'CLIMA', 'sub_category' => 'AA 2', 'brand' => '']);

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
            $t->text('custom_checklist')->nullable();
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

    private function orden(array $elementos = []): WorkOrder
    {
        $orden = WorkOrder::create(['property_id' => $this->propiedad, 'type' => 'Mantenimiento', 'zone' => 'Sala', 'description' => 'x', 'status' => 'En Proceso']);
        foreach ($elementos as $label) {
            $orden->elementos()->create(['label' => $label]);
        }
        return $orden;
    }

    private function evidencia(WorkOrder $orden, string $etapa, ?int $elemento = null): void
    {
        WorkReport::create(['work_order_id' => $orden->id, 'work_order_component_id' => $elemento, 'technician_id' => $this->admin->id, 'stage' => $etapa, 'image_url' => 'u', 'description' => 'd']);
    }

    private function fotosGrupo(): array
    {
        return [
            'foto_equipo' => UploadedFile::fake()->image('equipo.jpg'),
            'foto_datos' => UploadedFile::fake()->image('datos.jpg'),
        ];
    }

    public function test_las_fechas_del_ciclo_coinciden_con_la_nota(): void
    {
        $base = '2026-10-26';
        $this->assertSame(['2027-10-26'], MaintenanceEquipment::fechasDelCiclo($base, 1));
        $this->assertSame(['2027-04-26', '2027-10-26'], MaintenanceEquipment::fechasDelCiclo($base, 2));
        $this->assertSame(['2027-02-26', '2027-06-26', '2027-10-26'], MaintenanceEquipment::fechasDelCiclo($base, 3));
        $this->assertSame(['2027-01-26', '2027-04-26', '2027-07-26', '2027-10-26'], MaintenanceEquipment::fechasDelCiclo($base, 4));
    }

    public function test_un_fin_de_mes_no_se_pasa_al_mes_siguiente(): void
    {
        $this->assertSame('2027-02-28', MaintenanceEquipment::fechasDelCiclo('2026-08-31', 2)[0]);
    }

    public function test_crea_una_orden_con_elementos_y_grupos_en_una_sola_peticion(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->post('/api/work-orders/cliente', [
            'property_id' => $this->propiedad,
            'type' => 'Mantenimiento',
            'zone' => 'Varias zonas',
            'description' => 'Servicio',
            'component_ids' => [$this->sala],
            'grupos' => [['tipo' => 'Minis', 'cantidad' => 3] + $this->fotosGrupo()],
        ], ['Accept' => 'application/json']);

        $res->assertCreated();
        $elementos = WorkOrderComponent::where('work_order_id', $res->json('id'))->orderBy('id')->pluck('label')->all();
        $this->assertSame(['AA (LG) - Sala', '3 × Minis'], $elementos);

        $grupo = MaintenanceEquipment::first();
        $this->assertNull($grupo->veces_al_ano);
        $this->assertSame('https://fotos.test/datos.jpg', $grupo->foto_datos_url);
    }

    public function test_un_grupo_sin_fotos_no_crea_la_orden(): void
    {
        Sanctum::actingAs($this->admin);

        $this->post('/api/work-orders/cliente', [
            'property_id' => $this->propiedad, 'type' => 'Mantenimiento', 'zone' => 'Z', 'description' => 'd',
            'grupos' => [['tipo' => 'Minis', 'cantidad' => 3]],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_un_tecnico_no_puede_crear_una_orden_con_elementos(): void
    {
        Sanctum::actingAs($this->usuario(2));

        $this->post('/api/work-orders/cliente', [
            'property_id' => $this->propiedad, 'type' => 'Mantenimiento', 'zone' => 'Z', 'description' => 'd',
            'component_ids' => [$this->sala],
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_un_elemento_de_otra_propiedad_se_rechaza(): void
    {
        Sanctum::actingAs($this->admin);
        $otraArea = DB::table('property_areas')->insertGetId(['property_id' => 999, 'name' => 'X']);
        $ajeno = DB::table('property_components')->insertGetId(['property_area_id' => $otraArea, 'category' => 'C', 'sub_category' => 'S']);

        $this->post('/api/work-orders/cliente', [
            'property_id' => $this->propiedad, 'type' => 'Mantenimiento', 'zone' => 'Z', 'description' => 'd',
            'component_ids' => [$ajeno],
        ], ['Accept' => 'application/json'])->assertStatus(422);
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

    public function test_guardar_el_ciclo_programa_elementos_y_grupos(): void
    {
        Sanctum::actingAs($this->admin);
        $orden = $this->orden();

        $this->post('/api/mantenimiento', [
            'property_id' => $this->propiedad,
            'work_order_id' => $orden->id,
            'fecha_base' => '2026-10-26',
            'items' => [
                ['property_component_id' => $this->sala, 'veces_al_ano' => 2],
                ['tipo' => 'Minis', 'cantidad' => 3, 'veces_al_ano' => 3] + $this->fotosGrupo(),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $elemento = MaintenanceEquipment::where('property_component_id', $this->sala)->firstOrFail();
        $this->assertSame(['2027-04-26', '2027-10-26'], $elemento->visits->map(fn ($v) => $v->fecha_programada->toDateString())->all());
        $this->assertSame(3, MaintenanceEquipment::whereNull('property_component_id')->firstOrFail()->visits()->count());
    }

    public function test_cambiar_la_frecuencia_reemplaza_las_fechas_pendientes_sin_duplicar_el_equipo(): void
    {
        Sanctum::actingAs($this->admin);
        $guardar = fn ($veces) => $this->postJson('/api/mantenimiento', [
            'property_id' => $this->propiedad,
            'fecha_base' => '2026-10-26',
            'items' => [['property_component_id' => $this->sala, 'veces_al_ano' => $veces]],
        ])->assertCreated();

        $guardar(4);
        $guardar(1);

        $this->assertSame(1, MaintenanceEquipment::count());
        $this->assertSame(['2027-10-26'], MaintenanceVisit::pluck('fecha_programada')->map(fn ($f) => $f->toDateString())->all());
    }

    public function test_un_grupo_nuevo_sin_fotos_se_rechaza_en_el_ciclo(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/mantenimiento', [
            'property_id' => $this->propiedad,
            'fecha_base' => '2026-10-26',
            'items' => [['tipo' => 'Minis', 'cantidad' => 3, 'veces_al_ano' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, MaintenanceEquipment::count());
    }

    public function test_convertir_une_en_una_orden_lo_del_mismo_dia_y_renueva_el_ciclo(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/mantenimiento', [
            'property_id' => $this->propiedad,
            'fecha_base' => '2026-10-26',
            'items' => [
                ['property_component_id' => $this->sala, 'veces_al_ano' => 1],
                ['property_component_id' => $this->cuarto, 'veces_al_ano' => 1],
            ],
        ])->assertCreated();

        $res = $this->postJson('/api/mantenimiento/visitas/' . MaintenanceVisit::first()->id . '/convertir')->assertCreated();

        $orden = WorkOrder::findOrFail($res->json('work_order_id'));
        $this->assertSame('Mantenimiento', $orden->type);
        $this->assertSame(2, $orden->elementos()->count());
        $this->assertSame(2, MaintenanceVisit::where('status', 'convertida')->where('work_order_id', $orden->id)->count());
        // El año siguiente queda programado para cada equipo
        $this->assertSame(2, MaintenanceVisit::where('status', 'pendiente')->whereDate('fecha_programada', '2028-10-26')->count());
    }

    public function test_una_visita_no_se_convierte_dos_veces(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/mantenimiento', [
            'property_id' => $this->propiedad,
            'fecha_base' => '2026-10-26',
            'items' => [['property_component_id' => $this->sala, 'veces_al_ano' => 2]],
        ])->assertCreated();
        $visita = MaintenanceVisit::first();

        $this->postJson("/api/mantenimiento/visitas/{$visita->id}/convertir")->assertCreated();
        $this->postJson("/api/mantenimiento/visitas/{$visita->id}/convertir")->assertStatus(422);
        $this->assertSame(1, WorkOrder::count());
    }

    public function test_la_baja_quita_las_fechas_pendientes_de_la_agenda(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/mantenimiento', [
            'property_id' => $this->propiedad,
            'fecha_base' => '2026-10-26',
            'items' => [['property_component_id' => $this->sala, 'veces_al_ano' => 4]],
        ])->assertCreated();

        $this->deleteJson('/api/mantenimiento/equipos/' . MaintenanceEquipment::first()->id)->assertOk();

        $this->getJson('/api/mantenimiento/visitas')->assertOk()->assertExactJson([]);
    }

    public function test_el_mantenimiento_es_solo_de_admins_del_equipo_interno(): void
    {
        Sanctum::actingAs($this->usuario(2));
        $this->getJson('/api/mantenimiento/visitas')->assertForbidden();
        $this->getJson("/api/properties/{$this->propiedad}/mantenimiento")->assertForbidden();

        // Un admin de otro tenant no ve propiedades internas
        Sanctum::actingAs($this->usuario(1, 15));
        $this->getJson("/api/properties/{$this->propiedad}/mantenimiento")->assertNotFound();

        // Un cliente tampoco
        Sanctum::actingAs($this->usuario(3));
        $this->getJson('/api/mantenimiento/formulario?property_id=' . $this->propiedad)->assertForbidden();
    }
}
