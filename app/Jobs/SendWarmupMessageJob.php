<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendWarmupMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $senderName;
    protected $receiverPhone;
    protected $frase;

    public function __construct($senderName, $receiverPhone, $frase)
    {
        $this->senderName = $senderName;
        $this->receiverPhone = $receiverPhone;
        $this->frase = $frase;
    }

    public function handle()
    {
        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";

        // 1. Simulate presence (typing vs recording)
        // Randomly alternate composing/recording states
        $presenceType = rand(0, 1) ? 'composing' : 'recording';

        $presenceEndpoint = "{$baseUrl}/chat/retrivePresence/{$this->senderName}";

        Http::withHeaders([
            'apikey' => $config['apikey'],
            'Content-Type' => 'application/json'
        ])->post($presenceEndpoint, [
            'number' => $this->receiverPhone,
            'presence' => $presenceType,
            'delay' => rand(1500, 3000) // How long the presence bubble stays visible
        ]);

        // Short pause so the presence is visible before the text arrives
        usleep(500000); // 0.5 seconds

        // 2. Send the actual text
        $textEndpoint = "{$baseUrl}/message/sendText/{$this->senderName}";

        Http::withHeaders([
            'apikey' => $config['apikey'],
            'Content-Type' => 'application/json'
        ])->post($textEndpoint, [
            'number' => $this->receiverPhone,
            'text' => $this->frase,
            'delay' => rand(1000, 2000) // Extra API-side delay
        ]);
    }
}
