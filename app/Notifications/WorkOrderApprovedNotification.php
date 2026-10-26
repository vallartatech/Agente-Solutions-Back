<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\WorkOrder;

/**
 * Aviso al técnico cuando un admin aprueba su trabajo y lo da por finalizado.
 * La push sale sola por PushForDatabaseNotifications.
 */
class WorkOrderApprovedNotification extends Notification
{
    use Queueable;

    protected $workOrder;
    protected $adminName;

    public function __construct(WorkOrder $workOrder, string $adminName)
    {
        $this->workOrder = $workOrder;
        $this->adminName = $adminName;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $propertyName = $this->workOrder->property
            ? ($this->workOrder->property->property_name ?: $this->workOrder->property->address)
            : 'la propiedad';

        return [
            'work_order_id' => $this->workOrder->id,
            'property_id' => $this->workOrder->property_id,
            'title' => 'Trabajo Aprobado',
            'message' => "{$this->adminName} aprobó tu trabajo #{$this->workOrder->id} en {$propertyName}. Quedó finalizado correctamente.",
            'alert_type' => 'work_order_approved',
            'url' => '/trabajos-tecnico'
        ];
    }
}
