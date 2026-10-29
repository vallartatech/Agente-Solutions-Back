<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Property;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\WorkOrder;
use App\Models\User;
use App\Notifications\WorkOrderAssigned;
use App\Notifications\WorkOrderRescheduledTechnician;
use Illuminate\Support\Facades\Notification;
use App\Notifications\WorkOrderCancelledNotification;
// Importamos la API pura de Cloudinary (La Opción Nuclear)
use Cloudinary\Cloudinary;

class PropertyController extends Controller
{
    // ---------------------------------------------------
    // 1. GUARDAR PROPIEDAD
    // ---------------------------------------------------
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'estado' => 'required|string',
            'municipio' => 'required|string',
            'colonia' => 'required|string',
            'calle' => 'required|string',
            'numero' => 'required|string',
            'property_name' => 'nullable|string|max:191',
            'facade_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240', // Límite de 10MB
        ]);

        // FORZAMOS LA LECTURA DESDE SANCTUM (El Gafete)
        $user = auth('sanctum')->user();
        if (!$user) {
            return response()->json(['error' => 'No autorizado. Token inválido o ausente.'], 401);
        }

        $clientId = null;

        if ($user->role_id == 3) {

            // 🔥 AUTO-REPARADOR NIVEL DIOS 🔥
            // Buscamos por ID o por Correo para que no haya duplicados
            $cliente = DB::table('clients')
                ->where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->first();

            if (!$cliente) {
                // 1. Si de verdad no existe, lo creamos
                $clientId = DB::table('clients')->insertGetId([
                    'user_id' => $user->id,
                    'name' => trim($user->first_name . ' ' . $user->last_name) ?: 'Cliente Web',
                    'email' => $user->email,
                    'phone' => $user->phone_number ?? 'Sin teléfono',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                // 2. Si ya existía el correo pero estaba desvinculado de este nuevo ID, lo reconectamos silenciosamente
                if ($cliente->user_id !== $user->id) {
                    DB::table('clients')->where('id', $cliente->id)->update([
                        'user_id' => $user->id
                    ]);
                }
                $clientId = $cliente->id;
            }

        } else {
            $clientId = $request->client_id;
        }

        // --- SUBIDA A CLOUDINARY (Fachadas) ---
        $uploadedFileUrl = null;
        if ($request->hasFile('facade_photo')) {
            $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
            $respuestaNube = $cloudinary->uploadApi()->upload($request->file('facade_photo')->getRealPath(), [
                'folder' => 'agente_propiedades' // Guardamos las casas en su propia carpeta en la nube
            ]);
            $uploadedFileUrl = $respuestaNube['secure_url'];
        }

        // Lógica de CURP Personalizado
        $tipo = strtoupper(substr($request->type, 0, 2));
        $estado_limpio = Str::ascii($request->estado);
        $estado_curp = strtoupper(substr($estado_limpio, 0, 3));
        $muni_limpio = Str::ascii($request->municipio);
        $municipio_curp = strtoupper(substr($muni_limpio, 0, 3));
        $colonia = strtoupper(substr($request->colonia, 0, 3));
        // Limpiamos calle y número de símbolos como # que rompen las URLs
        $calle_curp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->calle));
        $numero_curp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->numero));
        $random = strtoupper(Str::random(3));
        $custom_curp = "{$tipo}-{$estado_curp}-{$municipio_curp}-{$colonia}-{$calle_curp}-{$numero_curp}-{$random}";

        $direccion_completa = $this->componerDireccion($request->only(['calle', 'numero', 'cruzamientos', 'colonia', 'municipio', 'estado']));

        // Verificar límite de propiedades en el Plan del Autónomo
        if ($user->tenant_id) {
            $tenant = \App\Models\Tenant::find($user->tenant_id);
            if ($tenant && !$tenant->canAddProperty()) {
                if ($tenant->membership_type === 'autonomo_personal') {
                    return response()->json([
                        'error' => 'Has alcanzado el límite de ' . (($tenant->max_properties ?? 3) + ($tenant->extra_properties_count ?? 0)) . ' propiedades en tu Plan Personal. Adquiere una propiedad extra por $79.99 MXN para continuar.',
                        'limit_reached' => true,
                        'requires_extra_property' => true,
                        'extra_cost' => 79.99,
                        'tenant_id' => $tenant->id
                    ], 403);
                } else {
                    return response()->json([
                        'error' => 'Has alcanzado el límite máximo de ' . ($tenant->max_properties ?? 30) . ' propiedades en tu Plan Empresarial.',
                        'limit_reached' => true
                    ], 403);
                }
            }
        }

        $property = new Property();
        $property->client_id = $clientId;
        $property->tenant_id = $user->tenant_id;
        $property->type = $request->type;
        $property->state = $request->estado;
        $property->address = $direccion_completa;
        $property->municipio = $request->municipio;
        $property->colonia = $request->colonia;
        $property->calle = $request->calle;
        $property->numero = $request->numero;
        $property->cruzamientos = $request->cruzamientos ?: null;
        $property->coordinates = $request->coordinates;
        $property->custom_curp = $custom_curp;
        $property->property_name = $request->property_name;

        // GUARDAMOS LA URL DIRECTA DE LA NUBE (O NULL SI NO SUBIERON NADA)
        $property->facade_photo_path = $uploadedFileUrl;

        $property->save();

        return response()->json([
            'message' => 'Propiedad guardada con éxito',
            'property' => $property
        ], 201);
    }

    // ---------------------------------------------------
    // 2. OBTENER PROPIEDADES (Para la tabla de React)
    // ---------------------------------------------------
    public function index(Request $request)
    {
        try {
            // FORZAMOS LA LECTURA DESDE SANCTUM
            $user = auth('sanctum')->user();

            if (!$user) {
                return response()->json([
                    'error' => 'No autorizado. El token es inválido o no se recibió correctamente.'
                ], 401);
            }

            $query = Property::with(['client', 'tenant'])->orderByDesc('created_at');

            // Filtrado basado en el rol (Si es Cliente 3, solo ve las suyas)
            $cliente = null;
            if ($user->role_id == 3) {
                $cliente = DB::table('clients')->where('user_id', $user->id)->first();
                if ($cliente) {
                    $sharedPropertyIds = DB::table('property_shares')->where('client_id', $cliente->id)->pluck('property_id');
                    $query->where(function ($q) use ($cliente, $sharedPropertyIds) {
                        $q->where('client_id', $cliente->id)
                            ->orWhereIn('id', $sharedPropertyIds);
                    });
                } else {
                    // Si el usuario no tiene perfil, le devolvemos una lista vacía
                    return response()->json([], 200);
                }
            } elseif ($user->role_id == 4 || $user->role_id == 5) {
                // Autónomo: ve las propiedades de su empresa, de sus clientes, o creadas bajo su perfil
                $clienteIds = DB::table('clients')
                    ->where('user_id', $user->id)
                    ->orWhere('email', $user->email)
                    ->orWhere('tenant_id', $user->tenant_id)
                    ->pluck('id');

                $query->where(function ($q) use ($user, $clienteIds) {
                    $q->where('tenant_id', $user->tenant_id)
                        ->orWhereIn('client_id', $clienteIds)
                        ->orWhereHas('client', function ($qc) use ($user) {
                            $qc->where('tenant_id', $user->tenant_id);
                        });
                });
            } elseif ($user->role_id !== 0 && $user->tenant_id) {
                // Admin o técnico de un tenant
                $query->where(function ($q) use ($user) {
                    $q->where('tenant_id', $user->tenant_id)
                        ->orWhereHas('client', function ($qc) use ($user) {
                            $qc->where('tenant_id', $user->tenant_id);
                        });
                });
            }

            $propiedades = $query->get();

            $formateadas = $propiedades->map(function ($p) use ($cliente, $user) {
                $tienePendiente = DB::table('services')
                    ->where('property_id', $p->id)
                    ->whereNotIn('status', ['Finalizado', 'Cancelado'])
                    ->exists();

                // Buscamos el reporte técnico (Cualquier servicio vinculado a esta propiedad)
                $levantamiento = DB::table('services')
                    ->where('property_id', $p->id)
                    ->orderBy('id', 'desc') // El más reciente
                    ->first();

                // Buscamos si la propiedad ya tiene zonas principales (sin padre)
                $tieneZonas = DB::table('property_areas')
                    ->where('property_id', $p->id)
                    ->whereNull('parent_id')
                    ->exists();

                // Un levantamiento está realizado SOLO SI tiene zonas registradas
                $realizado = $tieneZonas;

                \Log::info("Propiedad {$p->id} ({$p->property_name}): Zonas detectadas = " . ($tieneZonas ? 'SI' : 'NO'));

                $is_shared_with_me = false;
                $is_shared_by_me = false;

                if ($cliente) {
                    if ($p->client_id !== $cliente->id) {
                        $is_shared_with_me = true;
                    } else {
                        $is_shared_by_me = DB::table('property_shares')->where('property_id', $p->id)->exists();
                    }
                } else if ($user->role_id == 0 || $user->role_id == 1) {
                    $is_shared_by_me = DB::table('property_shares')->where('property_id', $p->id)->exists();
                }

                return [
                    'id' => $p->id,
                    'tenant_id' => $p->tenant_id,
                    'folder_id' => $p->folder_id,
                    'tenant_name' => $p->tenant ? $p->tenant->name : ($p->tenant_id == 1 ? 'Agente Solutions' : null),
                    'client_id' => $p->client_id,
                    'client_email' => $p->client ? $p->client->email : null,
                    'propietario' => $p->client ? $p->client->name : 'Sin Propietario',
                    'nombre_propiedad' => $p->property_name ?? 'Propiedad sin nombre',
                    'direccion' => $p->address,
                    'fecha' => $p->created_at ? $p->created_at->format('Y-m-d') : 'Sin fecha',
                    'tipo' => strtoupper($p->type),
                    // Datos por separado para el formulario de edición
                    'type' => $p->type,
                    'estado' => $p->state,
                    'municipio' => $p->municipio,
                    'colonia' => $p->colonia,
                    'calle' => $p->calle,
                    'numero' => $p->numero,
                    'cruzamientos' => $p->cruzamientos,
                    'curp' => $p->custom_curp,
                    'coordenadas' => $p->coordinates,
                    'foto_url' => $p->facade_photo_path,
                    'created_at' => $p->created_at,
                    'has_pending_service' => $tienePendiente,
                    'id_levantamiento' => $levantamiento ? $levantamiento->id : null,
                    'levantamiento_realizado' => $realizado,
                    'is_shared_with_me' => $is_shared_with_me,
                    'is_shared_by_me' => $is_shared_by_me,
                ];
            });

            return response()->json($formateadas, 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al cargar propiedades: ' . $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 3. ELIMINAR PROPIEDAD
    // ---------------------------------------------------
    public function destroy($id)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['error' => 'No autorizado.'], 401);
            }

            $property = Property::findOrFail($id);

            // Verificar que no sea un usuario que solo tiene la propiedad compartida
            if ($user->role_id == 3) {
                $cliente = DB::table('clients')->where('user_id', $user->id)->first();
                if (!$cliente || $property->client_id !== $cliente->id) {
                    return response()->json(['error' => 'Acceso denegado. No eres el dueño principal de esta propiedad.'], 403);
                }
            }

            // Nota: Esto elimina fotos solo si quedaron algunas viejas guardadas en local.
            // Las de Cloudinary se quedan en la nube como respaldo.
            if ($property->facade_photo_path && !str_contains($property->facade_photo_path, 'cloudinary.com')) {
                Storage::disk('public')->delete($property->facade_photo_path);
            }

            // Eliminar servicios y levantamientos huérfanos asociados a esta propiedad
            DB::table('services')->where('property_id', $id)->delete();

            $property->delete();

            return response()->json(['message' => 'Propiedad eliminada con éxito'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al eliminar la propiedad: ' . $e->getMessage()], 500);
        }
    }

    // "Calle 60 #100 x 62 y 64, Col. Centro, Mérida, Yucatán" (mismo formato desde siempre)
    private function componerDireccion(array $d): string
    {
        $direccion = "Calle {$d['calle']} #{$d['numero']}";
        if (!empty($d['cruzamientos'])) {
            $direccion .= " x {$d['cruzamientos']}";
        }
        return $direccion . ", Col. {$d['colonia']}, {$d['municipio']}, {$d['estado']}";
    }

    // ---------------------------------------------------
    // 8. ACTUALIZAR PROPIEDAD (todos los datos del formulario de registro)
    //    Solo cambia lo que se envía. El código (custom_curp) no cambia.
    // ---------------------------------------------------
    public function updateProperty(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado.'], 401);

            $property = Property::findOrFail($id);

            // Un cliente solo edita las suyas (no las que le compartieron)
            if ((int) $user->role_id === 3) {
                $cliente = DB::table('clients')->where('user_id', $user->id)->first();
                if (!$cliente || $property->client_id !== $cliente->id) {
                    return response()->json(['error' => 'Solo el dueño principal puede editar esta propiedad.'], 403);
                }
            }

            // Validación
            try {
                $request->validate([
                    'property_name' => 'nullable|string|max:191',
                    'facade_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
                    'type' => 'nullable|string|max:50',
                    'estado' => 'nullable|string|max:191',
                    'municipio' => 'nullable|string|max:191',
                    'colonia' => 'nullable|string|max:191',
                    'calle' => 'nullable|string|max:191',
                    'numero' => 'nullable|string|max:191',
                    'cruzamientos' => 'nullable|string|max:191',
                    'coordinates' => 'nullable|string|max:100',
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
            }

            // 1. Nombre, tipo y ubicación GPS
            if ($request->has('property_name')) {
                $property->property_name = $request->property_name;
            }
            if ($request->filled('type')) {
                $property->type = $request->type;
            }
            if ($request->filled('coordinates')) {
                $property->coordinates = $request->coordinates;
            }

            // Dirección: se rearma solo si llegan las partes obligatorias del registro
            $partes = [
                'estado' => trim((string) ($request->estado ?? $property->state)),
                'municipio' => trim((string) $request->municipio),
                'colonia' => trim((string) $request->colonia),
                'calle' => trim((string) $request->calle),
                'numero' => trim((string) $request->numero),
                'cruzamientos' => trim((string) $request->cruzamientos),
            ];
            if ($partes['calle'] !== '' && $partes['numero'] !== '' && $partes['colonia'] !== '' && $partes['municipio'] !== '') {
                $property->state = $partes['estado'];
                $property->municipio = $partes['municipio'];
                $property->colonia = $partes['colonia'];
                $property->calle = $partes['calle'];
                $property->numero = $partes['numero'];
                $property->cruzamientos = $partes['cruzamientos'] !== '' ? $partes['cruzamientos'] : null;
                $property->address = $this->componerDireccion($partes);
            } elseif ($request->filled('estado')) {
                $property->state = $partes['estado'];
            }

            // 2. Actualizar Foto
            if ($request->hasFile('facade_photo')) {
                $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
                $respuestaNube = $cloudinary->uploadApi()->upload($request->file('facade_photo')->getRealPath(), [
                    'folder' => 'agente_propiedades'
                ]);
                $property->facade_photo_path = $respuestaNube['secure_url'];
            }

            $property->save();

            return response()->json([
                'message' => 'Propiedad actualizada con éxito',
                'property' => $property,
                'foto_url' => $property->facade_photo_path
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // EXPEDIENTE DE LA PROPIEDAD (vista "Detalle de la propiedad")
    // Propiedad + encargado (cliente dueño) + todos sus trabajos con técnicos,
    // visitas, cotizaciones, pagos y reportes, en el formato que espera el frontend.
    // ---------------------------------------------------
    public function expediente($id)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['error' => 'No autorizado.'], 401);
            }

            $property = Property::withoutGlobalScopes()->with('client')->find($id);
            if (!$property) {
                return response()->json(['error' => 'No se encontró la propiedad.'], 404);
            }
            if (!$this->puedeVerPropiedad($user, $property)) {
                return response()->json(['error' => 'No tienes acceso a esta propiedad.'], 403);
            }

            $workOrders = WorkOrder::withoutGlobalScopes()->with(['tecnico', 'technicians'])
                ->where('property_id', $property->id)->get();
            $services = \App\Models\Service::withoutGlobalScopes()->with(['technician', 'technicians'])
                ->where('property_id', $property->id)->get();

            // Cotizaciones y reportes de todos los trabajos en dos consultas
            $quotes = \App\Models\Quote::withoutGlobalScopes()
                ->where(fn ($q) => $q->whereIn('work_order_id', $workOrders->pluck('id'))->orWhereIn('service_id', $services->pluck('id')))
                ->orderBy('created_at')->get();
            $reports = \App\Models\WorkReport::with('technician:id,first_name,last_name')
                ->where(fn ($q) => $q->whereIn('work_order_id', $workOrders->pluck('id'))->orWhereIn('service_id', $services->pluck('id')))
                ->orderBy('created_at')->get();

            $fecha = fn ($v) => $v ? \Carbon\Carbon::parse($v)->toIso8601String() : null;
            $limpiar = fn ($d) => trim(preg_replace('/\[LOTE-[A-Z0-9]+\]\s*(\(\d+\/\d+\))?\s*/i', '', (string) $d));
            $tecnico = fn ($u) => [
                'id' => $u->id,
                'nombre' => trim("{$u->first_name} {$u->last_name}") ?: ($u->name ?? 'Técnico'),
                'telefono' => $u->phone_number,
                'email' => $u->email,
                'foto' => $u->profile_picture,
            ];
            $tecnicos = fn ($principal, $lista) => collect([$principal])->merge($lista)->filter()
                ->unique('id')->map($tecnico)->values();
            $cotizacion = fn ($c) => [
                'id' => $c->id,
                'folio' => null,
                'status' => $c->status,
                'payment_status' => $c->payment_status,
                'cash_confirmed' => (bool) $c->cash_confirmed,
                'advance_paid' => (bool) $c->advance_paid,
                'remaining_paid' => (bool) $c->remaining_paid,
                'total' => $c->estimated_amount,
                'file_path' => $c->file_path,
                'created_at' => $fecha($c->created_at),
                'updated_at' => $fecha($c->updated_at),
                'cash_confirmed_at' => $fecha($c->cash_confirmed_at),
                'advance_paid_at' => $fecha($c->advance_paid_at),
            ];
            $reporte = fn ($r) => [
                'id' => $r->id,
                'description' => $r->description,
                'image_url' => $r->image_url,
                'tecnico_nombre' => $r->technician ? trim("{$r->technician->first_name} {$r->technician->last_name}") : null,
                'created_at' => $fecha($r->created_at),
            ];

            $trabajos = $workOrders->map(fn ($wo) => [
                'uid' => "wo-{$wo->id}",
                'id' => $wo->id,
                'kind' => 'trabajo',
                'titulo' => $wo->type ? trim($wo->type . ($wo->equipment ? " - {$wo->equipment}" : '')) : ($wo->zone ?: 'Trabajo'),
                'tipo' => $wo->type,
                'zona' => $wo->zone,
                'descripcion' => $limpiar($wo->description),
                'status' => $wo->status,
                'prioridad' => $wo->priority,
                'scheduled_at' => $fecha($wo->scheduled_at),
                'arrival_status' => $wo->arrival_status,
                'arrived_at' => $fecha($wo->arrived_at),
                'second_visit_proposed_date' => $wo->second_visit_proposed_date,
                'second_visit_reason' => $wo->second_visit_reason,
                'created_at' => $fecha($wo->created_at),
                'updated_at' => $fecha($wo->updated_at),
                'fotos' => array_values(array_filter([$wo->evidence_path, $wo->evidence_path_2])),
                'tecnicos' => $tecnicos($wo->tecnico, $wo->technicians),
                'cotizaciones' => $quotes->where('work_order_id', $wo->id)->map($cotizacion)->values(),
                'reportes' => $reports->where('work_order_id', $wo->id)->map($reporte)->values(),
            ])->concat($services->map(fn ($s) => [
                'uid' => "sv-{$s->id}",
                'id' => $s->id,
                'kind' => (stripos((string) $s->title, 'levantamiento') !== false || stripos((string) $s->service_type, 'levantamiento') !== false)
                    ? 'levantamiento' : 'servicio',
                'titulo' => $s->title ?: 'Servicio',
                'tipo' => $s->service_type,
                'zona' => null,
                'descripcion' => $limpiar($s->description),
                'status' => $s->status,
                'prioridad' => $s->priority,
                'scheduled_at' => $fecha($s->scheduled_start),
                'arrival_status' => $s->arrival_status,
                'arrived_at' => $fecha($s->arrived_at),
                'second_visit_proposed_date' => $s->second_visit_proposed_date,
                'second_visit_reason' => $s->second_visit_reason,
                'created_at' => $fecha($s->created_at),
                'updated_at' => $fecha($s->updated_at),
                'fotos' => array_values(array_filter([$s->evidence_path])),
                'tecnicos' => $tecnicos($s->technician, $s->technicians),
                'cotizaciones' => $quotes->where('service_id', $s->id)->whereNull('work_order_id')->map($cotizacion)->values(),
                'reportes' => $reports->where('service_id', $s->id)->map($reporte)->values(),
            ]))->sortByDesc('created_at')->values();

            $cliente = $property->client;

            return response()->json([
                'propiedad' => array_merge($property->toArray(), [
                    'nombre_propiedad' => $property->property_name,
                ]),
                'encargado' => $cliente ? [
                    'name' => $cliente->name,
                    'phone' => $cliente->phone,
                    'email' => $cliente->email,
                    'profile_picture' => $cliente->profile_picture,
                ] : null,
                'trabajos' => $trabajos,
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error en expediente de propiedad: ' . $e->getMessage());
            return response()->json(['error' => 'No se pudo cargar la información de la propiedad.'], 500);
        }
    }

    // Misma regla de acceso que la lista de propiedades (index)
    private function puedeVerPropiedad($user, Property $property): bool
    {
        $rol = (int) $user->role_id;
        if ($rol === 0) {
            return true;
        }
        if ($rol === 3) {
            $cliente = DB::table('clients')->where('user_id', $user->id)->first();
            if (!$cliente) {
                return false;
            }
            return (int) $property->client_id === (int) $cliente->id
                || DB::table('property_shares')->where('property_id', $property->id)->where('client_id', $cliente->id)->exists();
        }
        // Tenant nulo y tenant 1 son el mismo equipo (Agente Solutions)
        if (empty($user->tenant_id) || (int) $user->tenant_id === 1) {
            return true;
        }
        return (int) $property->tenant_id === (int) $user->tenant_id
            || (int) optional($property->client)->tenant_id === (int) $user->tenant_id;
    }

    // ---------------------------------------------------
    // 4. DATOS DEL DASHBOARD DE LA PROPIEDAD
    // ---------------------------------------------------
    public function getDashboardData($id)
    {
        try {
            $propiedad = DB::table('properties')->where('id', $id)->first();
            if (!$propiedad)
                return response()->json(['error' => 'No encontrada'], 404);

            $stats = DB::table('work_orders')
                ->where('property_id', $id)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get()
                ->pluck('total', 'status');

            $sosCount = DB::table('work_orders')
                ->where('property_id', $id)
                ->where('status', '!=', 'Listo')
                ->where('priority', 'Urgente')
                ->count();

            $historial = DB::table('work_orders')
                ->leftJoin('users', 'work_orders.tecnico_id', '=', 'users.id')
                ->where('work_orders.property_id', $id)
                ->where('work_orders.status', 'Listo')
                ->select('work_orders.*', DB::raw("CONCAT(users.first_name, ' ', users.last_name) as tecnico_nombre"))
                ->orderBy('work_orders.updated_at', 'desc')
                ->limit(5)
                ->get();

            $cotizacionesCount = DB::table('quotes')
                ->leftJoin('services', 'quotes.service_id', '=', 'services.id')
                ->leftJoin('work_orders', 'quotes.work_order_id', '=', 'work_orders.id')
                ->where(function ($query) use ($id) {
                    $query->where('services.property_id', $id)
                        ->orWhere('work_orders.property_id', $id);
                })
                ->where(function ($q) {
                    $q->where('quotes.status', 'Pendiente')
                        ->orWhere('quotes.status', 'En proceso')
                        ->orWhere('quotes.status', 'like', '%Admin%');
                })
                ->count();

            $totalTareas = $sosCount + ($stats['Por Hacer'] ?? 0) + ($stats['En Proceso'] ?? 0) + ($stats['Listo'] ?? 0);
            $avanceObra = $totalTareas > 0 ? round((($stats['Listo'] ?? 0) / $totalTareas) * 100) : 0;

            // Buscamos el reporte técnico (Cualquier servicio vinculado a esta propiedad)
            $levantamiento = DB::table('services')
                ->where('property_id', $id)
                ->orderBy('id', 'desc')
                ->first();

            // Fetch Owner Info
            $ownerInfo = DB::table('clients')
                ->where('id', $propiedad->client_id)
                ->select('name', 'profile_picture')
                ->first();

            // Fetch Shared Users
            $sharedUsers = DB::table('property_shares')
                ->join('clients', 'property_shares.client_id', '=', 'clients.id')
                ->where('property_shares.property_id', $id)
                ->select('clients.id', 'clients.name', 'clients.profile_picture')
                ->get();

            $is_shared_with_me = false;
            $user = auth('sanctum')->user();
            if ($user && $user->role_id == 3) {
                $cliente = DB::table('clients')->where('user_id', $user->id)->first();
                if ($cliente && $propiedad->client_id !== $cliente->id) {
                    $is_shared_with_me = true;
                }
            }

            return response()->json([
                'propiedad' => $propiedad,
                'owner_info' => $ownerInfo,
                'shared_users' => $sharedUsers,
                'id_levantamiento' => $levantamiento ? $levantamiento->id : null,
                'is_shared_with_me' => $is_shared_with_me,
                'stats' => [
                    'sos' => $sosCount,
                    'pendientes' => $stats['Por Hacer'] ?? 0,
                    'proceso' => $stats['En Proceso'] ?? 0,
                    'listos' => $stats['Listo'] ?? 0,
                ],
                'historial' => $historial,
                'cotizaciones_pendientes' => $cotizacionesCount,
                'avance_obra' => $avanceObra
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 5. GUARDAR ÓRDENES DE TRABAJO
    // ---------------------------------------------------
    public function storeWorkOrder(Request $request)
    {
        try {
            $path = null;
            if ($request->hasFile('foto')) {
                $path = $request->file('foto')->store('work_orders', 'public');
            }

            $id = DB::table('work_orders')->insertGetId([
                'property_id' => $request->property_id,
                'zone' => $request->zona,
                'equipment' => $request->equipo,
                'description' => $request->descripcion,
                'evidence_path' => $path,
                'status' => 'Por Hacer',
                'priority' => 'Normal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['success' => true, 'id' => $id], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 6. OBTENER ÓRDENES DE TRABAJO
    // ---------------------------------------------------
    public function getWorkOrders($id)
    {
        try {
            $orders = DB::table('work_orders')
                ->leftJoin('users', 'work_orders.tecnico_id', '=', 'users.id')
                ->where('work_orders.property_id', $id)
                ->select(
                    'work_orders.*',
                    DB::raw("CONCAT(users.first_name, ' ', users.last_name) as tecnico_nombre")
                )
                ->get();

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 7. ACTUALIZAR ESTADO DE ÓRDENES
    // ---------------------------------------------------
    // Al quedar "Listo" por un admin se registra quién lo validó, cuándo y si había ticket de conformidad.
    // No bloquea: cerrar sin ticket se permite y queda a nombre de quien lo confirmó.
    // Si vuelve a abrirse, se borra el registro.
    private function registrarCierre(WorkOrder $workOrder, string $nuevoEstado, $user): void
    {
        $esAdmin = $user && in_array((int) $user->role_id, [0, 1]);
        if ($nuevoEstado === 'Listo' && $esAdmin && $workOrder->status !== $workOrder->getOriginal('status')) {
            $tieneTicket = \App\Models\WorkReport::where('work_order_id', $workOrder->id)->where('stage', 'ticket')->exists();
            $workOrder->ticket_status = $tieneTicket ? 'con_ticket' : 'sin_ticket';
            $workOrder->closed_by = $user->id;
            $workOrder->closed_at = now();
        } elseif (!in_array($nuevoEstado, ['Listo', 'Finalizado'], true)) {
            $workOrder->ticket_status = null;
            $workOrder->closed_by = null;
            $workOrder->closed_at = null;
        }
    }

    public function updateWorkOrderStatus(Request $request, $id)
    {
        $user = auth('sanctum')->user();
        // El Técnico Agente (rol 2) no puede finalizar: su "Listo" queda Por Aprobar hasta que un admin lo apruebe
        $nuevoEstado = ($request->status === 'Listo' && $user && (int) $user->role_id === 2)
            ? 'Por Aprobar'
            : $request->status;

        if (is_string($id) && str_starts_with($id, 'batch_')) {
            $batchId = str_replace('batch_', '', $id);
            $workOrders = WorkOrder::with('property')->where('batch_id', $batchId)->get();
            if ($workOrders->isEmpty()) {
                return response()->json(['error' => 'No se encontraron órdenes para este lote'], 404);
            }
            foreach ($workOrders as $wo) {
                $wo->status = $nuevoEstado;
                $wo->updated_at = now();
                $this->registrarCierre($wo, $nuevoEstado, $user);
                $wo->save();
            }
            return response()->json(['success' => true, 'status' => $nuevoEstado, 'message' => 'Estado del lote actualizado correctamente']);
        }

        try {
            $request->validate([
                'status' => 'required|in:Por Hacer,En Proceso,Por Aprobar,Listo,Rechazado,Cancelado'
            ]);

            $workOrder = WorkOrder::withoutGlobalScopes()->with('property')->findOrFail($id);
            $oldStatus = $workOrder->status;

            // Un trabajo ya aprobado no regresa a "Por Aprobar" si el técnico vuelve a finalizarlo
            if ($nuevoEstado === 'Por Aprobar' && $oldStatus === 'Listo') {
                $nuevoEstado = 'Listo';
            }

            $workOrder->status = $nuevoEstado;
            $workOrder->updated_at = now();
            $this->registrarCierre($workOrder, $nuevoEstado, $user);
            $workOrder->save();

            // Si se cancela el servicio (Rechazado o Cancelado), notificamos al Cliente y al Técnico
            if (in_array($request->status, ['Rechazado', 'Cancelado']) && !in_array($oldStatus, ['Rechazado', 'Cancelado'])) {
                try {
                    // 1. Notificación al Cliente
                    if ($workOrder->property && $workOrder->property->client_id) {
                        $client = \App\Models\Client::find($workOrder->property->client_id);
                        if ($client && $client->user_id) {
                            $userCliente = User::find($client->user_id);
                            if ($userCliente) {
                                Notification::send($userCliente, new WorkOrderCancelledNotification($workOrder, 'client'));
                                \Log::info("Notificación de trabajo cancelado enviada al cliente.");
                            }
                        }
                    }

                    // 2. Notificación a los Técnicos asignados
                    $tecnicos = $workOrder->technicians;
                    if ($tecnicos && $tecnicos->count() > 0) {
                        Notification::send($tecnicos, new WorkOrderCancelledNotification($workOrder, 'technician'));
                        \Log::info("Notificación de trabajo cancelado enviada a técnicos (relación N:M).");
                    }

                    if ($workOrder->tecnico_id) {
                        $tecnicoSolo = User::find($workOrder->tecnico_id);
                        if ($tecnicoSolo && (!$tecnicos || !$tecnicos->contains($tecnicoSolo->id))) {
                            Notification::send($tecnicoSolo, new WorkOrderCancelledNotification($workOrder, 'technician'));
                            \Log::info("Notificación de trabajo cancelado enviada al técnico individual.");
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error("Error enviando notificaciones de servicio cancelado: " . $e->getMessage());
                }
            }

            // Si el técnico lo manda a aprobación (o lo marca "Listo" otro rol), notificamos al Admin
            $esAdmin = $user && in_array((int) $user->role_id, [0, 1]);
            if (in_array($nuevoEstado, ['Por Aprobar', 'Listo']) && !in_array($oldStatus, ['Por Aprobar', 'Listo']) && !$esAdmin) {
                try {
                    $technicianName = $user ? ($user->first_name . ' ' . $user->last_name) : 'Un técnico';

                    $propertyName = $workOrder->property ? ($workOrder->property->property_name ?: $workOrder->property->address) : 'Propiedad desconocida';

                    // Solo al validador del equipo (si no hay, a los admins de ese equipo; ya no a los de otras empresas)
                    $destinatarios = \App\Http\Controllers\WorkValidationController::destinatarios(
                        $workOrder->tenant_id ? (int) $workOrder->tenant_id : null
                    );

                    Notification::send($destinatarios, new \App\Notifications\WorkOrderFinishedNotification($workOrder, $technicianName, $propertyName));
                    \Log::info("Notificación de trabajo finalizado enviada a " . $destinatarios->count() . " validador(es).");
                } catch (\Exception $e) {
                    \Log::error("Error enviando notificación de trabajo finalizado: " . $e->getMessage());
                }
            }

            // Un admin lo da por finalizado: avisamos a los técnicos asignados
            if ($nuevoEstado === 'Listo' && $oldStatus !== 'Listo' && $esAdmin) {
                try {
                    $adminName = trim($user->first_name . ' ' . $user->last_name) ?: 'Un administrador';
                    $idsTecnicos = $workOrder->technicians()->pluck('users.id')->push($workOrder->tecnico_id)
                        ->filter()->unique()->reject(fn ($tid) => (int) $tid === (int) $user->id);
                    $tecnicos = User::withoutGlobalScopes()->whereIn('id', $idsTecnicos)->get();
                    if ($tecnicos->count() > 0) {
                        Notification::send($tecnicos, new \App\Notifications\WorkOrderApprovedNotification($workOrder, $adminName));
                    }
                } catch (\Exception $e) {
                    \Log::error("Error enviando notificación de trabajo aprobado: " . $e->getMessage());
                }
            }

            return response()->json(['success' => true, 'status' => $nuevoEstado, 'message' => 'Estado actualizado']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 8. OBTENER PROPIEDAD POR CURP
    // ---------------------------------------------------
    public function getByCurp($curp)
    {
        try {
            // Normalización extrema: quitamos espacios y guiones tanto de la búsqueda como de la BD
            $curpLimpio = str_replace([' ', '-'], '', $curp);

            $property = Property::whereRaw("REPLACE(REPLACE(custom_curp, ' ', ''), '-', '') = ?", [$curpLimpio])
                ->first();

            if (!$property) {
                return response()->json([
                    'error' => 'Propiedad no encontrada',
                    'curp_buscado' => $curp,
                    'curp_normalizado' => $curpLimpio
                ], 404);
            }

            return response()->json($property, 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al buscar propiedad por CURP: ' . $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 9. ASIGNAR TÉCNICO A ORDEN DE TRABAJO
    // ---------------------------------------------------
    public function assignWorkOrder(Request $request, $id)
    {
        if (is_string($id) && str_starts_with($id, 'batch_')) {
            $batchId = str_replace('batch_', '', $id);
            return $this->assignBatchWorkOrders($request, $batchId);
        }

        try {
            $request->validate([
                'tecnicos_ids' => 'sometimes|array',
                'tecnico_id' => 'sometimes|exists:users,id'
            ]);

            $workOrder = WorkOrder::with('property')->findOrFail($id);

            $isRescheduling = false;

            if ($request->has('tecnicos_ids') && is_array($request->tecnicos_ids)) {
                $workOrder->technicians()->sync($request->tecnicos_ids);
                if (count($request->tecnicos_ids) > 0) {
                    $workOrder->tecnico_id = $request->tecnicos_ids[0]; // backward compatibility
                } else {
                    $workOrder->tecnico_id = null;
                }
            } else if ($request->has('tecnico_id')) {
                $workOrder->tecnico_id = $request->tecnico_id;
                $workOrder->technicians()->sync([$request->tecnico_id]);
            }

            if ($request->has('custom_checklist')) {
                $workOrder->custom_checklist = $request->custom_checklist;
            }

            if ($request->has('scheduled_at')) {
                $isRescheduling = $workOrder->technicians()->count() > 0 && !$request->has('tecnicos_ids') && !$request->has('tecnico_id');
                $workOrder->scheduled_at = $request->scheduled_at;
            }

            // Reset arrival status if the job is rescheduled or reassigned
            if ($request->has('scheduled_at') || $request->has('tecnicos_ids') || $request->has('tecnico_id')) {
                $workOrder->arrival_status = 'PENDIENTE';
                $workOrder->arrived_at = null;
                $workOrder->arrived_latitude = null;
                $workOrder->arrived_longitude = null;
            }

            $workOrder->save();

            // 1. Notificación a los Técnicos
            $techIdsToNotify = [];
            if ($request->has('tecnicos_ids')) {
                $techIdsToNotify = $request->tecnicos_ids;
            } else if ($request->has('tecnico_id')) {
                $techIdsToNotify = [$request->tecnico_id];
            } else if ($request->has('scheduled_at') && $workOrder->technicians()->count() > 0) {
                $techIdsToNotify = $workOrder->technicians()->pluck('users.id')->toArray();
            }

            foreach ($techIdsToNotify as $tId) {
                $tecnico = User::find($tId);
                if ($tecnico) {
                    if ($isRescheduling) {
                        $adminName = auth()->user() ? (auth()->user()->first_name . ' ' . auth()->user()->last_name) : 'El administrador';
                        Notification::send($tecnico, new WorkOrderRescheduledTechnician($workOrder, $adminName));
                    } else {
                        Notification::send($tecnico, new WorkOrderAssigned($workOrder));
                    }
                }
            }

            // 2. Notificación al Cliente (Si se programó la fecha)
            if ($request->has('scheduled_at') && $workOrder->technicians()->count() > 0) {
                try {
                    $primerTecnico = $workOrder->technicians()->first();
                    $tecnicoName = $primerTecnico ? ($primerTecnico->first_name . ' ' . $primerTecnico->last_name) : 'Asignado';
                    if ($workOrder->technicians()->count() > 1) {
                        $tecnicoName .= ' y equipo';
                    }

                    $propertyName = $workOrder->property ? ($workOrder->property->property_name ?: $workOrder->property->address) : 'Tu propiedad';

                    // Obtener el usuario del cliente
                    $client = \App\Models\Client::find($workOrder->property->client_id);
                    if ($client && $client->user_id) {
                        $userCliente = User::find($client->user_id);
                        if ($userCliente) {
                            Notification::send($userCliente, new \App\Notifications\WorkOrderScheduledNotification($workOrder, $tecnicoName, $propertyName));
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error("Error notificando al cliente: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden de trabajo actualizada correctamente',
                'tecnico_nombre' => isset($primerTecnico) && $primerTecnico ? ($primerTecnico->first_name . ' ' . $primerTecnico->last_name) : null
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 9.5. ASIGNAR TÉCNICOS EN MASA A UN LOTE (BATCH)
    // ---------------------------------------------------
    public function assignBatchWorkOrders(Request $request, $batchId)
    {
        try {
            $request->validate([
                'tecnicos_ids' => 'sometimes|array',
                'tecnico_id' => 'sometimes|exists:users,id'
            ]);

            $workOrders = WorkOrder::with('property')->where('batch_id', $batchId)->get();

            if ($workOrders->isEmpty()) {
                return response()->json(['error' => 'No se encontraron órdenes para este lote'], 404);
            }

            foreach ($workOrders as $workOrder) {
                if ($request->has('tecnicos_ids') && is_array($request->tecnicos_ids)) {
                    $workOrder->technicians()->sync($request->tecnicos_ids);
                    if (count($request->tecnicos_ids) > 0) {
                        $workOrder->tecnico_id = $request->tecnicos_ids[0];
                    } else {
                        $workOrder->tecnico_id = null;
                    }
                } else if ($request->has('tecnico_id')) {
                    $workOrder->tecnico_id = $request->tecnico_id;
                    $workOrder->technicians()->sync([$request->tecnico_id]);
                }

                if ($request->has('custom_checklist')) {
                    $workOrder->custom_checklist = $request->custom_checklist;
                }

                if ($request->has('scheduled_at')) {
                    $workOrder->scheduled_at = $request->scheduled_at;
                }

                // Reset arrival status if the job is rescheduled or reassigned
                if ($request->has('scheduled_at') || $request->has('tecnicos_ids') || $request->has('tecnico_id')) {
                    $workOrder->arrival_status = 'PENDIENTE';
                    $workOrder->arrived_at = null;
                    $workOrder->arrived_latitude = null;
                    $workOrder->arrived_longitude = null;
                }

                $workOrder->save();

                if ($request->has('tecnicos_ids') && is_array($request->tecnicos_ids)) {
                    foreach ($request->tecnicos_ids as $tId) {
                        $tecnico = User::find($tId);
                        if ($tecnico) {
                            Notification::send($tecnico, new WorkOrderAssigned($workOrder));
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Lote de órdenes de trabajo actualizado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 10. OBTENER LEVANTAMIENTO/INVENTARIO COMPLETO (Para técnicos)
    // ---------------------------------------------------
    public function getPropertySurvey($id)
    {
        try {
            $areas = DB::table('property_areas')
                ->where('property_id', $id)
                ->whereNull('parent_id')
                ->get();

            $survey = $areas->map(function ($area) {
                // Obtener subáreas
                $subareas = DB::table('property_areas')
                    ->where('parent_id', $area->id)
                    ->get();

                $subareaIds = $subareas->pluck('id')->toArray();
                $allAreaIds = array_merge([$area->id], $subareaIds);

                // Obtener componentes (para el agrupado global compatible)
                $components = DB::table('property_components')
                    ->whereIn('property_area_id', $allAreaIds)
                    ->get()
                    ->map(function ($comp) {
                        return [
                            'id' => $comp->id,
                            'nombre' => $comp->sub_category,
                            'categoria' => $comp->category,
                            'marca' => $comp->brand,
                            'modelo' => $comp->model_or_color,
                            'estado' => $comp->status,
                            'cantidad' => $comp->quantity,
                            'observaciones' => $comp->observations,
                            'foto' => $comp->image_path,
                            'foto_secundaria' => $comp->image_path_secondary,
                            'serial_number' => $comp->serial_number,
                            'property_area_id' => $comp->property_area_id,
                            'sub_category' => $comp->sub_category,
                            'brand' => $comp->brand,
                            'model_or_color' => $comp->model_or_color,
                            'status' => $comp->status,
                            'quantity' => $comp->quantity,
                            'observations' => $comp->observations,
                            'image_path' => $comp->image_path,
                            'image_path_secondary' => $comp->image_path_secondary,
                            'galleries' => DB::table('component_galleries')
                                ->where('property_component_id', $comp->id)
                                ->get()
                        ];
                    });

                // Agrupar por categoría (global para compatibilidad)
                $groupedByCategory = [];
                foreach ($components as $comp) {
                    $cat = $comp['categoria'] ?: 'General';
                    if (!isset($groupedByCategory[$cat])) {
                        $groupedByCategory[$cat] = [];
                    }
                    $groupedByCategory[$cat][] = $comp;
                }

                // --- NUEVO: Agrupar por Zonas/Subáreas ---
                $subareasSurvey = [];

                // 1. Componentes directos de la área principal (sin subárea específica)
                $parentComponents = DB::table('property_components')
                    ->where('property_area_id', $area->id)
                    ->get()
                    ->map(function ($comp) {
                        return [
                            'id' => $comp->id,
                            'nombre' => $comp->sub_category,
                            'categoria' => $comp->category,
                            'marca' => $comp->brand,
                            'modelo' => $comp->model_or_color,
                            'estado' => $comp->status,
                            'cantidad' => $comp->quantity,
                            'observaciones' => $comp->observations,
                            'foto' => $comp->image_path,
                            'foto_secundaria' => $comp->image_path_secondary,
                            'serial_number' => $comp->serial_number,
                            'property_area_id' => $comp->property_area_id,
                            'sub_category' => $comp->sub_category,
                            'brand' => $comp->brand,
                            'model_or_color' => $comp->model_or_color,
                            'status' => $comp->status,
                            'quantity' => $comp->quantity,
                            'observations' => $comp->observations,
                            'image_path' => $comp->image_path,
                            'image_path_secondary' => $comp->image_path_secondary,
                            'galleries' => DB::table('component_galleries')
                                ->where('property_component_id', $comp->id)
                                ->get()
                        ];
                    });

                if ($parentComponents->isNotEmpty()) {
                    $parentGrouped = [];
                    foreach ($parentComponents as $comp) {
                        $cat = $comp['categoria'] ?: 'General';
                        if (!isset($parentGrouped[$cat])) {
                            $parentGrouped[$cat] = [];
                        }
                        $parentGrouped[$cat][] = $comp;
                    }
                    $subareasSurvey[] = [
                        'id' => $area->id,
                        'name' => 'General / ' . $area->name,
                        'photo' => $area->image_path,
                        'is_parent' => true,
                        'categories' => $parentGrouped
                    ];
                }

                // 2. Agregar componentes de cada una de las subáreas
                foreach ($subareas as $sub) {
                    $subComponents = DB::table('property_components')
                        ->where('property_area_id', $sub->id)
                        ->get()
                        ->map(function ($comp) {
                            return [
                                'id' => $comp->id,
                                'nombre' => $comp->sub_category,
                                'categoria' => $comp->category,
                                'marca' => $comp->brand,
                                'modelo' => $comp->model_or_color,
                                'estado' => $comp->status,
                                'cantidad' => $comp->quantity,
                                'observaciones' => $comp->observations,
                                'foto' => $comp->image_path,
                                'foto_secundaria' => $comp->image_path_secondary,
                                'serial_number' => $comp->serial_number,
                                'property_area_id' => $comp->property_area_id,
                                'sub_category' => $comp->sub_category,
                                'brand' => $comp->brand,
                                'model_or_color' => $comp->model_or_color,
                                'status' => $comp->status,
                                'quantity' => $comp->quantity,
                                'observations' => $comp->observations,
                                'image_path' => $comp->image_path,
                                'image_path_secondary' => $comp->image_path_secondary,
                                'galleries' => DB::table('component_galleries')
                                    ->where('property_component_id', $comp->id)
                                    ->get()
                            ];
                        });

                    $subGrouped = [];
                    foreach ($subComponents as $comp) {
                        $cat = $comp['categoria'] ?: 'General';
                        if (!isset($subGrouped[$cat])) {
                            $subGrouped[$cat] = [];
                        }
                        $subGrouped[$cat][] = $comp;
                    }

                    $subareasSurvey[] = [
                        'id' => $sub->id,
                        'name' => $sub->name,
                        'photo' => $sub->image_path,
                        'is_parent' => false,
                        'categories' => $subGrouped
                    ];
                }

                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'photo' => $area->image_path,
                    'categories' => $groupedByCategory,
                    'subareas' => $subareasSurvey
                ];
            });

            return response()->json($survey);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getGlobalServiceStats()
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            $query = DB::table('work_orders');

            if ($user->role_id == 3) {
                $cliente = DB::table('clients')->where('user_id', $user->id)->first();
                if (!$cliente)
                    return response()->json(['sos' => 0, 'todo' => 0, 'progress' => 0, 'done' => 0]);

                $propertyIds = DB::table('properties')->where('client_id', $cliente->id)->pluck('id');
                $query->whereIn('property_id', $propertyIds);
            }

            $stats = $query->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get()
                ->pluck('total', 'status');

            // SOS son los de prioridad 'Urgente' Y que no estén en estado 'Listo'
            $sosQuery = DB::table('work_orders')->where('priority', 'Urgente')->where('status', '!=', 'Listo');
            if ($user->role_id == 3) {
                $cliente = DB::table('clients')->where('user_id', $user->id)->first();
                if ($cliente) {
                    $propertyIds = DB::table('properties')->where('client_id', $cliente->id)->pluck('id');
                    $sosQuery->whereIn('property_id', $propertyIds);
                } else {
                    $sosCount = 0;
                }
            }

            if (!isset($sosCount)) {
                $sosCount = $sosQuery->count();
            }

            return response()->json([
                'sos' => $sosCount,
                'todo' => $stats['Por Hacer'] ?? 0,
                'progress' => $stats['En Proceso'] ?? 0,
                'done' => $stats['Listo'] ?? 0,
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function finalizeSurvey($id)
    {
        try {
            $propiedad = Property::findOrFail($id);
            $propiedad->levantamiento_realizado = true;
            $propiedad->save();

            // CREAR EL SERVICIO TÉCNICO DE LEVANTAMIENTO (Si no existe)
            // Esto permite que el cliente vea su reporte de inmediato
            $existeServicio = DB::table('services')
                ->where('property_id', $id)
                ->where('title', 'like', '%Levantamiento%')
                ->exists();

            if (!$existeServicio) {
                DB::table('services')->insert([
                    'property_id' => $id,
                    'title' => 'Levantamiento Inicial (Cliente)',
                    'description' => 'Levantamiento técnico registrado directamente por el cliente.',
                    'status' => 'completed',
                    'priority' => 'Baja',
                    'supervisor_name' => 'N/A', // <-- SOLUCIÓN AL ERROR SQL
                    'scheduled_start' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // NOTIFICAR A LOS ADMINS (Opcional)
            try {
                $admins = \App\Models\User::whereIn('role_id', [0, 1])->get();
                $notification = new \App\Notifications\ClientSurveyCompletedNotification($propiedad);
                foreach ($admins as $admin) {
                    $admin->notify($notification);
                }
            } catch (\Exception $e) {
                // Si falla la notificación no bloqueamos el proceso
            }

            return response()->json(['success' => true, 'message' => 'Levantamiento finalizado y servicio creado.']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getPropertyReport($id)
    {
        try {
            $property = Property::with('client')->find($id);
            if (!$property) {
                return response()->json(['error' => 'Propiedad no encontrada'], 404);
            }

            $client = $property->client;

            // Reutilizamos la lógica de formateo (basada en ServiceController)
            $secciones = $this->getFormattedSecciones($property->id);

            return response()->json([
                'id' => 'prop_' . $property->id,
                'tipo_registro' => 'propiedad',
                'titulo' => 'Levantamiento Inicial',
                'estado' => 'Completado',
                'prioridad' => 'Media',
                'identificador_curp' => $property->custom_curp,
                'propietario' => $client ? $client->name : 'Sin Propietario',
                'telefono_cliente' => $client ? $client->phone : null,
                'direccion' => $property->address,
                'coordenadas' => $property->coordinates,
                'tipoPropiedad' => strtoupper($property->type),
                'propiedad_nombre' => $property->property_name,
                'foto_fachada' => $property->facade_photo_path,
                'cliente_email' => $client ? $client->email : null,
                'tecnico' => 'Registro Manual',
                'tecnico_email' => null,
                'tecnico_celular' => null,
                'technicians' => [],
                'fecha_programada' => $property->updated_at->format('Y-m-d H:i:s'),
                'descripcion' => 'Levantamiento realizado manualmente por el cliente o administración.',
                'property_id' => $property->id,
                'evidencias' => [],
                'secciones' => $secciones
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener reporte: ' . $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------
    // 13. COMPARTIR PROPIEDAD
    // ---------------------------------------------------
    public function shareProperty(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado.'], 401);

            $request->validate([
                'email' => 'required|email'
            ]);

            $property = Property::findOrFail($id);

            // Solo dueño o admin puede compartir
            if ($user->role_id == 3) {
                $clienteDuenio = DB::table('clients')->where('user_id', $user->id)->first();
                if (!$clienteDuenio || $property->client_id !== $clienteDuenio->id) {
                    return response()->json(['error' => 'Solo el dueño principal puede compartir la propiedad.'], 403);
                }
            }

            // Buscar al cliente por email
            $clienteInvitado = DB::table('clients')->where('email', $request->email)->first();
            if (!$clienteInvitado) {
                // Buscamos a ver si es un usuario
                $userInvitado = User::where('email', $request->email)->where('role_id', 3)->first();
                if ($userInvitado) {
                    // Si es usuario pero no tiene perfil de cliente, se lo creamos temporalmente o lo denegamos.
                    // Para simplificar, devolvemos error y le decimos que debe haber iniciado sesión al menos una vez
                    return response()->json(['error' => 'El usuario existe pero no ha configurado su perfil de cliente. Pídele que inicie sesión primero.'], 404);
                }
                return response()->json(['error' => 'El correo no pertenece a ningún cliente del sistema.'], 404);
            }

            if ($property->client_id === $clienteInvitado->id) {
                return response()->json(['error' => 'No puedes compartir tu propia propiedad contigo mismo.'], 400);
            }

            // Checar si ya está compartido
            $exists = DB::table('property_shares')->where('property_id', $property->id)->where('client_id', $clienteInvitado->id)->exists();
            if ($exists) {
                return response()->json(['error' => 'La propiedad ya está compartida con este usuario.'], 400);
            }

            DB::table('property_shares')->insert([
                'property_id' => $property->id,
                'client_id' => $clienteInvitado->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Enviar notificaciones
            $ownerName = $property->client ? $property->client->name : 'El dueño';
            $guestName = $clienteInvitado->name;
            $propName = $property->property_name ?: $property->address;

            // Al dueño
            if ($property->client && $property->client->user_id) {
                $ownerUser = User::find($property->client->user_id);
                if ($ownerUser)
                    Notification::send($ownerUser, new \App\Notifications\PropertySharedNotification($ownerName, $guestName, $propName, 'owner'));
            }

            // Al invitado
            if ($clienteInvitado->user_id) {
                $guestUser = User::find($clienteInvitado->user_id);
                if ($guestUser)
                    Notification::send($guestUser, new \App\Notifications\PropertySharedNotification($ownerName, $guestName, $propName, 'guest'));
            }

            // A los admins
            $admins = User::whereIn('role_id', [0, 1])->get();
            Notification::send($admins, new \App\Notifications\PropertySharedNotification($ownerName, $guestName, $propName, 'admin'));

            return response()->json(['message' => 'Propiedad compartida con éxito'], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al compartir propiedad: ' . $e->getMessage()], 500);
        }
    }

    public function revokeShare(Request $request, $id, $clientId)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado.'], 401);

            $property = Property::findOrFail($id);

            // Solo dueño o admin puede revocar
            if ($user->role_id == 3) {
                $clienteDuenio = DB::table('clients')->where('user_id', $user->id)->first();
                if (!$clienteDuenio || $property->client_id !== $clienteDuenio->id) {
                    return response()->json(['error' => 'Solo el dueño principal puede revocar la herencia.'], 403);
                }
            }

            $clienteInvitado = DB::table('clients')->where('id', $clientId)->first();

            DB::table('property_shares')
                ->where('property_id', $property->id)
                ->where('client_id', $clientId)
                ->delete();

            if ($clienteInvitado) {
                // Enviar notificaciones
                $ownerName = $property->client ? $property->client->name : 'El dueño';
                $guestName = $clienteInvitado->name;
                $propName = $property->property_name ?: $property->address;

                // Al dueño
                if ($property->client && $property->client->user_id) {
                    $ownerUser = User::find($property->client->user_id);
                    if ($ownerUser)
                        Notification::send($ownerUser, new \App\Notifications\PropertyShareRevokedNotification($ownerName, $guestName, $propName, 'owner'));
                }

                // Al invitado
                if ($clienteInvitado->user_id) {
                    $guestUser = User::find($clienteInvitado->user_id);
                    if ($guestUser)
                        Notification::send($guestUser, new \App\Notifications\PropertyShareRevokedNotification($ownerName, $guestName, $propName, 'guest'));
                }

                // A los admins
                $admins = User::whereIn('role_id', [0, 1])->get();
                Notification::send($admins, new \App\Notifications\PropertyShareRevokedNotification($ownerName, $guestName, $propName, 'admin'));
            }

            return response()->json(['message' => 'Acceso revocado con éxito'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al revocar acceso: ' . $e->getMessage()], 500);
        }
    }

    public function getSharedUsers($id)
    {
        try {
            $shares = DB::table('property_shares')
                ->join('clients', 'property_shares.client_id', '=', 'clients.id')
                ->where('property_shares.property_id', $id)
                ->select('clients.id', 'clients.name', 'clients.email', 'clients.phone', 'clients.profile_picture', 'property_shares.created_at')
                ->get();

            return response()->json($shares);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener usuarios invitados: ' . $e->getMessage()], 500);
        }
    }

    private function getFormattedSecciones($propertyId)
    {
        if (!$propertyId)
            return [];

        $areas = DB::table('property_areas as a')
            ->where('a.property_id', $propertyId)
            ->where(function ($q) {
                $q->whereNull('a.parent_id')
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('property_areas as p')
                            ->whereColumn('p.id', 'a.parent_id');
                    });
            })
            ->get();

        if ($areas->isEmpty())
            return [];

        return $areas->map(function ($area) {
            $parent = null;
            if ($area->parent_id) {
                $parent = DB::table('property_areas')->where('id', $area->parent_id)->first();
            }

            $components = DB::table('property_components')
                ->where('property_area_id', $area->id)
                ->get();

            // Obtener categorías explícitas guardadas para el área
            $explicitCategories = DB::table('property_maintenance_categories')
                ->where('property_area_id', $area->id)
                ->get();

            // Agrupar componentes por su categoría
            $groupedComponents = $components->groupBy('category');

            // Asegurar que las categorías explícitas existan en el grupo, aunque estén vacías
            foreach ($explicitCategories as $cat) {
                if (!$groupedComponents->has($cat->name)) {
                    $groupedComponents->put($cat->name, collect([]));
                }
            }

            $subSecciones = $groupedComponents->map(function ($items, $catName) use ($explicitCategories) {
                $catRecord = $explicitCategories->firstWhere('name', $catName);
                return [
                    'id' => $catRecord ? $catRecord->id : null,
                    'nombre' => $catName ?: 'General',
                    'inventario' => $items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'nombre' => $item->sub_category,
                            'categoria' => $item->category,
                            'marca' => $item->brand,
                            'modelo' => $item->model_or_color,
                            'estado' => $item->status,
                            'cantidad' => $item->quantity,
                            'observaciones' => $item->observations,
                            'foto' => $item->image_path,
                            'foto_secundaria' => $item->image_path_secondary,
                            'serial_number' => $item->serial_number,
                            'property_area_id' => $item->property_area_id,
                            'sub_category' => $item->sub_category,
                            'brand' => $item->brand,
                            'model_or_color' => $item->model_or_color,
                            'status' => $item->status,
                            'quantity' => $item->quantity,
                            'observations' => $item->observations,
                            'image_path' => $item->image_path,
                            'image_path_secondary' => $item->image_path_secondary,
                            'galleries' => DB::table('component_galleries')
                                ->where('property_component_id', $item->id)
                                ->get()
                        ];
                    })->values()
                ];
            })->values();

            return [
                'id' => $area->id,
                'titulo' => $area->name,
                'foto' => $area->image_path,
                'subSecciones' => $subSecciones,
                'parent' => $parent
            ];
        });
    }
}
