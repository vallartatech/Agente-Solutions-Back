<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Datos bancarios para pagos por transferencia: valores por defecto, quién los edita y validación.
class DatosBancariosTest extends TestCase
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
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->string('setting_key')->unique();
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
    }

    private function usuario(int $rol): User
    {
        return User::forceCreate(['role_id' => $rol, 'first_name' => "Rol {$rol}", 'email' => "rol{$rol}@test.mx"]);
    }

    public function test_sin_configurar_devuelve_banorte_por_defecto(): void
    {
        Sanctum::actingAs($this->usuario(3));

        $this->getJson('/api/ui/settings/bank-info')
            ->assertOk()
            ->assertJsonPath('data.banco', 'Banorte')
            ->assertJsonPath('data.cuenta', '1195878544')
            ->assertJsonPath('data.clabe', '072910011958785445');
    }

    public function test_requiere_sesion_para_leer(): void
    {
        $this->getJson('/api/ui/settings/bank-info')->assertUnauthorized();
    }

    public function test_admin_los_edita_y_todos_ven_el_cambio(): void
    {
        Sanctum::actingAs($this->usuario(1));
        $this->postJson('/api/ui/settings/bank-info', [
            'banco' => 'BBVA', 'titular' => 'Agente Solutions', 'cuenta' => '0123 456789', 'clabe' => '012180001234567891',
        ])->assertOk()->assertJsonPath('data.cuenta', '0123456789');

        Sanctum::actingAs($this->usuario(3));
        $this->getJson('/api/ui/settings/bank-info')
            ->assertJsonPath('data.banco', 'BBVA')
            ->assertJsonPath('data.clabe', '012180001234567891');
    }

    public function test_cliente_y_tecnico_no_pueden_editar(): void
    {
        foreach ([2, 3, 5] as $rol) {
            Sanctum::actingAs($this->usuario($rol));
            $this->postJson('/api/ui/settings/bank-info', ['banco' => 'X', 'clabe' => '012180001234567891'])
                ->assertForbidden();
        }
    }

    public function test_clabe_debe_tener_18_digitos(): void
    {
        Sanctum::actingAs($this->usuario(0));
        $this->postJson('/api/ui/settings/bank-info', ['banco' => 'Banorte', 'clabe' => '0729100119'])
            ->assertStatus(422);
    }
}
