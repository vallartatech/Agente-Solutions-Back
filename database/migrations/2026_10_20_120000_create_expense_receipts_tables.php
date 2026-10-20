<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Comprobantes de gastos (notas, tickets, facturas) que el equipo sube para una empresa.
    // Le llegan al secretario de esa empresa, que los confirma como recibidos.
    private array $secretarios = [
        'mantenere' => 'cris.chale.1234@gmail.com',
        'agente' => 't.montero@mantenere.com.mx',
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('users', 'billing_company')) {
            Schema::table('users', function (Blueprint $table) {
                // Empresa de la que este usuario recibe los comprobantes ('agente' | 'mantenere')
                $table->string('billing_company', 20)->nullable()->index();
            });
        }

        if (!Schema::hasTable('expense_receipts')) {
            Schema::create('expense_receipts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('company', 20)->index();
                $table->text('note');
                $table->decimal('amount', 12, 2)->nullable();
                $table->string('status', 20)->default('pendiente')->index();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('expense_receipt_files')) {
            Schema::create('expense_receipt_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('expense_receipt_id')->constrained('expense_receipts')->cascadeOnDelete();
                $table->string('url', 500);
                $table->string('public_id')->nullable();
                $table->string('mime', 100)->nullable();
                $table->timestamps();
            });
        }

        foreach ($this->secretarios as $empresa => $email) {
            DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->update(['billing_company' => $empresa]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_receipt_files');
        Schema::dropIfExists('expense_receipts');
        if (Schema::hasColumn('users', 'billing_company')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['billing_company']);
                $table->dropColumn('billing_company');
            });
        }
    }
};
