<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Catálogo de habilidades fijo: el técnico sugiere, Root/Admin aprueban, unen o rechazan.
class SugerenciaEspecialidadTest extends TestCase
{
    private User $root;
    private User $tecnico;
    private User $otroTecnico;
    private int $hvac;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        Notification::fake();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->default(1);
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('profile_picture')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('specialties', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('icon')->nullable();
            $t->string('category')->nullable();
            $t->timestamps();
        });
        Schema::create('technician_specialties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('specialty_id');
            $t->string('custom_name')->nullable();
        });
        (require database_path('migrations/2026_11_17_100000_create_specialty_suggestions_table.php'))->up();

        $this->root = $this->usuario(0, 'root@agente.mx');
        $this->tecnico = $this->usuario(6, 'tec@red.mx');
        $this->otroTecnico = $this->usuario(8, 'otro@red.mx');
        $this->hvac = DB::table('specialties')->insertGetId(['name' => 'Aire Acondicionado (HVAC)', 'icon' => '❄️']);
    }

    private function usuario(int $rol, string $email): User
    {
        $id = DB::table('users')->insertGetId(['role_id' => $rol, 'email' => $email, 'first_name' => 'U' . $rol]);
        return User::withoutGlobalScopes()->find($id);
    }

    public function test_el_tecnico_ya_no_crea_habilidades_directo(): void
    {
        Sanctum::actingAs($this->tecnico);
        $this->postJson('/api/specialties', ['name' => 'Tecnico de Compuatdoras'])->assertForbidden();

        // Ni al guardar sus habilidades con un nombre nuevo: queda como sugerencia
        $this->postJson("/api/users/u_{$this->tecnico->id}/specialties", ['specialties' => [$this->hvac, 'Paneles solares']])->assertOk();
        $this->assertDatabaseMissing('specialties', ['name' => 'Paneles solares']);
        $this->assertDatabaseHas('specialty_suggestions', ['user_id' => $this->tecnico->id, 'name' => 'Paneles solares', 'status' => 'pendiente']);
        $this->assertSame([$this->hvac], $this->tecnico->specialties()->pluck('specialties.id')->all());
    }

    public function test_si_ya_existe_se_usa_la_del_catalogo(): void
    {
        Sanctum::actingAs($this->tecnico);
        $this->postJson('/api/especialidades/sugerencias', ['nombre' => '  aire acondicionado (hvac) '])->assertOk()
            ->assertJsonPath('ya_existe', true)
            ->assertJsonPath('specialty.id', $this->hvac);
        $this->assertSame(0, DB::table('specialty_suggestions')->count());
    }

    public function test_sugerir_no_se_repite_y_avisa_a_root(): void
    {
        Sanctum::actingAs($this->tecnico);
        $this->postJson('/api/especialidades/sugerencias', ['nombre' => 'paneles solares'])->assertCreated()
            ->assertJsonPath('sugerencia.nombre', 'Paneles solares');
        $this->postJson('/api/especialidades/sugerencias', ['nombre' => 'Paneles Solares'])->assertOk();
        $this->assertSame(1, DB::table('specialty_suggestions')->count());
        $this->getJson('/api/especialidades/sugerencias/mias')->assertOk()->assertJsonCount(1, 'data');

        Notification::assertSentTo($this->root, \App\Notifications\MovimientoNotificacion::class);
        Notification::assertNotSentTo($this->otroTecnico, \App\Notifications\MovimientoNotificacion::class);
    }

    public function test_aprobar_con_nombre_corregido_la_agrega_a_todos_los_que_la_pidieron(): void
    {
        Sanctum::actingAs($this->tecnico);
        $id = $this->postJson('/api/especialidades/sugerencias', ['nombre' => 'Tecnico de Compuatdoras'])->json('sugerencia.id');
        Sanctum::actingAs($this->otroTecnico);
        $this->postJson('/api/especialidades/sugerencias', ['nombre' => 'tecnico de compuatdoras'])->assertCreated();

        // El técnico no puede revisar
        $this->getJson('/api/admin/especialidades/sugerencias')->assertForbidden();
        $this->postJson("/api/admin/especialidades/sugerencias/{$id}/aprobar")->assertForbidden();

        Sanctum::actingAs($this->root);
        $this->getJson('/api/admin/especialidades/sugerencias')->assertOk()->assertJsonPath('data.0.veces', 2);
        $this->postJson("/api/admin/especialidades/sugerencias/{$id}/aprobar", ['nombre' => 'Computadoras y Soporte Técnico', 'icono' => '💻'])->assertOk();

        $nueva = DB::table('specialties')->where('name', 'Computadoras y Soporte Técnico')->first();
        $this->assertNotNull($nueva);
        $this->assertSame('💻', $nueva->icon);
        $this->assertTrue($this->tecnico->specialties()->whereKey($nueva->id)->exists());
        $this->assertTrue($this->otroTecnico->specialties()->whereKey($nueva->id)->exists());
        $this->assertSame(0, DB::table('specialty_suggestions')->where('status', 'pendiente')->count());

        // Ya revisada: no se puede volver a resolver
        $this->postJson("/api/admin/especialidades/sugerencias/{$id}/rechazar")->assertStatus(422);
    }

    public function test_unir_a_una_existente_y_rechazar(): void
    {
        Sanctum::actingAs($this->tecnico);
        $minisplit = $this->postJson('/api/especialidades/sugerencias', ['nombre' => 'Minisplits'])->json('sugerencia.id');
        $otra = $this->postJson('/api/especialidades/sugerencias', ['nombre' => 'Cosas varias'])->json('sugerencia.id');

        Sanctum::actingAs($this->root);
        $this->postJson("/api/admin/especialidades/sugerencias/{$minisplit}/unir", ['specialty_id' => $this->hvac])->assertOk();
        $this->assertTrue($this->tecnico->specialties()->whereKey($this->hvac)->exists());
        $this->assertDatabaseHas('specialty_suggestions', ['id' => $minisplit, 'status' => 'unida', 'specialty_id' => $this->hvac]);
        $this->assertDatabaseMissing('specialties', ['name' => 'Minisplits']);

        $this->postJson("/api/admin/especialidades/sugerencias/{$otra}/rechazar", ['motivo' => 'Es muy general.'])->assertOk();
        $this->assertDatabaseHas('specialty_suggestions', ['id' => $otra, 'status' => 'rechazada', 'motivo' => 'Es muy general.']);
        $this->postJson("/api/admin/especialidades/sugerencias/{$otra}/rechazar")->assertStatus(422);
    }

    public function test_root_si_puede_crear_directo(): void
    {
        Sanctum::actingAs($this->root);
        $this->postJson('/api/specialties', ['name' => 'Paneles solares'])->assertCreated();
        $this->assertDatabaseHas('specialties', ['name' => 'Paneles solares']);
    }
}
