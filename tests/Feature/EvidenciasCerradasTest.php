<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Evidencias de un trabajo: el técnico deja de editarlas al enviarlo a aprobación,
// el admin puede corregirlas hasta validarlo y la red (Market) no cambia su flujo.
class EvidenciasCerradasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

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
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id')->nullable();
            $t->string('status')->default('En proceso');
            $t->timestamps();
        });
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('work_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('service_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->unsignedBigInteger('work_order_component_id')->nullable();
            $t->unsignedBigInteger('technician_id');
            $t->string('stage')->nullable();
            $t->string('image_url');
            $t->text('description')->nullable();
            $t->timestamps();
        });
    }

    private function usuario(int $rol): User
    {
        return User::withoutGlobalScopes()->findOrFail(
            DB::table('users')->insertGetId(['role_id' => $rol, 'first_name' => "Rol {$rol}", 'email' => uniqid() . '@test.mx'])
        );
    }

    /** @return array{0:int,1:int} [orden, reporte] */
    private function trabajoConEvidencia(string $estado): array
    {
        $orden = DB::table('work_orders')->insertGetId(['status' => $estado]);
        $reporte = DB::table('work_reports')->insertGetId([
            'work_order_id' => $orden, 'technician_id' => 1, 'stage' => 'antes',
            'image_url' => 'https://x.test/a.jpg', 'description' => '[ANTES] Foto',
        ]);
        return [$orden, $reporte];
    }

    public function test_el_tecnico_edita_mientras_el_trabajo_sigue_en_proceso(): void
    {
        [, $reporte] = $this->trabajoConEvidencia('En proceso');
        Sanctum::actingAs($this->usuario(2));

        $this->deleteJson("/api/reportes/{$reporte}")->assertOk();
    }

    public function test_al_enviarlo_a_aprobacion_el_tecnico_ya_no_puede_tocar_evidencias(): void
    {
        [$orden, $reporte] = $this->trabajoConEvidencia('Por Aprobar');
        Sanctum::actingAs($this->usuario(2));

        $this->deleteJson("/api/reportes/{$reporte}")->assertForbidden();
        $this->putJson("/api/reportes/{$reporte}", ['description' => 'Cambio'])->assertForbidden();
        $this->post("/api/servicios/work_order-{$orden}/reportes", [
            'description' => '[DURANTE] Nueva',
            'image' => UploadedFile::fake()->image('foto.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertDatabaseHas('work_reports', ['id' => $reporte, 'description' => '[ANTES] Foto']);
    }

    public function test_el_admin_corrige_antes_de_validar(): void
    {
        [, $reporte] = $this->trabajoConEvidencia('Por Aprobar');
        Sanctum::actingAs($this->usuario(1));

        $this->putJson("/api/reportes/{$reporte}", ['description' => '[ANTES] Corregida'])->assertOk();
        $this->assertDatabaseHas('work_reports', ['id' => $reporte, 'description' => '[ANTES] Corregida']);
    }

    public function test_validado_nadie_lo_modifica(): void
    {
        foreach (['Listo', 'Finalizado'] as $estado) {
            [, $reporte] = $this->trabajoConEvidencia($estado);
            foreach ([0, 1, 2] as $rol) {
                Sanctum::actingAs($this->usuario($rol));
                $this->deleteJson("/api/reportes/{$reporte}")->assertForbidden();
            }
        }
    }

    public function test_la_red_conserva_su_flujo(): void
    {
        [, $reporte] = $this->trabajoConEvidencia('Listo');
        Sanctum::actingAs($this->usuario(8));

        $this->deleteJson("/api/reportes/{$reporte}")->assertOk();
    }
}
