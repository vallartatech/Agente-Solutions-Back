<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseReceiptFile extends Model
{
    protected $table = 'expense_receipt_files';

    protected $fillable = [
        'expense_receipt_id',
        'url',
        'public_id',
        'mime',
    ];

    protected $hidden = ['public_id'];

    public function receipt()
    {
        return $this->belongsTo(ExpenseReceipt::class, 'expense_receipt_id');
    }
}
