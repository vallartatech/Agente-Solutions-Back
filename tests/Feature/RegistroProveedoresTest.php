<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PerfilProfesional;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Registro público de los tres proveedores de Agente Market, de punta a punta:
// técnico independiente (6), contratista (7) y técnico de cuadrilla (8).
// En testing no se valida el reCAPTCHA.
class RegistroProveedoresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }

        self::olvidarTablaPerfiles();
        Notification::fake();
        $this->crearEsquema();
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

    /** Columnas de users y tenants como en la base real. */
    private function crearEsquema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->default(1);
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone_number')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('profile_picture')->nullable();
            $t->string('cover_picture')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(1);
            $t->string('subscription_status')->nullable();
            $t->timestamp('subscription_start')->nullable();
            $t->timestamp('subscription_expires_at')->nullable();
            $t->decimal('subscription_amount', 8, 2)->nullable();
            $t->decimal('rating_stars_avg', 3, 1)->nullable();
            $t->decimal('rating_time_avg', 3, 1)->nullable();
            $t->integer('total_reviews_count')->default(0);
            $t->rememberToken();
            $t->string('approval_status', 50)->default('approved');
            $t->timestamp('privacy_accepted_at')->nullable();
            $t->timestamp('deletion_requested_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->string('logo_url')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('status')->nullable();
            $t->string('membership_type')->nullable();
            $t->integer('max_properties')->nullable();
            $t->integer('max_clients')->nullable();
            $t->integer('extra_properties_count')->default(0);
            $t->string('billing_cycle')->nullable();
            $t->string('subscription_status')->nullable();
            $t->timestamp('subscription_start')->nullable();
            $t->timestamp('subscription_expires_at')->nullable();
            $t->decimal('subscription_amount', 8, 2)->nullable();
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
            $t->string('email')->nullable();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
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

    /** Lo mismo que manda el formulario "Crear cuenta". */
    private function registrar(int $rol, string $nombre, array $extra = [])
    {
        return $this->postJson('/api/registro-usuario', array_merge([
            'first_name' => $nombre,
            'last_name' => 'Prueba',
            'email' => strtolower($nombre) . '@prueba.mx',
            'phone_number' => '999' . random_int(1000000, 9999999),
            'password' => 'secreto123',
            'role_id' => $rol,
            'acepta_privacidad' => true,
        ], $extra));
    }

    private function entrar(string $nombre)
    {
        return $this->postJson('/api/login', ['email' => strtolower($nombre) . '@prueba.mx', 'password' => 'secreto123']);
    }

    public function test_tecnico_independiente_se_registra_y_entra_a_completar_su_perfil(): void
    {
        $this->registrar(6, 'Ivan')->assertCreated()->assertJsonPath('status', 'active');

        $user = User::withoutGlobalScopes()->where('email', 'ivan@prueba.mx')->first();
        $this->assertSame('approved', $user->approval_status);
        $this->assertEquals(1, $user->is_active);
        $this->assertNull($user->tenant_id);
        $this->assertNotNull($user->privacy_accepted_at);

        $this->entrar('Ivan')->assertOk()
            ->assertJsonPath('user.role_id', 6)
            ->assertJsonPath('perfil_profesional.bloqueado', true);
    }

    public function test_contratista_se_registra_con_su_cuadrilla_y_entra_a_completar_su_perfil(): void
    {
        $this->registrar(7, 'Carlos')->assertCreated();

        $user = User::withoutGlobalScopes()->where('email', 'carlos@prueba.mx')->first();
        $this->assertSame('approved', $user->approval_status);
        $this->assertNotNull($user->tenant_id);
        $tenant = DB::table('tenants')->find($user->tenant_id);
        $this->assertSame('market_contractor', $tenant->membership_type);
        $this->assertEquals($user->id, $tenant->owner_user_id);
        $this->assertStringStartsWith('AUT_C_', $tenant->code);

        $this->entrar('Carlos')->assertOk()
            ->assertJsonPath('user.role_id', 7)
            ->assertJsonPath('perfil_profesional.bloqueado', true);

        // Hasta que Root valide su perfil no ve "Mi cuadrilla"; después le da el código para invitar
        $this->actingAs($user, 'sanctum')->getJson('/api/cuadrilla')
            ->assertStatus(403)->assertJsonPath('reason', 'perfil_profesional_pendiente');
        $this->validarPerfil($user);
        $this->getJson('/api/cuadrilla')->assertOk()->assertJsonPath('cuadrilla.codigo', $tenant->code);
    }

    /** Lo que hace Root al aprobar el perfil profesional. */
    private function validarPerfil(User $user): void
    {
        DB::table('technician_profiles')->updateOrInsert(['user_id' => $user->id], [
            'obligatorio' => true, 'estado' => 'aprobado', 'aprobado_at' => now(),
        ]);
    }

    public function test_tecnico_de_cuadrilla_se_registra_con_el_codigo_y_entra_cuando_lo_aprueba_su_contratista(): void
    {
        $this->registrar(7, 'Carlos')->assertCreated();
        $contratista = User::withoutGlobalScopes()->where('email', 'carlos@prueba.mx')->first();
        $this->validarPerfil($contratista);
        $codigo = DB::table('tenants')->where('id', $contratista->tenant_id)->value('code');

        $this->registrar(8, 'Ana', ['company_code' => $codigo])
            ->assertCreated()->assertJsonPath('status', 'pending_approval');
        $ana = User::withoutGlobalScopes()->where('email', 'ana@prueba.mx')->first();
        $this->assertEquals($contratista->tenant_id, $ana->tenant_id);

        // Mientras no lo aprueben no entra
        $this->entrar('Ana')->assertStatus(403)->assertJsonPath('status', 'pending');

        // El contratista lo ve en su Sala de espera y lo aprueba
        $this->actingAs($contratista, 'sanctum');
        $sala = $this->getJson('/api/tenants/pending-technicians')->assertOk()->json('pending_technicians');
        $this->assertContains($ana->id, collect($sala)->pluck('id')->all());
        $this->postJson("/api/tenants/approve-technician/{$ana->id}")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->entrar('Ana')->assertOk()
            ->assertJsonPath('user.role_id', 8)
            ->assertJsonPath('perfil_profesional.bloqueado', true);
    }

    public function test_el_formulario_verifica_el_codigo_del_contratista(): void
    {
        $this->registrar(7, 'Carlos')->assertCreated();
        $contratista = User::withoutGlobalScopes()->where('email', 'carlos@prueba.mx')->first();
        $codigo = DB::table('tenants')->where('id', $contratista->tenant_id)->value('code');

        $this->getJson('/api/cuadrilla/verificar-codigo?codigo=' . urlencode($codigo))->assertOk()
            ->assertJsonPath('valido', true)
            ->assertJsonPath('codigo', $codigo)
            ->assertJsonPath('contratista', 'Carlos Prueba')
            ->assertJsonPath('llena', false);
        $this->getJson('/api/cuadrilla/verificar-codigo?codigo=NO_EXISTE')->assertNotFound()->assertJsonPath('valido', false);
        $this->getJson('/api/cuadrilla/verificar-codigo')->assertNotFound();

        // El código de otra empresa que no es cuadrilla tampoco sirve para registrarse
        DB::table('tenants')->insert(['name' => 'Inmobiliaria', 'code' => 'AUT_E_1', 'membership_type' => 'market_property_manager']);
        $this->getJson('/api/cuadrilla/verificar-codigo?codigo=AUT_E_1')->assertNotFound();
        $this->registrar(8, 'Beto', ['company_code' => 'AUT_E_1'])->assertStatus(422)->assertJsonValidationErrors('company_code');
    }

    public function test_tecnico_de_cuadrilla_sin_codigo_valido_no_se_registra(): void
    {
        $this->registrar(8, 'Beto', ['company_code' => 'NO_EXISTE'])
            ->assertStatus(422)->assertJsonValidationErrors('company_code');
        $this->registrar(8, 'Beto')
            ->assertStatus(422)->assertJsonValidationErrors('company_code');
        $this->assertFalse(User::withoutGlobalScopes()->where('email', 'beto@prueba.mx')->exists());
    }
}
