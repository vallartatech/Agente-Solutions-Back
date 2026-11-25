<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cláusula del Mercado: técnicos y clientes de la red (4-9), nuevos y ya registrados, la aceptan una vez.
 * El técnico sin validar también puede aceptarla (el candado del perfil la deja pasar).
 */
class TerminosMercadoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->default(1);
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('email')->nullable();
            $t->string('approval_status')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_11_25_110000_add_terminos_mercado_to_users.php'))->up();
    }

    private function usuario(int $rol): User
    {
        $id = DB::table('users')->insertGetId(['role_id' => $rol, 'email' => "r{$rol}@test.mx"]);
        return User::withoutGlobalScopes()->find($id);
    }

    public function test_el_cliente_de_la_red_la_acepta_una_vez(): void
    {
        $cliente = $this->usuario(4);
        Sanctum::actingAs($cliente);

        $this->getJson('/api/mercado/terminos')->assertOk()
            ->assertJsonPath('aplica', true)->assertJsonPath('aceptado', false);
        $this->postJson('/api/mercado/terminos')->assertStatus(422);
        $this->postJson('/api/mercado/terminos', ['acepto' => true])->assertOk();
        $this->getJson('/api/mercado/terminos')->assertOk()->assertJsonPath('aceptado', true);

        // Volver a aceptar no cambia la fecha original
        $fecha = DB::table('users')->where('id', $cliente->id)->value('terminos_mercado_at');
        $this->travel(1)->days();
        $this->postJson('/api/mercado/terminos', ['acepto' => true])->assertOk();
        $this->assertSame($fecha, DB::table('users')->where('id', $cliente->id)->value('terminos_mercado_at'));
    }

    public function test_el_tecnico_sin_validar_tambien_puede_aceptarla(): void
    {
        Schema::create('technician_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('estado')->default('incompleto');
            $t->timestamps();
        });
        Sanctum::actingAs($this->usuario(6));
        $this->getJson('/api/mercado/terminos')->assertOk()->assertJsonPath('aceptado', false);
        $this->postJson('/api/mercado/terminos', ['acepto' => true])->assertOk();
    }

    public function test_los_usuarios_internos_no_la_necesitan(): void
    {
        Sanctum::actingAs($this->usuario(1));
        $this->getJson('/api/mercado/terminos')->assertOk()
            ->assertJsonPath('aplica', false)->assertJsonPath('aceptado', true);
    }
}
