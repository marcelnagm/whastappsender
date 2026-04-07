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

        // 1. Simular Presença (Digitando ou Gravando)
        // Alternamos entre 'composing' (digitando) e 'recording' (gravando) aleatoriamente
        $presenceType = rand(0, 1) ? 'composing' : 'recording';

        $presenceEndpoint = "{$baseUrl}/chat/retrivePresence/{$this->senderName}";

        Http::withHeaders([
            'apikey' => $config['apikey'],
            'Content-Type' => 'application/json'
        ])->post($presenceEndpoint, [
            'number' => $this->receiverPhone,
            'presence' => $presenceType,
            'delay' => rand(1500, 3000) // Tempo que o status ficará visível para o alvo
        ]);

        // Pequena pausa no PHP para a presença ser notada antes do texto chegar
        usleep(500000); // 0.5 segundos

        // 2. Enviar o Texto Real
        $textEndpoint = "{$baseUrl}/message/sendText/{$this->senderName}";

        Http::withHeaders([
            'apikey' => $config['apikey'],
            'Content-Type' => 'application/json'
        ])->post($textEndpoint, [
            'number' => $this->receiverPhone,
            'text' => $this->frase,
            'delay' => rand(1000, 2000) // Delay adicional interno da API
        ]);
    }
}
