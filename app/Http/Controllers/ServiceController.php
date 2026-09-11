<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Notifications\RescheduleRequested;
use App\Notifications\SecondVisitRequested;
use App\Notifications\SecondVisitAgreed;
use App\Notifications\SecondVisitAdminScheduled;
use Illuminate\Support\Facades\Notification;
use App\Notifications\VisitRescheduled;
use App\Notifications\VisitConfirmed;
use App\Notifications\NewServiceRequested;
use App\Models\PropertyArea;
use Cloudinary\Cloudinary;
use Illuminate\Support\Facades\Log;
use App\Notifications\TechnicianMissedVisitNotification;
use App\Models\WorkOrder;
use App\Models\WorkReport;
use App\Models\FinalWorkReport;


class ServiceController extends Controller
{
    // ... (store, index, assignTechnician, assignWorkOrder methods remain unchanged)

    private function parseCompositeId($raw)
    {
        $decoded = trim(urldecode((string)$raw));
        $isWorkOrder = (bool) preg_match('/work[_\s-]*order/i', $decoded);
        $isService = (bool) preg_match('/servicio/i', $decoded);

        preg_match('/\d+/', $decoded, $matches);
        $realId = isset($matches[0]) ? (int)$matches[0] : null;

        return [
            'raw' => $decoded,
            'isWorkOrder' => $isWorkOrder,
            'isService' => $isService,
            'realId' => $realId,
            'column' => $isWorkOrder ? 'work_order_id' : 'service_id'
        ];
    }

    public function getReports($id)
    {
        try {
            $parsed = $this->parseCompositeId($id);
            $realId = $parsed['realId'];

            if (!$realId) {
                return response()->json([], 200);
            }

            $column = $parsed['column'];

            $reports = WorkReport::with('technician:id,first_name,last_name,profile_picture')
                ->where($column, $realId)
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json($reports, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener reportes: ' . $e->getMessage()], 500);
        }
    }

    public function storeReport(Request $request, $id)
    {
        try {
            $request->validate([
                'description' => 'required|string',
                'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240',
            ]);

            $parsed = $this->parseCompositeId($id);
            $realId = $parsed['realId'];
            $column = $parsed['column'];

            $cloudinary = new Cloudinary('cloudinary://942191234587844:VmNYB6w4vj3DdLqI9SZSKVofOi0@dcj5rcpi8');
            $respuestaNube = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), [
                'folder' => 'agente_reportes'
            ]);

            $report = WorkReport::create([
                $column => $realId,
                'technician_id' => $request->user()->id,
                'image_url' => $respuestaNube['secure_url'],
                'description' => $request->description,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Reporte guardado con éxito',
                'report' => $report
            ], 201);
        } catch (\Exception $e) {
            Log::error("Error guardando reporte: " . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Error al guardar reporte: ' . $e->getMessage()], 500);
        }
    }

    public function updateReport(Request $request, $id)
    {
        try {
            $request->validate([
                'description' => 'required|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
            ]);

            $report = WorkReport::find($id);
            if (!$report) {
                return response()->json(['success' => false, 'error' => 'Reporte no encontrado'], 404);
            }

            $report->description = $request->description;

            if ($request->hasFile('image')) {
                $cloudinary = new Cloudinary('cloudinary://942191234587844:VmNYB6w4vj3DdLqI9SZSKVofOi0@dcj5rcpi8');
                $respuestaNube = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), [
                    'folder' => 'agente_reportes'
                ]);
                $report->image_url = $respuestaNube['secure_url'];
            }

            $report->save();

            return response()->json([
                'success' => true,
                'message' => 'Reporte actualizado con éxito',
                'report' => $report
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error actualizando reporte: " . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Error al actualizar reporte: ' . $e->getMessage()], 500);
        }
    }

    public function deleteReport($id)
    {
        try {
            $report = WorkReport::find($id);
            if (!$report) {
                return response()->json(['success' => false, 'error' => 'Reporte no encontrado'], 404);
            }

            $report->delete();

            return response()->json([
                'success' => true,
                'message' => 'Reporte eliminado con éxito'
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error eliminando reporte: " . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Error al eliminar reporte: ' . $e->getMessage()], 500);
        }
    }

    public function storeFinalReport(Request $request, $id)
    {
        try {
            \Log::info("Iniciando guardado de reporte final para ID: " . $id);
            \Log::info("Datos recibidos: ", $request->all());

            $realId = $id;
            $type = 'service_id';

            if (str_contains($id, '-')) {
                $parts = explode('-', $id);
                $prefix = $parts[0];
                $realId = $parts[1];
                $type = ($prefix === 'work_order') ? 'work_order_id' : 'service_id';
            }

            if ($realId === 'null' || !$realId) {
                return response()->json(['success' => false, 'error' => 'ID de trabajo inválido (null)'], 400);
            }

            $data = $request->all();
            
            // Mapear campos de español (frontend) a inglés (BD) si es necesario
            if (isset($data['materiales'])) $data['materials'] = $data['materiales'];
            if (isset($data['observaciones'])) $data['observations'] = $data['observaciones'];
            if (isset($data['imagenes'])) $data['selected_images'] = $data['imagenes'];
            if (isset($data['fechaTrabajo'])) $data['report_date'] = $data['fechaTrabajo'];
            if (isset($data['horaInicio'])) $data['start_time'] = $data['horaInicio'];
            if (isset($data['horaFin'])) $data['end_time'] = $data['horaFin'];

            $data[$type] = $realId;

            \Log::info("Datos normalizados para BD: ", $data);

            $report = FinalWorkReport::updateOrCreate(
                [$type => $realId],
                $data
            );
            
            \Log::info("Reporte guardado con éxito ID: " . $report->id);

            return response()->json(['success' => true, 'report' => $report], 200);
        } catch (\Exception $e) {
            \Log::error("Error en storeFinalReport: " . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getFinalReport($id)
    {
        try {
            $realId = $id;
            $type = 'service_id';

            if (str_contains($id, '-')) {
                $parts = explode('-', $id);
                $prefix = $parts[0];
                $realId = $parts[1];
                $type = ($prefix === 'work_order') ? 'work_order_id' : 'service_id';
            }

            if ($realId === 'null' || !$realId) {
                return response()->json(null, 200);
            }

            $report = FinalWorkReport::where($type, $realId)->first();
            return response()->json($report, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'property_id' => 'required|exists:properties,id',
                'title' => 'nullable|string|max:191',
                'property_area_id' => 'nullable|exists:property_areas,id',
                'foto' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
                'evidencia_1' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
                'evidencia_2' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
            ]);

            $servicio = new Service();
            $servicio->property_id = $request->property_id;
            $servicio->property_area_id = $request->property_area_id;

            $user = $request->user();
            if ($user && $user->role_id == 3) {
                 $servicio->requested_by = $user->id;
            } else {
                 $servicio->requested_by = $request->filled('requested_by') ? $request->requested_by : null;
            }

            // --- SUBIDA A CLOUDINARY ---
            $cloudinary = new Cloudinary('cloudinary://942191234587844:VmNYB6w4vj3DdLqI9SZSKVofOi0@dcj5rcpi8');

            // Caso retrocompatible (campo 'foto')
            if ($request->hasFile('foto')) {
                try {
                    $respuestaNube = $cloudinary->uploadApi()->upload($request->file('foto')->getRealPath(), [
                        'folder' => 'agente_servicios'
                    ]);
                    $servicio->evidence_path = $respuestaNube['secure_url'];
                } catch (\Exception $e) {
                    Log::error("Error subiendo evidencia a Cloudinary: " . $e->getMessage());
                }
            }

            // Evidencia 1
            if ($request->hasFile('evidencia_1')) {
                try {
                    $respuestaNube = $cloudinary->uploadApi()->upload($request->file('evidencia_1')->getRealPath(), [
                        'folder' => 'agente_servicios'
                    ]);
                    $servicio->evidence_path = $respuestaNube['secure_url'];
                } catch (\Exception $e) {
                    Log::error("Error subiendo evidencia_1: " . $e->getMessage());
                }
            }

            // Evidencia 2
            if ($request->hasFile('evidencia_2')) {
                try {
                    $respuestaNube = $cloudinary->uploadApi()->upload($request->file('evidencia_2')->getRealPath(), [
                        'folder' => 'agente_servicios'
                    ]);
                    // Intentamos guardar en evidence_path_2 si existe el atributo (vía asignación dinámica o si ya está en el modelo)
                    // Para evitar errores si la columna no existe aún, podemos usar un try catch o verificar Schema
                    $servicio->evidence_path_2 = $respuestaNube['secure_url'];
                } catch (\Exception $e) {
                    Log::error("Error subiendo evidencia_2: " . $e->getMessage());
                }
            }

            $servicio->service_category_id = $request->service_category_id ?? 1;
            $servicio->service_type = $request->service_type ?? $request->type ?? 'Mantenimiento';
            $servicio->priority = $request->priority ?? 'Media';
            $servicio->status = 'Por Asignar'; 
            
            // Generar título si no viene
            if (!$request->filled('title')) {
                $areaName = 'General';
                if ($request->property_area_id) {
                    $area = PropertyArea::find($request->property_area_id);
                    if ($area) $areaName = $area->name;
                }
                $servicio->title = "Reporte: " . $areaName;
            } else {
                $servicio->title = $request->title;
            }

            $servicio->supervisor_name = $request->supervisor_name;
            $servicio->description = $request->description;

            $servicio->assigned_to = $request->filled('technician_id') ? $request->technician_id : null;
            $servicio->scheduled_start = $request->filled('scheduled_start') ? $request->scheduled_start : ($request->filled('date') ? $request->date : now());
            $servicio->scheduled_end = $request->scheduled_end;

            $servicio->save();

            try {
                $admins = User::where('role_id', 0)->get();
                Notification::send($admins, new \App\Notifications\NewServiceRequested($servicio));
            } catch (\Exception $e) {
                \Log::error('Error al enviar notificación de nuevo servicio: ' . $e->getMessage());
            }

            if ($request->has('selected_components') && is_array($request->selected_components)) {
                $pivotData = [];
                foreach ($request->selected_components as $componentId) {
                    $pivotData[] = [
                        'service_id' => $servicio->id,
                        'property_component_id' => $componentId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                DB::table('service_component')->insert($pivotData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Servicio creado con éxito',
                'service' => $servicio
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear servicio: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $query = Service::with(['property.client', 'technician'])->orderByDesc('created_at');

            if ($user && $user->role_id == 3) {
                $cliente = DB::table('clients')->where('user_id', $user->id)->first();
                
                if ($cliente) {
                    $query->whereHas('property', function($q) use ($cliente) {
                        $q->where('client_id', $cliente->id);
                    });
                } else {
                    return response()->json([], 200); 
                }
            } elseif ($user && $user->role_id == 4) {
                // Autónomo: solo ve servicios de propiedades de su empresa (o asignados a técnicos de su empresa)
                $query->where(function ($q) use ($user) {
                    $q->where('tenant_id', $user->tenant_id)
                      ->orWhereHas('property', function ($qp) use ($user) {
                          $qp->where('tenant_id', $user->tenant_id);
                      })
                      ->orWhereHas('technician', function ($qt) use ($user) {
                          $qt->where('tenant_id', $user->tenant_id);
                      });
                });
            } elseif ($user && $user->role_id !== 0 && $user->tenant_id) {
                $query->where(function ($q) use ($user) {
                    $q->where('tenant_id', $user->tenant_id)
                      ->orWhereHas('property', function ($qp) use ($user) {
                          $qp->where('tenant_id', $user->tenant_id);
                      });
                });
            }

            $servicios = $query->get();

            $formateados = $servicios->map(function ($s) {
                return [
                    'id' => $s->id,
                    'property_id' => $s->property_id,
                    'title' => $s->title,
                    'priority' => $s->priority,
                    'status' => $s->status,
                    'assigned_to' => $s->assigned_to,
                    'tecnico_nombre' => $s->technician
                        ? ($s->technician->first_name . ' ' . $s->technician->last_name)
                        : '⚠️ Por Asignar',

                    'propiedad_nombre' => $s->property ? $s->property->property_name : 'N/A',
                    'propiedad_tipo' => $s->property ? $s->property->type : 'N/A',
                    'curp' => $s->property ? $s->property->custom_curp : 'S/N',
                    'cliente_nombre' => ($s->property && $s->property->client) ? $s->property->client->name : 'Usuario',
                    'cliente_telefono' => ($s->property && $s->property->client) ? $s->property->client->phone : '',
                    'cliente_email' => ($s->property && $s->property->client) ? $s->property->client->email : '',
                    'direccion' => $s->property ? $s->property->address : 'N/A',
                    'fecha_programada' => $s->scheduled_start,
                    'foto_fachada' => $s->property ? $s->property->facade_photo_path : null,
                    'supervisor_name' => $s->supervisor_name,
                    'description' => $s->description,
                    'prioridad' => $s->priority,
                ];
            });

            return response()->json($formateados, 200);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function assignTechnician(Request $request, $id)
    {
        try {
            $servicio = Service::with('property.client')->find($id);

            if (!$servicio) {
                return response()->json(['success' => false, 'message' => 'Servicio no encontrado'], 404);
            }

            $servicio->assigned_to = $request->tecnico_id;
            $servicio->scheduled_start = $request->scheduled_start;
            $servicio->arrival_status = 'PENDIENTE';
            $servicio->arrived_at = null;
            $servicio->arrived_latitude = null;
            $servicio->arrived_longitude = null;
            $servicio->status = 'Programado';
            $servicio->save();

            if ($servicio->property && $servicio->property->client && $servicio->property->client->user_id) {
                $clienteUser = User::find($servicio->property->client->user_id);
                
                if ($clienteUser) {
                    Notification::send($clienteUser, new \App\Notifications\VisitRescheduled($servicio));
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Técnico asignado y visita programada correctamente.'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en la asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    public function assignWorkOrder(Request $request, $id)
    {
        try {
            $servicio = Service::find($id);

            if (!$servicio) {
                return response()->json(['success' => false, 'message' => 'Servicio no encontrado'], 404);
            }

            if ($request->has('tecnicos_ids') && is_array($request->tecnicos_ids)) {
                $servicio->technicians()->sync($request->tecnicos_ids);
                if (count($request->tecnicos_ids) > 0) {
                    $servicio->assigned_to = $request->tecnicos_ids[0]; // backward compatibility
                } else {
                    $servicio->assigned_to = null;
                }
            } else if ($request->has('tecnico_id')) {
                $servicio->assigned_to = $request->tecnico_id;
                $servicio->technicians()->sync([$request->tecnico_id]);
            }

            if ($request->has('scheduled_start')) {
                $servicio->scheduled_start = $request->scheduled_start;
            }
            if ($request->has('custom_checklist')) {
                $servicio->custom_checklist = $request->custom_checklist; 
            }
            
            // Reset arrival status if the job is rescheduled or reassigned
            if ($request->has('scheduled_start') || $request->has('tecnicos_ids') || $request->has('tecnico_id')) {
                $servicio->arrival_status = 'PENDIENTE';
                $servicio->arrived_at = null;
                $servicio->arrived_latitude = null;
                $servicio->arrived_longitude = null;
            }
            
            $servicio->status = 'Programado';
            $servicio->save();

            $techIdsToNotify = [];
            if ($request->has('tecnicos_ids')) {
                $techIdsToNotify = $request->tecnicos_ids;
            } else if ($request->has('tecnico_id')) {
                $techIdsToNotify = [$request->tecnico_id];
            }

            foreach ($techIdsToNotify as $tId) {
                $tecnicoUser = User::find($tId);
                if ($tecnicoUser) {
                    \Illuminate\Support\Facades\Notification::send($tecnicoUser, new \App\Notifications\WorkAssigned($servicio));
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Trabajo y Checklist asignados correctamente al equipo.'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en la asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($identifier)
    {
        try {
            $parsed = $this->parseCompositeId($identifier);
            $realId = $parsed['realId'];
            $model = null;
            $isWorkOrder = false;

            if ($parsed['isWorkOrder']) {
                $model = WorkOrder::withoutGlobalScopes()->with(['property.client', 'tecnico', 'technicians', 'networkQuotes.technician'])->find($realId);
                if ($model) {
                    $isWorkOrder = true;
                } else {
                    $model = Service::withoutGlobalScopes()->with(['property.client', 'technician', 'technicians'])->find($realId);
                }
            } elseif ($parsed['isService']) {
                $model = Service::withoutGlobalScopes()->with(['property.client', 'technician', 'technicians'])->find($realId);
                if (!$model) {
                    $model = WorkOrder::withoutGlobalScopes()->with(['property.client', 'tecnico', 'technicians', 'networkQuotes.technician'])->find($realId);
                    if ($model) {
                        $isWorkOrder = true;
                    }
                }
            } else {
                // Fallback: Buscar en WorkOrder primero si es numérico, luego en Service
                $model = WorkOrder::withoutGlobalScopes()->with(['property.client', 'tecnico', 'technicians', 'networkQuotes.technician'])->find($realId);
                if ($model) {
                    $isWorkOrder = true;
                } else {
                    $model = Service::withoutGlobalScopes()->with(['property.client', 'technician', 'technicians'])->find($realId);
                }
            }

            if (!$model) {
                return response()->json(['error' => 'Registro no encontrado'], 404);
            }

            $property = \App\Models\Property::withoutGlobalScopes()
                ->with(['client' => function($q) { $q->withoutGlobalScopes(); }])
                ->find($model->property_id);
            $client = $property ? $property->client : null;

            $secciones = $this->getFormattedSecciones($model->property_id);
            
            // Map the team
            $team = [];
            if ($model->technicians && $model->technicians->count() > 0) {
                foreach ($model->technicians as $t) {
                    $team[] = [
                        'id' => $t->id,
                        'name' => $t->first_name . ' ' . $t->last_name,
                        'picture' => $t->profile_picture,
                        'email' => $t->email,
                        'phone_number' => $t->phone_number,
                        'role' => 'TÉCNICO'
                    ];
                }
            } else if ($isWorkOrder && $model->tecnico) {
                 $team[] = [
                        'id' => $model->tecnico->id,
                        'name' => $model->tecnico->first_name . ' ' . $model->tecnico->last_name,
                        'picture' => $model->tecnico->profile_picture,
                        'email' => $model->tecnico->email,
                        'phone_number' => $model->tecnico->phone_number,
                        'role' => 'TÉCNICO'
                 ];
            } else if (!$isWorkOrder && $model->technician) {
                 $team[] = [
                        'id' => $model->technician->id,
                        'name' => $model->technician->first_name . ' ' . $model->technician->last_name,
                        'picture' => $model->technician->profile_picture,
                        'email' => $model->technician->email,
                        'phone_number' => $model->technician->phone_number,
                        'role' => 'TÉCNICO'
                 ];
            }

            if ($isWorkOrder) {
                return response()->json([
                    'id' => $model->id,
                    'tipo_registro' => 'work_order',
                    'titulo' => ($model->type ?? 'Trabajo') . ' - ' . ($model->zone ?? 'General'),
                    'estado' => $model->status,
                    'prioridad' => $model->priority,
                    'identificador_curp' => $property ? $property->custom_curp : 'S/N',
                    'propietario' => $client ? $client->name : 'Sin Propietario',
                    'telefono_cliente' => $client ? $client->phone : null,
                    'direccion' => $property ? $property->address : 'Dirección no registrada',
                    'coordenadas' => $property ? $property->coordinates : null,
                    'coordinates' => $property ? $property->coordinates : null,
                    'tipoPropiedad' => $property ? strtoupper($property->type) : 'N/A',
                    'propiedad_nombre' => $property ? $property->property_name : 'Propiedad Sin Nombre',
                    'foto_fachada' => $property ? $property->facade_photo_path : null,
                    'cliente_email' => $client ? $client->email : null,
                    'tecnico' => $model->tecnico ? ($model->tecnico->first_name . ' ' . $model->tecnico->last_name) : 'Sin Asignar',
                    'tecnico_email' => $model->tecnico ? $model->tecnico->email : null,
                    'tecnico_celular' => $model->tecnico ? $model->tecnico->phone_number : null,
                    'technicians' => $team,
                    'fecha_programada' => $model->scheduled_at ? $model->scheduled_at->format('Y-m-d H:i:s') : null,
                    'descripcion' => $model->description,
                    'equipment' => $model->equipment,
                    'equipo_afectado' => $model->equipment,
                    'custom_checklist' => $model->custom_checklist,
                    'property_id' => $model->property_id,
                    'evidencias' => array_values(array_filter([$model->evidence_path, $model->evidence_path_2])),
                    'secciones' => $secciones,
                    'zone' => $model->zone,
                    'is_from_network' => (bool) ($model->is_from_network || ($model->networkQuotes && $model->networkQuotes->count() > 0)),
                    'network_quotes' => $model->networkQuotes
                ], 200);
            } else {
                return response()->json([
                    'id' => $model->id,
                    'tipo_registro' => 'servicio',
                    'titulo' => $model->title,
                    'estado' => $model->status,
                    'prioridad' => $model->priority,
                    'identificador_curp' => $property ? $property->custom_curp : 'S/N',
                    'propietario' => $client ? $client->name : 'Sin Propietario',
                    'telefono_cliente' => $client ? $client->phone : null,
                    'direccion' => $property ? $property->address : 'Dirección no registrada',
                    'coordenadas' => $property ? $property->coordinates : null,
                    'coordinates' => $property ? $property->coordinates : null,
                    'tipoPropiedad' => $property ? strtoupper($property->type) : 'N/A',
                    'propiedad_nombre' => $property ? $property->property_name : 'Propiedad Sin Nombre',
                    'foto_fachada' => $property ? $property->facade_photo_path : null,
                    'cliente_email' => $client ? $client->email : null,
                    'tecnico' => $model->technician ? ($model->technician->first_name . ' ' . $model->technician->last_name) : 'Sin Asignar',
                    'tecnico_email' => $model->technician ? $model->technician->email : null,
                    'tecnico_celular' => $model->technician ? $model->technician->phone_number : null,
                    'technicians' => $team,
                    'fecha_programada' => $model->scheduled_start,
                    'descripcion' => $model->description,
                    'equipment' => $model->equipment ?? null,
                    'equipo_afectado' => $model->equipment ?? null,
                    'property_id' => $model->property_id,
                    'evidencias' => [],
                    'secciones' => $secciones,
                    'zone' => $model->area ? $model->area->name : 'General'
                ], 200);
            }

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al cargar el detalle: ' . $e->getMessage()], 500);
        }
    }


    // ... (rest of the methods confirmedCitaCliente, solicitarReprogramacion, getTecnicoServicios, update remain unchanged)
    
    public function confirmarCitaCliente($id)
    {
        try {
            $servicio = Service::find($id);

            if (!$servicio) {
                return response()->json(['success' => false, 'message' => 'Servicio no encontrado'], 404);
            }

            $servicio->status = 'Visita Confirmada';
            $servicio->save();

            $admins = User::where('role_id', 0)->get();
            
            Notification::send($admins, new \App\Notifications\VisitConfirmed($servicio));

            // Enviar notificación al Técnico asignado
            if ($servicio->assigned_to) {
                $tecnico = User::find($servicio->assigned_to);
                if ($tecnico) {
                    Notification::send($tecnico, new \App\Notifications\VisitConfirmed($servicio));
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Cita confirmada correctamente por el cliente.'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al confirmar la cita: ' . $e->getMessage()
            ], 500);
        }
    }

    public function solicitarReprogramacion(Request $request, $id)
    {
        try {
            $servicio = Service::find($id);

            if (!$servicio) {
                return response()->json(['success' => false, 'message' => 'Servicio no encontrado'], 404);
            }

            $request->validate([
                'fecha_sugerida' => 'required',
                'motivo' => 'nullable|string'
            ]);

            $notaReprogramacion = "\n[ALERTA DE REPROGRAMACIÓN]: El cliente solicita cambiar la visita al: " . 
                                  $request->fecha_sugerida . ". Motivo: " . 
                                  ($request->motivo ?? 'Sin motivo especificado.');
            
            $servicio->status = 'Reprogramación Solicitada';
            
            $servicio->description = $servicio->description . $notaReprogramacion;
            
            $servicio->save();

            $admins = User::where('role_id', 0)->get();
            
            Notification::send($admins, new \App\Notifications\RescheduleRequested($servicio, $request->fecha_sugerida));

            return response()->json([
                'success' => true,
                'message' => 'Solicitud de reprogramación enviada.'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al solicitar reprogramación: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getTecnicoServicios($idTecnico) {
        try {
            // 1. Servicios (con columnas seguras)
            $servicios = DB::table('services')
                ->join('properties', 'services.property_id', '=', 'properties.id')
                ->leftJoin('clients', 'properties.client_id', '=', 'clients.id')
                ->leftJoin('service_technician', 'services.id', '=', 'service_technician.service_id')
                ->select(
                    'services.id',
                    'services.title',
                    'services.description',
                    'services.status',
                    'services.assigned_to',
                    'services.scheduled_start',
                    'services.scheduled_end',
                    'services.real_start',
                    'services.real_end',
                    'services.arrival_status',
                    'services.arrived_at',
                    'services.property_id',
                    'services.priority',
                    'services.supervisor_name',
                    'services.created_at',
                    'services.updated_at',
                    'properties.property_name',
                    'properties.address',
                    'properties.coordinates',
                    'properties.custom_curp',
                    'properties.facade_photo_path as facade_photo',
                    'clients.name as client_name',
                    'clients.phone as client_phone'
                )
                ->where(function ($query) use ($idTecnico) {
                    $query->where('services.assigned_to', $idTecnico)
                          ->orWhere('service_technician.technician_id', $idTecnico);
                })
                ->distinct('services.id')
                ->get();

            // 2. Work Orders (con columnas seguras)
            $workOrders = DB::table('work_orders')
                ->join('properties', 'work_orders.property_id', '=', 'properties.id')
                ->leftJoin('clients', 'properties.client_id', '=', 'clients.id')
                ->leftJoin('work_order_technician', 'work_orders.id', '=', 'work_order_technician.work_order_id')
                ->select(
                    'work_orders.id',
                    'work_orders.type',
                    'work_orders.zone',
                    'work_orders.description',
                    'work_orders.status',
                    'work_orders.tecnico_id',
                    'work_orders.scheduled_at',
                    'work_orders.arrival_status',
                    'work_orders.arrived_at',
                    'work_orders.property_id',
                    'work_orders.priority',
                    'work_orders.created_at',
                    'work_orders.updated_at',
                    'properties.property_name',
                    'properties.address',
                    'properties.coordinates',
                    'properties.custom_curp',
                    'properties.facade_photo_path as facade_photo',
                    'clients.name as client_name',
                    'clients.phone as client_phone'
                )
                ->where(function ($query) use ($idTecnico) {
                    $query->where('work_orders.tecnico_id', $idTecnico)
                          ->orWhere('work_order_technician.technician_id', $idTecnico);
                })
                ->distinct('work_orders.id')
                ->get();

            // 3. Unificar normalizando campos para el frontend
            $unificados = $servicios->map(function($s) {
                $s->composite_id = "servicio-{$s->id}";
                $s->tipo_registro = 'servicio';
                $s->coordenadas = $s->coordinates;
                return $s;
            })->concat($workOrders->map(function($w) {
                $w->composite_id = "work_order-{$w->id}";
                $w->tipo_registro = 'work_order';
                $w->assigned_to = $w->tecnico_id;
                $w->scheduled_start = $w->scheduled_at;
                $w->coordenadas = $w->coordinates;
                // Generar título si no existe
                $w->title = ($w->type ?? 'Trabajo') . ' - ' . ($w->zone ?? 'General');
                return $w;
            }));

            return response()->json($unificados->values());

        } catch (\Exception $e) {
            Log::error("Error en getTecnicoServicios: " . $e->getMessage());
            return response()->json([
                'error' => 'Error interno del servidor',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $servicio = Service::find($id);
            
            if (!$servicio) {
                $workOrder = WorkOrder::find($id);
                if ($workOrder) {
                    if ($request->has('status')) {
                        $workOrder->status = $request->status;
                    }
                    if ($request->has('custom_checklist')) {
                        $workOrder->custom_checklist = $request->custom_checklist;
                    }
                    $workOrder->save();
                    return response()->json(['success' => true, 'message' => 'Orden de Trabajo actualizada', 'work_order' => $workOrder], 200);
                }
                return response()->json(['success' => false, 'message' => 'No encontrado'], 404);
            }

            if ($request->has('status')) {
                $servicio->status = $request->status;
                if ($request->status === 'completed' || $request->status === 'Finalizado' || $request->status === 'Listo') {
                    $servicio->real_end = now();
                    
                    // Si el servicio es un levantamiento, marcamos la propiedad como realizada
                    if (str_contains(strtolower($servicio->title), 'levantamiento')) {
                        $propiedad = \App\Models\Property::find($servicio->property_id);
                        if ($propiedad) {
                            $propiedad->levantamiento_realizado = true;
                            $propiedad->save();
                            \Log::info("Propiedad {$propiedad->id} marcada como LEVANTAMIENTO REALIZADO por término de servicio.");
                            
                            // NOTIFICAR A LOS ADMINS
                            try {
                                $admins = \App\Models\User::whereIn('role_id', [0, 1])->get();
                                $notification = new \App\Notifications\ClientSurveyCompletedNotification($propiedad);
                                foreach ($admins as $admin) {
                                    $admin->notify($notification);
                                }
                            } catch (\Exception $e) {
                                \Log::error("Error notificando término de levantamiento técnico: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
            
            $servicio->save();

            return response()->json([
                'success' => true,
                'message' => 'Servicio actualizado correctamente.',
                'service' => $servicio
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar servicio: ' . $e->getMessage()
            ], 500);
        }
    }
    private function getFormattedSecciones($propertyId)
    {
        if (!$propertyId) return [];

        // Obtener solo las áreas que no son huérfanas
        $areas = DB::table('property_areas as a')
            ->where('a.property_id', $propertyId)
            ->where(function($q) {
                $q->whereNull('a.parent_id')
                  ->orWhereExists(function($sub) {
                      $sub->select(DB::raw(1))
                          ->from('property_areas as p')
                          ->whereColumn('p.id', 'a.parent_id');
                  });
            })
            ->get();

        if ($areas->isEmpty()) return [];

        return $areas->map(function ($area) {
            // Buscar si tiene un parent para la agrupación en el frontend
            $parent = null;
            if ($area->parent_id) {
                $parent = DB::table('property_areas')->where('id', $area->parent_id)->first();
            }

            // Obtener los componentes (inventario) de esta área específica
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

            // Mapear para formar las 'subSecciones' que espera React
            $subSecciones = $groupedComponents->map(function ($items, $catName) use ($explicitCategories) {
                $catRecord = $explicitCategories->firstWhere('name', $catName);
                return [
                    'id' => $catRecord ? $catRecord->id : null,
                    'nombre' => $catName ?: 'General',
                    'inventario' => $items->map(function($item) {
                        // Mapear campos para que el frontend (React) los detecte correctamente
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
                            // Alias para compatibilidad con el modal de edición que usa nombres de BD
                            'sub_category' => $item->sub_category,
                            'brand' => $item->brand,
                            'model_or_color' => $item->model_or_color,
                            'status' => $item->status,
                            'quantity' => $item->quantity,
                            'observations' => $item->observations,
                            'image_path' => $item->image_path,
                            'image_path_secondary' => $item->image_path_secondary,
                            // Incluir la galería de imágenes de cada componente
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

    public function updateTechnicianLocation(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['error' => 'No autenticado'], 401);
            }

            $request->validate([
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
            ]);

            \App\Models\TechnicianLocation::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'updated_at' => now()
                ]
            );

            return response()->json(['success' => true, 'message' => 'Ubicación actualizada correctamente']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar ubicación: ' . $e->getMessage()], 500);
        }
    }

    public function confirmArrival(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user();
            $parsed = $this->parseCompositeId($id);
            $realId = $parsed['realId'];
            $isWorkOrder = $parsed['isWorkOrder'];

            $lat = $request->latitude ?? null;
            $lng = $request->longitude ?? null;
            $now = now()->setTimezone('America/Merida');

            if ($isWorkOrder) {
                $item = WorkOrder::withoutGlobalScopes()->find($realId);
                if (!$item) {
                    $item = Service::withoutGlobalScopes()->find($realId);
                }
            } else {
                $item = Service::withoutGlobalScopes()->find($realId);
                if (!$item) {
                    $item = WorkOrder::withoutGlobalScopes()->find($realId);
                }
            }

            if (!$item) {
                return response()->json(['error' => 'Trabajo no encontrado'], 404);
            }

            $item->arrived_at = $now->format('Y-m-d H:i:s');
            $item->arrived_latitude = $lat;
            $item->arrived_longitude = $lng;
            $item->arrival_status = 'EN_SITIO';
            $item->save();

            // Guardar o actualizar ubicación en vivo del técnico
            if ($user && $lat && $lng) {
                DB::table('technician_locations')->updateOrInsert(
                    ['user_id' => $user->id],
                    [
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'updated_at' => $now
                    ]
                );
            }

            // Notificar a Root, Admins y Cliente de la llegada del técnico
            try {
                $property = \App\Models\Property::withoutGlobalScopes()->find($item->property_id);
                $propName = $property ? ($property->property_name ?: $property->address) : 'la propiedad';

                // Usar withoutGlobalScopes para asegurar que los usuarios Root y Admin reciban la notificación independientemente del tenant_id
                $admins = User::withoutGlobalScopes()->whereIn('role_id', [0, 1])->get();
                $notif = new \App\Notifications\TechnicianArrivedNotification($user, $item, $propName);

                foreach ($admins as $admin) {
                    $admin->notify($notif);
                }

                if ($property && $property->client_id) {
                    $clientRecord = DB::table('clients')->where('id', $property->client_id)->first();
                    if ($clientRecord && $clientRecord->user_id) {
                        $clientUser = User::withoutGlobalScopes()->find($clientRecord->user_id);
                        if ($clientUser) {
                            $clientUser->notify($notif);
                        }
                    }
                }
            } catch (\Exception $notifErr) {
                Log::warning("No se pudo enviar la notificación de llegada: " . $notifErr->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Llegada confirmada con éxito.',
                'arrived_at' => $now->format('Y-m-d H:i:s'),
                'arrival_status' => 'EN_SITIO'
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al confirmar llegada: ' . $e->getMessage()], 500);
        }
    }

    public function getRootTechniciansLiveMap()
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user || !in_array((int)$user->role_id, [0, 1])) {
                return response()->json(['error' => 'Acceso denegado. Exclusivo para Usuario Root.'], 403);
            }

            // 1. Obtener técnicos de technician_locations
            $locations = DB::table('technician_locations')
                ->join('users', 'technician_locations.user_id', '=', 'users.id')
                ->select(
                    'technician_locations.id as location_id',
                    'technician_locations.user_id',
                    'technician_locations.latitude',
                    'technician_locations.longitude',
                    'technician_locations.updated_at as last_gps_update',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.phone_number',
                    'users.profile_picture'
                )
                ->get()
                ->keyBy('user_id');

            // 2. Incluir técnicos con trabajos asignados o arribo confirmado incluso si no han enviado GPS continuo
            $techsAssigned = DB::table('users')
                ->whereIn('role_id', [2, 4, 5, 7])
                ->select('id as user_id', 'first_name', 'last_name', 'email', 'phone_number', 'profile_picture')
                ->get();
            
            \Log::info("Techs assigned count: " . $techsAssigned->count());

            foreach ($techsAssigned as $t) {
                if (!$locations->has($t->user_id)) {
                    // Si no tiene GPS activo, buscar un trabajo activo asignado para usar las coordenadas de la propiedad
                    $activeJob = DB::table('work_orders')
                        ->join('properties', 'work_orders.property_id', '=', 'properties.id')
                        ->leftJoin('work_order_technician', 'work_orders.id', '=', 'work_order_technician.work_order_id')
                        ->where(function ($q) use ($t) {
                            $q->where('work_orders.tecnico_id', $t->user_id)
                              ->orWhere('work_order_technician.technician_id', $t->user_id);
                        })
                        ->whereNotIn('work_orders.status', ['Listo', 'Finalizado', 'Rechazado', 'Cancelado'])
                        ->select('work_orders.arrived_latitude', 'work_orders.arrived_longitude', 'work_orders.arrived_at', 'properties.coordinates')
                        ->latest('work_orders.updated_at')
                        ->first();

                    if (!$activeJob) {
                        $activeJob = DB::table('services')
                            ->join('properties', 'services.property_id', '=', 'properties.id')
                            ->leftJoin('service_technician', 'services.id', '=', 'service_technician.service_id')
                            ->where(function ($q) use ($t) {
                                $q->where('services.assigned_to', $t->user_id)
                                  ->orWhere('service_technician.technician_id', $t->user_id);
                            })
                            ->whereNotIn('services.status', ['Listo', 'Finalizado', 'Rechazado', 'Cancelado'])
                            ->select('services.arrived_latitude', 'services.arrived_longitude', 'services.arrived_at', 'properties.coordinates')
                            ->latest('services.updated_at')
                            ->first();
                    }
                    
                    \Log::info("Tech ID {$t->user_id} activeJob: " . ($activeJob ? "FOUND" : "NOT FOUND"));

                    if ($activeJob) {
                        // Si ya llegó, usar arrived_latitude, de lo contrario intentar usar las coordenadas de la propiedad
                        $t->latitude = $activeJob->arrived_latitude ?? null;
                        $t->longitude = $activeJob->arrived_longitude ?? null;

                        // Si properties.coordinates tiene "lat,lng" pero latitud está nula, extraemos de ahí
                        if (!$t->latitude && $activeJob->coordinates) {
                            $parts = explode(',', $activeJob->coordinates);
                            if (count($parts) >= 2) {
                                $t->latitude = (float) trim($parts[0]);
                                $t->longitude = (float) trim($parts[1]);
                            }
                        }

                        $t->last_gps_update = $activeJob->arrived_at ?? null;
                        $t->location_id = null;
                        if ($t->latitude && $t->longitude) {
                            $locations->put($t->user_id, $t);
                        }
                    }
                }
            }

            $result = $locations->values()->map(function ($tech) {
                $workOrders = DB::table('work_orders')
                    ->join('properties', 'work_orders.property_id', '=', 'properties.id')
                    ->leftJoin('clients', 'properties.client_id', '=', 'clients.id')
                    ->leftJoin('work_order_technician', 'work_orders.id', '=', 'work_order_technician.work_order_id')
                    ->select(
                        'work_orders.id',
                        'work_orders.type',
                        'work_orders.zone',
                        'work_orders.description',
                        'work_orders.status',
                        'work_orders.arrival_status',
                        'work_orders.arrived_at',
                        'work_orders.arrived_latitude',
                        'work_orders.arrived_longitude',
                        'work_orders.scheduled_at as scheduled_time',
                        'properties.id as property_id',
                        'properties.property_name',
                        'properties.address',
                        'properties.coordinates as property_coordinates',
                        'clients.name as client_name',
                        'clients.phone as client_phone'
                    )
                    ->where(function ($q) use ($tech) {
                        $q->where('work_orders.tecnico_id', $tech->user_id)
                          ->orWhere('work_order_technician.technician_id', $tech->user_id);
                    })
                    ->whereNotIn('work_orders.status', ['Listo', 'Finalizado', 'Rechazado', 'Cancelado'])
                    ->get()
                    ->map(function($wo) {
                        $wo->composite_id = "work_order-{$wo->id}";
                        $wo->tipo_registro = 'work_order';
                        $wo->title = ($wo->type ?? 'Trabajo') . ' - ' . ($wo->zone ?? 'General');
                        
                        $wo->property_latitude = null;
                        $wo->property_longitude = null;
                        if (!empty($wo->property_coordinates)) {
                            $parts = explode(',', $wo->property_coordinates);
                            if (count($parts) >= 2) {
                                $wo->property_latitude = (float) trim($parts[0]);
                                $wo->property_longitude = (float) trim($parts[1]);
                            }
                        }
                        return $wo;
                    });

                $servicios = DB::table('services')
                    ->join('properties', 'services.property_id', '=', 'properties.id')
                    ->leftJoin('clients', 'properties.client_id', '=', 'clients.id')
                    ->leftJoin('service_technician', 'services.id', '=', 'service_technician.service_id')
                    ->select(
                        'services.id',
                        'services.title',
                        'services.description',
                        'services.status',
                        'services.arrival_status',
                        'services.arrived_at',
                        'services.arrived_latitude',
                        'services.arrived_longitude',
                        'services.scheduled_start as scheduled_time',
                        'properties.id as property_id',
                        'properties.property_name',
                        'properties.address',
                        'properties.coordinates as property_coordinates',
                        'clients.name as client_name',
                        'clients.phone as client_phone'
                    )
                    ->where(function ($q) use ($tech) {
                        $q->where('services.assigned_to', $tech->user_id)
                          ->orWhere('service_technician.technician_id', $tech->user_id);
                    })
                    ->whereNotIn('services.status', ['Listo', 'Finalizado', 'Rechazado', 'Cancelado'])
                    ->get()
                    ->map(function($s) {
                        $s->composite_id = "servicio-{$s->id}";
                        $s->tipo_registro = 'servicio';
                        
                        $s->property_latitude = null;
                        $s->property_longitude = null;
                        if (!empty($s->property_coordinates)) {
                            $parts = explode(',', $s->property_coordinates);
                            if (count($parts) >= 2) {
                                $s->property_latitude = (float) trim($parts[0]);
                                $s->property_longitude = (float) trim($parts[1]);
                            }
                        }
                        return $s;
                    });

                $tech->assigned_jobs = $workOrders->concat($servicios)->values();
                return $tech;
            });

            return response()->json([
                'success' => true,
                'data' => $result
            ], 200);

        } catch (\Exception $e) {
            Log::error("Error en getRootTechniciansLiveMap: " . $e->getMessage());
            return response()->json(['error' => 'Error al obtener mapa de técnicos: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Técnico solicita 2da visita para un trabajo no concluido.
     */
    public function solicitarSegundaVisita(Request $request, $id)
    {
        try {
            $parsed = $this->parseCompositeId($id);
            $realId = $parsed['realId'];
            $isWorkOrder = $parsed['isWorkOrder'];

            $request->validate([
                'fecha_propuesta' => 'required|string',
                'motivo' => 'nullable|string'
            ]);

            $model = null;
            if ($isWorkOrder) {
                $model = WorkOrder::withoutGlobalScopes()->find($realId) ?? Service::withoutGlobalScopes()->find($realId);
            } else {
                $model = Service::withoutGlobalScopes()->find($realId) ?? WorkOrder::withoutGlobalScopes()->find($realId);
            }

            if (!$model) {
                return response()->json(['success' => false, 'message' => 'Trabajo no encontrado'], 404);
            }

            $user = $request->user();
            $techName = $user ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) : 'Técnico';
            if (empty($techName)) $techName = $user->name ?? 'Técnico';

            $nota = "\n[SOLICITUD 2DA VISITA]: Técnico {$techName} propone la fecha: {$request->fecha_propuesta}. Motivo: " . ($request->motivo ?? 'Sin motivo especificado.');
            
            $model->status = 'Segunda Visita Solicitada';
            
            // Si la descripción fue contaminada con anteriores notas repetidas de prueba, la limpiamos primero
            $cleanedDesc = $model->description ?? '';
            if ($cleanedDesc) {
                $cleanedDesc = preg_replace('/\n?\[(SOLICITUD 2DA VISITA|RESPUESTA CLIENTE 2DA VISITA|PROGRAMACIÓN DIRECTA 2DA VISITA POR ADMIN)\].*/s', '', $cleanedDesc);
                $cleanedDesc = trim($cleanedDesc);
            }
            // Adjuntamos la nota fresca para que el frontend pueda extraer la fecha y motivo
            $model->description = $cleanedDesc . $nota;
            
            // Las propiedades ahora existen en la base de datos gracias a la migración
            $model->second_visit_proposed_date = $request->fecha_propuesta;
            $model->second_visit_reason = $request->motivo;

            $model->save();

            // Cargar cliente del inmueble si existe
            $property = null;
            if (method_exists($model, 'property') && $model->property) {
                $property = $model->property;
            } else if (isset($model->property_id)) {
                $property = \App\Models\Property::withoutGlobalScopes()->find($model->property_id);
            }

            // 1. Notificar a Admins / Root
            $admins = User::withoutGlobalScopes()->whereIn('role_id', [0, 1])->get();
            if ($admins->count() > 0) {
                Notification::send($admins, new \App\Notifications\SecondVisitRequested($model, $request->fecha_propuesta, $request->motivo, $techName));
            }

            // 2. Notificar al Cliente (buscando por Client email o User ID sin scope de tenant)
            $clientUsers = collect();
            if ($property && !empty($property->client_id)) {
                $clientObj = \App\Models\Client::withoutGlobalScopes()->find($property->client_id);
                if ($clientObj && !empty($clientObj->email)) {
                    $uByEmail = User::withoutGlobalScopes()->where('email', $clientObj->email)->first();
                    if ($uByEmail) $clientUsers->push($uByEmail);
                }
                $uById = User::withoutGlobalScopes()->find($property->client_id);
                if ($uById) $clientUsers->push($uById);
            }
            if (!empty($model->client_id)) {
                $clientObj2 = \App\Models\Client::withoutGlobalScopes()->find($model->client_id);
                if ($clientObj2 && !empty($clientObj2->email)) {
                    $uByEmail2 = User::withoutGlobalScopes()->where('email', $clientObj2->email)->first();
                    if ($uByEmail2) $clientUsers->push($uByEmail2);
                }
                $uById2 = User::withoutGlobalScopes()->find($model->client_id);
                if ($uById2) $clientUsers->push($uById2);
            }

            // También notificar a todos los usuarios clientes (role_id = 3)
            $allClients = User::withoutGlobalScopes()->where('role_id', 3)->get();
            foreach ($allClients as $cUser) {
                $clientUsers->push($cUser);
            }

            $clientUsers = $clientUsers->unique('id');
            if ($clientUsers->count() > 0) {
                Notification::send($clientUsers, new \App\Notifications\SecondVisitRequested($model, $request->fecha_propuesta, $request->motivo, $techName));
            }

            return response()->json([
                'success' => true,
                'message' => 'Solicitud de 2da visita registrada correctamente.',
                'data' => $model
            ], 200);

        } catch (\Exception $e) {
            Log::error("Error en solicitarSegundaVisita: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al solicitar 2da visita: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cliente responde a la solicitud de 2da visita (Aceptar o Reprogramar).
     */
    public function responderSegundaVisita(Request $request, $id)
    {
        try {
            $parsed = $this->parseCompositeId($id);
            $realId = $parsed['realId'];
            $isWorkOrder = $parsed['isWorkOrder'];

            $request->validate([
                'accion' => 'required|in:aceptar,reprogramar',
                'fecha_confirmada' => 'required|string'
            ]);

            $model = null;
            if ($isWorkOrder) {
                $model = WorkOrder::withoutGlobalScopes()->find($realId) ?? Service::withoutGlobalScopes()->find($realId);
            } else {
                $model = Service::withoutGlobalScopes()->find($realId) ?? WorkOrder::withoutGlobalScopes()->find($realId);
            }

            if (!$model) {
                return response()->json(['success' => false, 'message' => 'Trabajo no encontrado'], 404);
            }

            $isAceptar = ($request->accion === 'aceptar');

            if ($isAceptar) {
                $model->status = 'Segunda Visita Programada';
                try {
                    if (isset($model->scheduled_at)) {
                        $model->scheduled_at = $request->fecha_confirmada;
                    }
                    if (isset($model->scheduled_start)) {
                        $model->scheduled_start = $request->fecha_confirmada;
                    }
                } catch (\Exception $e) {}
                $nota = "\n[RESPUESTA 2DA VISITA]: Cita ACEPTADA para la fecha: " . $request->fecha_confirmada;
            } else {
                // Si propone una nueva fecha (reprogramar), se mantiene como Solicitada / Reprogramada
                $model->status = 'Segunda Visita Solicitada';
                $model->second_visit_proposed_date = $request->fecha_confirmada;
                $nota = "\n[ALERTA DE REPROGRAMACIÓN 2DA VISITA]: Nueva fecha propuesta: " . $request->fecha_confirmada;
            }

            $model->description = ($model->description ?? '') . $nota;
            $model->save();

            // Notificar a Root y Administradores (rol 0 y 1)
            $admins = User::withoutGlobalScopes()->whereIn('role_id', [0, 1])->get();
            if ($admins->count() > 0) {
                Notification::send($admins, new \App\Notifications\SecondVisitAgreed($model, $request->fecha_confirmada, $request->accion));
            }

            // Notificar al Técnico Asignado (revisando asignaciones de WorkOrder y Service)
            $techId = $model->tecnico_id ?? $model->assigned_to ?? $model->technician_id ?? null;
            if ($techId) {
                $techUser = User::withoutGlobalScopes()->find($techId);
                if ($techUser) {
                    Notification::send($techUser, new \App\Notifications\SecondVisitAgreed($model, $request->fecha_confirmada, $request->accion));
                }
            }

            // Notificar al Cliente si fue aceptada/reprogramada por técnico o admin
            if (isset($model->property_id) && $model->property_id) {
                $prop = \App\Models\Property::withoutGlobalScopes()->find($model->property_id);
                if ($prop && $prop->user_id) {
                    $clientUser = User::withoutGlobalScopes()->find($prop->user_id);
                    if ($clientUser) {
                        Notification::send($clientUser, new \App\Notifications\SecondVisitAgreed($model, $request->fecha_confirmada, $request->accion));
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => $isAceptar ? 'Segunda visita programada con éxito.' : 'Nueva fecha propuesta registrada.',
                'data' => $model
            ], 200);

        } catch (\Exception $e) {
            Log::error("Error en responderSegundaVisita: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al procesar respuesta: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Root / Admin programa directamente una 2da visita (a solicitud del cliente).
     */
    public function adminProgramarSegundaVisita(Request $request, $id)
    {
        try {
            $parsed = $this->parseCompositeId($id);
            $realId = $parsed['realId'];
            $isWorkOrder = $parsed['isWorkOrder'];

            $request->validate([
                'fecha_programada' => 'required|string',
                'tecnico_id' => 'nullable|integer',
                'observaciones' => 'nullable|string'
            ]);

            $model = null;
            if ($isWorkOrder) {
                $model = WorkOrder::withoutGlobalScopes()->find($realId) ?? Service::withoutGlobalScopes()->find($realId);
            } else {
                $model = Service::withoutGlobalScopes()->find($realId) ?? WorkOrder::withoutGlobalScopes()->find($realId);
            }

            if (!$model) {
                return response()->json(['success' => false, 'message' => 'Trabajo no encontrado'], 404);
            }

            if (!empty($request->tecnico_id)) {
                $model->assigned_to = $request->tecnico_id;
            }

            $nota = "\n[PROGRAMACIÓN DIRECTA 2DA VISITA POR ADMIN]: Fecha: " . $request->fecha_programada . ($request->observaciones ? ". Observaciones: " . $request->observaciones : '');

            $model->status = 'Segunda Visita Programada';
            try {
                if (isset($model->scheduled_at)) {
                    $model->scheduled_at = $request->fecha_programada;
                }
            } catch (\Exception $e) {}
            $model->description = ($model->description ?? '') . $nota;
            $model->save();

            // Notificar a Cliente y al Técnico
            $property = null;
            if (method_exists($model, 'property') && $model->property) {
                $property = $model->property;
            } else if (isset($model->property_id)) {
                $property = \App\Models\Property::withoutGlobalScopes()->find($model->property_id);
            }

            if ($property && $property->client_id) {
                $clientUser = User::withoutGlobalScopes()->where('id', $property->client_id)->first();
                if ($clientUser) {
                    Notification::send($clientUser, new \App\Notifications\SecondVisitAdminScheduled($model, $request->fecha_programada, $request->observaciones));
                }
            }

            if (!empty($model->assigned_to)) {
                $techUser = User::withoutGlobalScopes()->find($model->assigned_to);
                if ($techUser) {
                    Notification::send($techUser, new \App\Notifications\SecondVisitAdminScheduled($model, $request->fecha_programada, $request->observaciones));
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Segunda visita programada directamente por el Administrador.',
                'data' => $model
            ], 200);

        } catch (\Exception $e) {
            Log::error("Error en adminProgramarSegundaVisita: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al programar 2da visita por Admin: ' . $e->getMessage()], 500);
        }
    }
}
