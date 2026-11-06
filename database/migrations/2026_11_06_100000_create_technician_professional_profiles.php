<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil profesional de los técnicos de la red (roles 6 y 8): cobro y facturación,
 * experiencia, currículum e INE, con revisión de Admin/Root.
 *
 * Los técnicos nuevos no pueden usar su cuenta hasta que Admin/Root apruebe su perfil
 * (obligatorio = true). A los que ya existían se les crea su perfil con obligatorio = false:
 * siguen trabajando y pueden llenarlo cuando quieran para obtener las insignias.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('technician_profiles')) {
            Schema::create('technician_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();

                // Revisión: incompleto → en_revision → aprobado | rechazado
                $table->boolean('obligatorio')->default(true);
                $table->string('estado', 20)->default('incompleto');
                $table->text('motivo_rechazo')->nullable();
                $table->timestamp('enviado_at')->nullable();
                $table->timestamp('aprobado_at')->nullable(); // primera aprobación: desde aquí ya no se bloquea
                $table->unsignedBigInteger('revisado_por')->nullable();
                $table->timestamp('revisado_at')->nullable();
                $table->timestamp('identidad_verificada_at')->nullable();
                $table->timestamp('estudios_verificados_at')->nullable();

                // Cobro y facturación (RFC y CLABE van cifrados: columnas text)
                $table->boolean('emite_factura')->nullable();
                $table->text('rfc')->nullable();
                $table->string('razon_social')->nullable();
                $table->string('banco', 100)->nullable();
                $table->string('titular_cuenta')->nullable();
                $table->text('clabe')->nullable();
                $table->boolean('acepta_efectivo')->nullable();
                $table->boolean('tiene_terminal')->nullable();

                // Experiencia y currículum
                $table->unsignedSmallInteger('anios_experiencia')->nullable();
                $table->string('nivel_estudios', 40)->nullable();
                $table->text('presentacion')->nullable();
                $table->boolean('sin_cursos')->default(false);
                $table->boolean('sin_empleos')->default(false);

                // INE (almacenamiento privado)
                $table->string('ine_frente_path')->nullable();
                $table->string('ine_reverso_path')->nullable();

                $table->timestamps();
                $table->index('estado');
            });
        }

        if (!Schema::hasTable('technician_courses')) {
            Schema::create('technician_courses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('nombre');
                $table->string('institucion')->nullable();
                $table->unsignedSmallInteger('anio')->nullable();
                $table->string('archivo_path')->nullable(); // diploma (privado)
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('technician_experiences')) {
            Schema::create('technician_experiences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('empresa');
                $table->string('puesto')->nullable();
                $table->unsignedSmallInteger('desde_anio')->nullable();
                $table->unsignedSmallInteger('hasta_anio')->nullable(); // null = trabajo actual
                $table->text('descripcion')->nullable();
                $table->timestamps();
            });
        }

        // Técnicos de la red que ya existían: no se les bloquea la cuenta
        $existentes = DB::table('users')->whereIn('role_id', [6, 8])->pluck('id');
        $yaTienen = DB::table('technician_profiles')->pluck('user_id')->all();
        foreach ($existentes as $userId) {
            if (in_array($userId, $yaTienen)) {
                continue;
            }
            DB::table('technician_profiles')->insert([
                'user_id' => $userId,
                'obligatorio' => false,
                'estado' => 'incompleto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_experiences');
        Schema::dropIfExists('technician_courses');
        Schema::dropIfExists('technician_profiles');
    }
};
