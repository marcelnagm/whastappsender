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
        
        $endpoint = "{$baseUrl}/message/sendText/{$this->senderName}";

        Http::withHeaders([
            'apikey' => $config['apikey'],
            'Content-Type' => 'application/json'
        ])->post($endpoint, [
            'number' => $this->receiverPhone,
            'text' => $this->frase,
            'delay' => rand(2000, 4000) // Delay de digitação da Evolution
        ]);
    }
}