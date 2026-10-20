<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseReceipt extends Model
{
    public const COMPANIES = [
        'agente' => 'Agente Solutions',
        'mantenere' => 'Mantenere',
    ];

    protected $table = 'expense_receipts';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'company',
        'note',
        'amount',
        'status',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function files()
    {
        return $this->hasMany(ExpenseReceiptFile::class);
    }

    public function companyName(): string
    {
        return self::COMPANIES[$this->company] ?? $this->company;
    }
}
