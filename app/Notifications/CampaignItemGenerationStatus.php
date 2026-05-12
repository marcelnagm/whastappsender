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
        $title = 'Send job generation';
        $message = "Item {$this->campaignItemName} process updated.";

        if ($this->status === 'started') {
            $title = 'Generation started';
            $message = "Async generation started for item {$this->campaignItemName}.";
        } elseif ($this->status === 'completed') {
            $title = 'Generation completed';
            $message = "Send job generation for item {$this->campaignItemName} is complete.";
        } elseif ($this->status === 'error') {
            $title = 'Generation failed';
            $message = "Failed to generate send jobs for item {$this->campaignItemName}.";
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
