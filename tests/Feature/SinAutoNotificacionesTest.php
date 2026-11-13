<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\MovimientoNotificacion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Nadie recibe en la campanita un aviso de lo que él mismo hizo (App\Services\SinAutoNotificaciones).
class SinAutoNotificacionesTest extends TestCase
{
    private User $pedro;
    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Estas pruebas solo corren en SQLite en memoria.');
        }
        config(['services.onesignal.app_id' => null, 'services.onesignal.rest_api_key' => null]);

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->default(4);
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        $this->pedro = $this->usuario(4, 'pedro@red.mx');
        $this->tecnico = $this->usuario(6, 'tecnico@red.mx');
    }

    private function usuario(int $rol, string $email): User
    {
        $id = DB::table('users')->insertGetId(['role_id' => $rol, 'email' => $email, 'first_name' => 'Pedro', 'last_name' => 'Pech']);
        return User::withoutGlobalScopes()->find($id);
    }

    private function aviso(): MovimientoNotificacion
    {
        return new MovimientoNotificacion(['titulo' => '💬 Pedro Pech te envió un mensaje', 'mensaje' => 'Hola', 'url' => '/chats']);
    }

    public function test_el_autor_no_recibe_su_propio_aviso_pero_el_otro_si(): void
    {
        Sanctum::actingAs($this->pedro);

        $this->pedro->notify($this->aviso());
        $this->tecnico->notify($this->aviso());

        $this->assertSame(0, $this->pedro->notifications()->count());
        $this->assertSame(1, $this->tecnico->notifications()->count());
    }

    public function test_sin_sesion_los_avisos_salen_normal(): void
    {
        // Scheduler, comandos y colas: no hay autor, nada se bloquea
        $this->pedro->notify($this->aviso());
        $this->assertSame(1, $this->pedro->notifications()->count());
    }

    public function test_una_notificacion_puede_pedir_llegarle_al_autor(): void
    {
        Sanctum::actingAs($this->pedro);
        $aviso = new class(['titulo' => 'Recordatorio', 'mensaje' => 'Tu cita es mañana']) extends MovimientoNotificacion {
            public bool $paraElAutor = true;
        };

        $this->pedro->notify($aviso);
        $this->assertSame(1, $this->pedro->notifications()->count());
    }
}
