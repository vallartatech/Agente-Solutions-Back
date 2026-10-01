<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewNetworkQuoteChatMessageNotification extends Notification
{
    use Queueable;

    protected $networkQuote;
    protected $senderName;
    protected $senderRole;

    public function __construct($networkQuote, $senderName, $senderRole)
    {
        $this->networkQuote = $networkQuote;
        $this->senderName = $senderName;
        $this->senderRole = $senderRole;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        $jobTitle = $this->networkQuote->workOrder 
            ? ($this->networkQuote->workOrder->type . ($this->networkQuote->workOrder->equipment ? ' - ' . $this->networkQuote->workOrder->equipment : '')) 
            : 'Trabajo en Red';
        
        $isTech = in_array((int)$notifiable->role_id, [2, 6, 8]);
        $targetUrl = $isTech ? '/mercado-trabajos' : '/red-autonomos';

        return [
            'network_quote_id' => $this->networkQuote->id,
            'work_order_id' => $this->networkQuote->work_order_id,
            'alert_type' => 'new_quote_message',
            'title' => '💬 Nuevo mensaje en la Red',
            'message' => "({$this->senderRole}) {$this->senderName} te envió un mensaje sobre: {$jobTitle}.",
            'url' => $targetUrl,
            'sender_name' => $this->senderName,
            'sender_role' => $this->senderRole,
        ];
    }
}