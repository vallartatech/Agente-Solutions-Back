<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // QR de acceso que algunos edificios entregan para entrar a la propiedad.
    // Es temporal: vive mientras el trabajo (o su lote) está abierto y se borra al finalizarlo.
    // La imagen se guarda cifrada en la base de datos (no en Cloudinary) para que nunca exista
    // una URL pública y para que al borrarla no quede ninguna copia.
    public function up(): void
    {
        if (!Schema::hasTable('access_qrs')) {
            Schema::create('access_qrs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('property_id')->nullable()->constrained('properties')->cascadeOnDelete();
                // Alcance: una orden suelta, un servicio, o un lote completo de órdenes (batch_id + property_id)
                $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->cascadeOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('services')->cascadeOnDelete();
                $table->string('batch_id')->nullable()->index();
                $table->unsignedTinyInteger('slot')->default(1); // 1 o 2
                $table->string('label', 80)->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->string('mime', 50);
                $table->longText('image'); // base64 cifrado con APP_KEY
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Bitácora: quién subió, vio o eliminó cada QR. Se conserva aunque el QR ya no exista.
        if (!Schema::hasTable('access_qr_logs')) {
            Schema::create('access_qr_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('property_id')->nullable()->index();
                $table->unsignedBigInteger('work_order_id')->nullable()->index();
                $table->unsignedBigInteger('service_id')->nullable()->index();
                $table->string('batch_id')->nullable();
                $table->unsignedTinyInteger('slot')->nullable();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('action', 30); // subido | reemplazado | visto | eliminado | eliminado_auto
                $table->string('detail', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_qr_logs');
        Schema::dropIfExists('access_qrs');
    }
};
