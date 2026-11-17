<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use App\Services\AvisoTrabajosRed;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Red: "Hay trabajos de tu especialidad" a los técnicos con esa habilidad, agrupado para no avisar por cada trabajo.
class AvisoTrabajosRedTest extends TestCase
{
    private User $cliente;
    private User $hvac1;
    private User $plomero;
    private User $sinAprobar;
    private User $bloqueado;
    private int $idHvac;
    private int $propiedad;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        Notification::fake();
        \Closure::bind(fn () => \App\Services\PerfilProfesional::$tablaLista = null, null, \App\Services\PerfilProfesional::class)();
        $this->crearEsquema();
        (require database_path('migrations/2026_11_17_120000_create_avisos_trabajos_red_table.php'))->up();

        $this->idHvac = DB::table('specialties')->insertGetId(['name' => 'Aire Acondicionado (HVAC)']);
        $idPlomeria = DB::table('specialties')->insertGetId(['name' => 'Plomería']);

        $this->cliente = $this->usuario(4);
        $this->hvac1 = $this->usuario(6, [$this->idHvac]);
        $this->plomero = $this->usuario(8, [$idPlomeria]);
        $this->sinAprobar = $this->usuario(6, [$this->idHvac], false);
        $this->bloqueado = $this->usuario(6, [$this->idHvac]);

        $clienteId = DB::table('clients')->insertGetId(['user_id' => $this->cliente->id]);
        $this->propiedad = DB::table('properties')->insertGetId(['client_id' => $clienteId]);
        DB::table('client_technician_relations')->insert([
            'technician_id' => $this->bloqueado->id, 'client_user_id' => $this->cliente->id, 'status' => 'bloqueado',
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
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('owner_user_id')->nullable();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
        });
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id');
            $t->string('status')->default('Por Hacer');
            $t->boolean('publish_network')->default(1);
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->unsignedBigInteger('tecnico_dirigido_id')->nullable();
            $t->unsignedBigInteger('specialty_id')->nullable();
            $t->timestamps();
        });
        Schema::create('specialties', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('icon')->nullable();
            $t->timestamps();
        });
        Schema::create('technician_specialties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('specialty_id');
            $t->string('custom_name')->nullable();
        });
        Schema::create('technician_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->boolean('obligatorio')->default(true);
            $t->timestamp('aprobado_at')->nullable();
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

    private function usuario(int $rol, array $especialidades = [], bool $aprobado = true): User
    {
        $id = DB::table('users')->insertGetId(['role_id' => $rol, 'first_name' => 'U' . $rol]);
        foreach ($especialidades as $e) {
            DB::table('technician_specialties')->insert(['user_id' => $id, 'specialty_id' => $e]);
        }
        if (in_array($rol, [6, 8], true)) {
            DB::table('technician_profiles')->insert(['user_id' => $id, 'obligatorio' => true, 'aprobado_at' => $aprobado ? now() : null]);
        }
        return User::withoutGlobalScopes()->find($id);
    }

    private function publicar(?int $especialidad, array $extra = []): void
    {
        $id = DB::table('work_orders')->insertGetId(['property_id' => $this->propiedad, 'specialty_id' => $especialidad] + $extra);
        AvisoTrabajosRed::nuevoTrabajo(WorkOrder::withoutGlobalScopes()->find($id), $this->cliente->id);
    }

    private function avisosDe(User $u): array
    {
        return Notification::sent($u, MovimientoNotificacion::class)
            ->map(fn ($n) => $n->toArray($u)['message'] ?? '')->values()->all();
    }

    public function test_solo_a_tecnicos_con_esa_habilidad_aprobados_y_no_bloqueados(): void
    {
        $this->publicar($this->idHvac);

        $this->assertSame(['Hay un trabajo de Aire Acondicionado (HVAC) disponible. ¡Míralo ahora!'], $this->avisosDe($this->hvac1));
        $this->assertSame([], $this->avisosDe($this->plomero));
        $this->assertSame([], $this->avisosDe($this->sinAprobar));
        $this->assertSame([], $this->avisosDe($this->bloqueado));
        $this->assertSame([], $this->avisosDe($this->cliente));
    }

    public function test_agrupa_cada_3_y_avisa_de_nuevo_pasada_la_ventana(): void
    {
        $this->publicar($this->idHvac);   // avisa (primero)
        $this->publicar($this->idHvac);   // se junta (1)
        $this->publicar($this->idHvac);   // se junta (2)
        $this->assertCount(1, $this->avisosDe($this->hvac1));

        $this->publicar($this->idHvac);   // 3 juntos → avisa
        $this->assertSame('Hay 3 trabajos de Aire Acondicionado (HVAC) disponibles. ¡Míralos ahora!', $this->avisosDe($this->hvac1)[1]);

        $this->travel(AvisoTrabajosRed::VENTANA_MIN + 1)->minutes();
        $this->publicar($this->idHvac);   // pasó la ventana → avisa aunque sea uno
        $this->assertCount(3, $this->avisosDe($this->hvac1));
    }

    public function test_al_abrir_el_mercado_ya_no_cuenta_lo_que_vio(): void
    {
        $this->publicar($this->idHvac);
        $this->publicar($this->idHvac);
        $this->publicar($this->idHvac);
        AvisoTrabajosRed::visto($this->hvac1);

        $this->publicar($this->idHvac);   // empieza de cero: 1 pendiente, dentro de la ventana
        $this->assertCount(1, $this->avisosDe($this->hvac1));
        $this->assertSame(1, (int) DB::table('avisos_trabajos_red')->where('user_id', $this->hvac1->id)->value('pendientes'));
    }

    public function test_no_estoy_seguro_avisa_a_todos_y_dirigida_a_nadie(): void
    {
        $this->publicar(null);
        $this->assertSame(['Hay un trabajo nuevo disponible en la red. ¡Míralo ahora!'], $this->avisosDe($this->hvac1));
        $this->assertCount(1, $this->avisosDe($this->plomero));
        $this->assertSame([], $this->avisosDe($this->bloqueado));

        Notification::fake();
        $this->travel(AvisoTrabajosRed::VENTANA_MIN + 1)->minutes();
        $this->publicar($this->idHvac, ['tecnico_dirigido_id' => $this->hvac1->id]);
        $this->publicar($this->idHvac, ['tecnico_id' => $this->hvac1->id, 'status' => 'Asignado']);
        $this->assertSame([], $this->avisosDe($this->hvac1));
    }

    public function test_texto_con_varias_habilidades(): void
    {
        $this->assertSame('Hay 4 trabajos de tu especialidad disponibles. ¡Míralos ahora!',
            AvisoTrabajosRed::texto(4, ['Electricidad', 'Plomería'])[1]);
        $this->assertSame('Hay 3 trabajos de tu especialidad disponibles. ¡Míralos ahora!',
            AvisoTrabajosRed::texto(3, ['Electricidad', ''])[1]);
        $this->assertSame('Hay 3 trabajos nuevos disponibles en la red. ¡Míralos ahora!',
            AvisoTrabajosRed::texto(3, [''])[1]);
    }
}
