<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CampaignItemGenerationStatus extends Notification
{
    use Queueable;

    private int $campaignItemId;
    private string $campaignItemName;
    private string $status;
    private ?string $details;

    public function __construct(int $campaignItemId, string $campaignItemName, string $status, ?string $details = null)
    {
        $this->campaignItemId = $campaignItemId;
        $this->campaignItemName = $campaignItemName;
        $this->status = $status;
        $this->details = $details;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $title = 'Geração de disparos';
        $message = "Processo do item {$this->campaignItemName} atualizado.";

        if ($this->status === 'started') {
            $title = 'Geração iniciada';
            $message = "Iniciamos a geração assíncrona para o item {$this->campaignItemName}.";
        } elseif ($this->status === 'completed') {
            $title = 'Geração concluída';
            $message = "A geração de disparos do item {$this->campaignItemName} foi concluída.";
        } elseif ($this->status === 'error') {
            $title = 'Geração com erro';
            $message = "Falha ao gerar disparos do item {$this->campaignItemName}.";
        }

        if ($this->details) {
            $message .= ' ' . $this->details;
        }

        return [
            'title' => $title,
            'message' => $message,
            'status' => $this->status,
            'context' => 'campaign_item_generate',
            'campaign_item_id' => $this->campaignItemId,
        ];
    }
}
