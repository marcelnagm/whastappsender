<?php

namespace App\Mail;

use App\Models\Instance;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InstanceDisconnectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $instance;

    public function __construct(Instance $instance)
    {
        $this->instance = $instance;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⚠️ Atenção: WhatsApp Desconectado - ' . $this->instance->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.instance_disconnected', // Onde está o HTML do e-mail
        );
    }
}
