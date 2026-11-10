<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eliminación de cuentas (política de Google Play) y consentimientos.
 *
 * users.approval_status (varchar) gana dos valores:
 *   - pending_deletion: el titular pidió eliminar su cuenta; se anonimiza a los 30 días.
 *   - anonymized:       datos personales ya borrados (la fila se conserva por integridad).
 *
 * Copia en SQL: database/sql/2026_11_10_account_deletion.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'deletion_requested_at')) {
                $table->timestamp('deletion_requested_at')->nullable()->index();
            }
            if (!Schema::hasColumn('users', 'anonymized_at')) {
                $table->timestamp('anonymized_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'privacy_accepted_at')) {
                $table->timestamp('privacy_accepted_at')->nullable();
            }
        });

        Schema::table('technician_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('technician_profiles', 'consentimiento_datos_at')) {
                $table->timestamp('consentimiento_datos_at')->nullable();
            }
        });

        // Cuentas que ya pidieron eliminarse con el botón anterior (solo se desactivaban):
        // pasan a eliminación pendiente con 30 días contados desde hoy.
        DB::table('users')
            ->where('approval_status', 'deleted_by_user')
            ->update(['approval_status' => 'pending_deletion', 'deletion_requested_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('approval_status', 'pending_deletion')
            ->update(['approval_status' => 'deleted_by_user']);

        Schema::table('technician_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('technician_profiles', 'consentimiento_datos_at')) {
                $table->dropColumn('consentimiento_datos_at');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['deletion_requested_at', 'anonymized_at', 'privacy_accepted_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    if ($col === 'deletion_requested_at') $table->dropIndex(['deletion_requested_at']);
                    $table->dropColumn($col);
                }
            }
        });
    }
};
