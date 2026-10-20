<?php

namespace App\Http\Controllers;

use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Notifications\ExpenseReceiptNotification;
use App\Services\OneSignalPush;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Comprobantes de gastos (notas, tickets, facturas).
 * - Root, admins y técnicos suben comprobantes eligiendo la empresa (Agente Solutions / Mantenere).
 * - Le llegan al secretario de esa empresa (users.billing_company), que los confirma como recibidos.
 * - Root ve los de ambas empresas. Todo queda en el historial.
 */
class ExpenseReceiptController extends Controller
{
    private const UPLOAD_ROLES = [0, 1, 2];
    private const MAX_FILES = 6;
    private const WITH = [
        'files:id,expense_receipt_id,url,mime',
        'sender:id,first_name,last_name,profile_picture,role_id',
        'confirmer:id,first_name,last_name',
    ];

    // Qué puede hacer el usuario actual (para mostrar u ocultar la bandeja en el frontend)
    public function status(Request $request)
    {
        $user = $request->user();
        $inbox = $this->inboxCompanies($user);

        return response()->json([
            'can_upload' => $this->canUpload($user),
            'inbox_companies' => $inbox,
            'pending_inbox' => $inbox
                ? ExpenseReceipt::whereIn('company', $inbox)->where('status', 'pendiente')->count()
                : 0,
            'companies' => ExpenseReceipt::COMPANIES,
        ]);
    }

    // ?vista=mios|bandeja &estado=pendiente|confirmado &empresa=agente|mantenere &q=texto &page=N
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ExpenseReceipt::with(self::WITH)->latest();

        if ($request->query('vista') === 'bandeja') {
            $inbox = $this->inboxCompanies($user);
            if (!$inbox) {
                return response()->json(['error' => 'No tienes acceso a la bandeja de comprobantes.'], 403);
            }
            $query->whereIn('company', $inbox);
        } else {
            $query->where('user_id', $user->id);
        }

        if (in_array($request->query('estado'), ['pendiente', 'confirmado'], true)) {
            $query->where('status', $request->query('estado'));
        }
        if (array_key_exists((string) $request->query('empresa'), ExpenseReceipt::COMPANIES)) {
            $query->where('company', $request->query('empresa'));
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($w) use ($q) {
                $w->where('note', 'like', "%{$q}%")
                    ->orWhereHas('sender', fn ($s) => $s->where(DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$q}%"));
            });
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$this->canUpload($user)) {
            return response()->json(['error' => 'No tienes permiso para subir comprobantes.'], 403);
        }

        try {
            $validated = $request->validate([
                'company' => ['required', Rule::in(array_keys(ExpenseReceipt::COMPANIES))],
                'note' => 'required|string|max:500',
                'amount' => 'nullable|numeric|min:0|max:9999999',
                'files' => 'required|array|min:1|max:' . self::MAX_FILES,
                'files.*' => 'file|mimes:jpg,jpeg,png,webp,heic,heif,pdf|max:10240',
            ], [
                'company.required' => 'Elige la empresa del comprobante.',
                'note.required' => 'Agrega una nota que explique el gasto.',
                'files.required' => 'Agrega al menos una foto del comprobante.',
                'files.max' => 'Máximo ' . self::MAX_FILES . ' archivos por comprobante.',
                'files.*.mimes' => 'Solo se aceptan fotos o PDF.',
                'files.*.max' => 'Cada archivo debe pesar menos de 10 MB.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        }

        // 1. Subir a Cloudinary antes de tocar la base de datos
        $subidos = [];
        try {
            $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
            foreach ($request->file('files') as $file) {
                $res = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                    'folder' => 'agente_comprobantes',
                    'resource_type' => 'auto',
                ]);
                $subidos[] = [
                    'url' => $res['secure_url'],
                    'public_id' => $res['public_id'] ?? null,
                    'mime' => $file->getMimeType(),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('Error subiendo comprobante a Cloudinary: ' . $e->getMessage());
            return response()->json(['error' => 'No se pudieron subir las fotos. Intenta de nuevo.'], 500);
        }

        // 2. Guardar el comprobante con sus archivos
        $receipt = DB::transaction(function () use ($user, $validated, $subidos) {
            $receipt = ExpenseReceipt::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'company' => $validated['company'],
                'note' => trim($validated['note']),
                'amount' => $validated['amount'] ?? null,
                'status' => 'pendiente',
            ]);
            $receipt->files()->createMany($subidos);
            return $receipt;
        });
        $receipt->load(self::WITH);

        // 3. Avisar a los secretarios de esa empresa
        $autor = trim("{$user->first_name} {$user->last_name}");
        $monto = $receipt->amount !== null ? ' · $' . number_format((float) $receipt->amount, 2) : '';
        $secretarios = User::withoutGlobalScopes()
            ->where('is_active', 1)
            ->where('billing_company', $receipt->company)
            ->where('id', '!=', $user->id)
            ->get();
        $push = $this->notify(
            $secretarios,
            new ExpenseReceiptNotification(
                $receipt,
                '🧾 Nuevo comprobante · ' . $receipt->companyName(),
                "{$autor}{$monto}: {$receipt->note}",
                '/comprobantes?vista=bandeja'
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Comprobante enviado.',
            'push' => $push,
            'receipt' => $receipt,
        ], 201);
    }

    // El secretario confirma que recibió el comprobante
    public function confirm(Request $request, $id)
    {
        $user = $request->user();
        $receipt = ExpenseReceipt::find($id);
        if (!$receipt) {
            return response()->json(['error' => 'Comprobante no encontrado.'], 404);
        }
        if (!in_array($receipt->company, $this->inboxCompanies($user), true)) {
            return response()->json(['error' => 'Solo el secretario de ' . $receipt->companyName() . ' puede confirmarlo.'], 403);
        }
        if ($receipt->status === 'confirmado') {
            return response()->json(['error' => 'Este comprobante ya estaba confirmado.'], 422);
        }

        $receipt->status = 'confirmado';
        $receipt->confirmed_by = $user->id;
        $receipt->confirmed_at = now();
        $receipt->save();
        $receipt->load(self::WITH);

        // Avisar a quien lo envió
        $quien = trim("{$user->first_name} {$user->last_name}");
        $this->notify(
            User::withoutGlobalScopes()->whereKey($receipt->user_id)->where('id', '!=', $user->id)->get(),
            new ExpenseReceiptNotification(
                $receipt,
                '✅ Comprobante recibido · ' . $receipt->companyName(),
                "{$quien} confirmó tu comprobante: {$receipt->note}",
                '/comprobantes'
            )
        );

        return response()->json(['success' => true, 'receipt' => $receipt]);
    }

    // Quien lo envió puede borrarlo mientras siga pendiente (por si se equivocó); root siempre
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $receipt = ExpenseReceipt::find($id);
        if (!$receipt) {
            return response()->json(['error' => 'Comprobante no encontrado.'], 404);
        }

        $esRoot = (int) $user->role_id === 0;
        $esSuyoPendiente = (int) $receipt->user_id === (int) $user->id && $receipt->status === 'pendiente';
        if (!$esRoot && !$esSuyoPendiente) {
            return response()->json(['error' => 'Solo puedes borrar tus comprobantes que aún no se confirman.'], 403);
        }

        $receipt->delete();
        return response()->json(['success' => true]);
    }

    private function canUpload($user): bool
    {
        return $user && (in_array((int) $user->role_id, self::UPLOAD_ROLES, true) || !empty($user->billing_company));
    }

    // Empresas cuya bandeja puede ver: root ambas, el secretario solo la suya
    private function inboxCompanies($user): array
    {
        if (!$user) return [];
        if ((int) $user->role_id === 0) return array_keys(ExpenseReceipt::COMPANIES);
        return !empty($user->billing_company) && array_key_exists($user->billing_company, ExpenseReceipt::COMPANIES)
            ? [$user->billing_company]
            : [];
    }

    private function notify($recipients, ExpenseReceiptNotification $notification): array
    {
        if ($recipients->isEmpty()) {
            return ['sent' => false, 'recipients' => 0, 'subscribed' => 0, 'id' => null, 'error' => 'No hay un secretario asignado a esta empresa.'];
        }
        try {
            Notification::send($recipients, $notification);
            return OneSignalPush::sendToUsers(
                $recipients->pluck('id')->all(),
                $notification->pushTitle(),
                $notification->pushBody(),
                $notification->url(),
                ['alert_type' => 'expense_receipt']
            );
        } catch (\Throwable $e) {
            Log::error('Error notificando comprobante: ' . $e->getMessage());
            return ['sent' => false, 'recipients' => $recipients->count(), 'subscribed' => null, 'id' => null, 'error' => 'Error interno al notificar.'];
        }
    }
}
