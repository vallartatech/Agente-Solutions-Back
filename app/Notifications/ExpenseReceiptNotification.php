<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Comprobantes de gastos para la campanita (base de datos).
 * La push se envía en lote desde ExpenseReceiptController con App\Services\OneSignalPush.
 */
class ExpenseReceiptNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected $receipt,
        protected string $title,
        protected string $message,
        protected string $url = '/comprobantes'
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function pushTitle(): string
    {
        return $this->title;
    }

    public function pushBody(): string
    {
        return $this->message;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function toDatabase($notifiable)
    {
        return [
            'titulo' => $this->title,
            'title' => $this->title,
            'mensaje' => $this->message,
            'message' => $this->message,
            'receipt_id' => $this->receipt->id ?? null,
            'company' => $this->receipt->company ?? null,
            'alert_type' => 'expense_receipt',
            'url' => $this->url,
        ];
    }
}
