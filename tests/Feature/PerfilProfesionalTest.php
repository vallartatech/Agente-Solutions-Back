<?php

namespace Tests\Feature;

use App\Models\TechnicianProfile;
use App\Models\User;
use App\Services\PerfilProfesional;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Perfil profesional de los técnicos de la red: candado hasta que Admin/Root apruebe,
// CLABE con dígito verificador, datos privados por niveles y revisión.
class PerfilProfesionalTest extends TestCase
{
    private const CLABE_OK = '072910011958785445';

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
            $t->string('phone_number')->nullable();
            $t->string('profile_picture')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('specialties', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('icon')->nullable();
            $t->string('category')->nullable();
            $t->timestamps();
        });
        Schema::create('technician_specialties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('specialty_id');
            $t->string('custom_name')->nullable();
            $t->timestamps();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('property_id')->nullable();
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->string('setting_key')->unique();
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });

        // Un técnico que ya existía antes de la migración
        $this->existente = User::forceCreate(['role_id' => 6, 'first_name' => 'Viejo', 'email' => 'viejo@test.mx']);

        (require database_path('migrations/2026_11_06_100000_create_technician_professional_profiles.php'))->up();

        Notification::fake();
        Storage::fake('local');
    }

    private User $existente;

    private function usuario(int $rol, array $extra = []): User
    {
        static $n = 0;
        $n++;
        return User::forceCreate(array_merge(['role_id' => $rol, 'first_name' => "Rol{$rol}", 'last_name' => "N{$n}", 'email' => "u{$n}@test.mx"], $extra));
    }

    private function completarPerfil(User $tecnico): void
    {
        Sanctum::actingAs($tecnico);
        $this->postJson('/api/tecnico/perfil-profesional', [
            'emite_factura' => true,
            'rfc' => 'pepj800101ab1',
            'razon_social' => 'Juan Pérez',
            'banco' => 'Banorte',
            'titular_cuenta' => 'Juan Pérez',
            'clabe' => '0729 1001 1958 7854 45',
            'acepta_efectivo' => true,
            'tiene_terminal' => false,
            'anios_experiencia' => 8,
            'nivel_estudios' => 'tecnico',
            'presentacion' => 'Electricista con 8 años en instalaciones residenciales.',
            'especialidades' => ['Electricidad', 'Paneles solares'],
            'sin_cursos' => true,
            'sin_empleos' => true,
        ])->assertOk();
        $this->post('/api/tecnico/perfil-profesional/ine', [
            'frente' => UploadedFile::fake()->image('frente.jpg'),
            'reverso' => UploadedFile::fake()->image('reverso.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    // ── Reglas ──

    public function test_clabe_con_digito_verificador(): void
    {
        $this->assertTrue(PerfilProfesional::clabeValida(self::CLABE_OK));
        $this->assertFalse(PerfilProfesional::clabeValida('072910011958785446'));
        $this->assertFalse(PerfilProfesional::clabeValida('12345'));
        $this->assertFalse(PerfilProfesional::clabeValida(null));
    }

    public function test_rfc_fisica_y_moral(): void
    {
        $this->assertTrue(PerfilProfesional::rfcValido('PEPJ800101AB1'));
        $this->assertTrue(PerfilProfesional::rfcValido('ABC800101XY2'));
        $this->assertFalse(PerfilProfesional::rfcValido('PEPJ800101'));
    }

    // ── Candado ──

    public function test_tecnico_nuevo_queda_bloqueado_fuera_de_su_perfil(): void
    {
        $nuevo = $this->usuario(6);
        Sanctum::actingAs($nuevo);

        $this->getJson('/api/ui/settings/bank-info')
            ->assertForbidden()
            ->assertJsonPath('reason', 'perfil_profesional_pendiente');

        $this->getJson('/api/tecnico/perfil-profesional')
            ->assertOk()
            ->assertJsonPath('data.bloqueado', true)
            ->assertJsonPath('data.obligatorio', true);
    }

    public function test_tecnico_existente_no_se_bloquea(): void
    {
        $this->assertFalse(PerfilProfesional::estaBloqueado($this->existente));
        Sanctum::actingAs($this->existente);
        $this->getJson('/api/ui/settings/bank-info')->assertOk();
    }

    public function test_otros_roles_no_se_bloquean(): void
    {
        Sanctum::actingAs($this->usuario(4));
        $this->getJson('/api/ui/settings/bank-info')->assertOk();
    }

    // ── Guardar y enviar ──

    public function test_clabe_invalida_no_se_guarda(): void
    {
        Sanctum::actingAs($this->usuario(6));
        $this->postJson('/api/tecnico/perfil-profesional', ['clabe' => '072910011958785446'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('clabe');
    }

    public function test_rfc_y_clabe_se_guardan_cifrados(): void
    {
        $tecnico = $this->usuario(8);
        $this->completarPerfil($tecnico);

        $crudo = DB::table('technician_profiles')->where('user_id', $tecnico->id)->first();
        $this->assertNotSame(self::CLABE_OK, $crudo->clabe);
        $this->assertNotSame('PEPJ800101AB1', $crudo->rfc);

        $perfil = TechnicianProfile::where('user_id', $tecnico->id)->first();
        $this->assertSame(self::CLABE_OK, $perfil->clabe);
        $this->assertSame('PEPJ800101AB1', $perfil->rfc);
        $this->assertSame(2, $tecnico->specialties()->count());
        $this->assertDatabaseHas('specialties', ['name' => 'Paneles solares']); // nueva, para todos
    }

    public function test_no_se_envia_incompleto(): void
    {
        Sanctum::actingAs($this->usuario(6));
        $this->postJson('/api/tecnico/perfil-profesional/enviar')
            ->assertStatus(422)
            ->assertJsonStructure(['faltantes' => ['clabe', 'ine_frente', 'especialidades']]);
    }

    public function test_flujo_completo_hasta_aprobacion(): void
    {
        $tecnico = $this->usuario(6);
        $root = $this->usuario(0);
        $admin = $this->usuario(1);
        $this->completarPerfil($tecnico);

        $this->postJson('/api/tecnico/perfil-profesional/enviar')
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_revision');
        // El aviso solo le llega a Root
        Notification::assertSentTo($root, \App\Notifications\MovimientoNotificacion::class);
        Notification::assertNotSentTo($admin, \App\Notifications\MovimientoNotificacion::class);
        $this->assertTrue(PerfilProfesional::estaBloqueado($tecnico->fresh()));

        Sanctum::actingAs($root);
        $this->getJson('/api/admin/perfiles-profesionales')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $tecnico->id);
        $this->postJson("/api/admin/perfiles-profesionales/{$tecnico->id}/aprobar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'aprobado')
            ->assertJsonPath('data.insignias.identidad_verificada', true);
        Notification::assertSentTo($tecnico, \App\Notifications\MovimientoNotificacion::class);

        $this->assertFalse(PerfilProfesional::estaBloqueado($tecnico->fresh()));
        Sanctum::actingAs($tecnico->fresh());
        $this->getJson('/api/ui/settings/bank-info')->assertOk();
    }

    public function test_cambiar_ine_ya_aprobado_vuelve_a_revision_sin_bloquear(): void
    {
        $tecnico = $this->usuario(6);
        $this->completarPerfil($tecnico);
        $this->postJson('/api/tecnico/perfil-profesional/enviar')->assertOk();
        Sanctum::actingAs($this->usuario(1));
        $this->postJson("/api/admin/perfiles-profesionales/{$tecnico->id}/aprobar")->assertOk();

        Sanctum::actingAs($tecnico->fresh());
        $this->post('/api/tecnico/perfil-profesional/ine', ['frente' => UploadedFile::fake()->image('nueva.jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_revision')
            ->assertJsonPath('data.insignias.identidad_verificada', false);
        $this->assertFalse(PerfilProfesional::estaBloqueado($tecnico->fresh()));
    }

    public function test_rechazo_requiere_motivo(): void
    {
        $tecnico = $this->usuario(6);
        $this->completarPerfil($tecnico);
        $this->postJson('/api/tecnico/perfil-profesional/enviar')->assertOk();

        Sanctum::actingAs($this->usuario(0));
        $this->postJson("/api/admin/perfiles-profesionales/{$tecnico->id}/rechazar")->assertStatus(422);
        $this->postJson("/api/admin/perfiles-profesionales/{$tecnico->id}/rechazar", ['motivo' => 'La INE está borrosa'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'rechazado')
            ->assertJsonPath('data.motivo_rechazo', 'La INE está borrosa');
        $this->assertTrue(PerfilProfesional::estaBloqueado($tecnico->fresh()));
    }

    public function test_solo_admin_y_root_validan(): void
    {
        Sanctum::actingAs($this->usuario(4));
        $this->getJson('/api/admin/perfiles-profesionales')->assertForbidden();
    }

    // ── Privacidad ──

    public function test_clabe_solo_la_ve_quien_lo_contrato(): void
    {
        $tecnico = $this->usuario(6);
        $this->completarPerfil($tecnico);

        $extrano = $this->usuario(4, ['tenant_id' => 50]);
        Sanctum::actingAs($extrano);
        $this->getJson("/api/tecnicos/{$tecnico->id}/perfil-profesional")
            ->assertOk()
            ->assertJsonPath('nivel', 'publico')
            ->assertJsonPath('data.emite_factura', true)
            ->assertJsonMissingPath('data.clabe')
            ->assertJsonMissingPath('data.rfc');

        $cliente = $this->usuario(4, ['tenant_id' => 60]);
        $clientId = DB::table('clients')->insertGetId(['user_id' => $cliente->id]);
        $propId = DB::table('properties')->insertGetId(['client_id' => $clientId]);
        DB::table('work_orders')->insert(['property_id' => $propId, 'tecnico_id' => $tecnico->id, 'status' => 'Asignado']);

        Sanctum::actingAs($cliente);
        $this->getJson("/api/tecnicos/{$tecnico->id}/perfil-profesional")
            ->assertOk()
            ->assertJsonPath('nivel', 'contratante')
            ->assertJsonPath('data.clabe', self::CLABE_OK)
            ->assertJsonMissingPath('data.faltantes');
    }

    public function test_ine_solo_para_el_tecnico_y_revisores(): void
    {
        $tecnico = $this->usuario(6);
        $this->completarPerfil($tecnico);

        Sanctum::actingAs($this->usuario(4));
        $this->get("/api/perfil-profesional/{$tecnico->id}/archivo/ine_frente", ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($this->usuario(0));
        $this->get("/api/perfil-profesional/{$tecnico->id}/archivo/ine_frente")->assertOk();
    }

    public function test_nadie_cambia_especialidades_ajenas(): void
    {
        // Un usuario sin empresa (sin TenantScope) era el que podía cambiar las de cualquiera
        $tecnico = $this->usuario(2);
        Sanctum::actingAs($this->existente);
        $this->postJson("/api/users/u_{$tecnico->id}/specialties", ['specialties' => ['Plomería']])->assertForbidden();

        Sanctum::actingAs($tecnico);
        $this->postJson("/api/users/u_{$tecnico->id}/specialties", ['specialties' => ['Plomería']])->assertOk();
    }
}
