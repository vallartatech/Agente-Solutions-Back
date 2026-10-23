<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinalWorkReport extends Model
{
    protected $fillable = [
        'service_id',
        'work_order_id',
        'version',
        'folio',
        'logo_url',
        'report_date',
        'start_time',
        'end_time',
        'description',
        'materials',
        'observations',
        'selected_images',
        'data',
        'created_by',
    ];

    protected $casts = [
        'materials' => 'array',
        'selected_images' => 'array',
        'data' => 'array',
    ];

    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScopes();
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
