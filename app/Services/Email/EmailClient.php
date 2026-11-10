<?php

namespace App\Services\Email;

use App\Models\Instance;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Sends mail through the user's own SMTP server (credentials stored encrypted on the instance). */
class EmailClient
{
    /** tests replace the real SMTP connection: fn (Instance $i): TransportInterface */
    public static $transportFactory = null;

    public function __construct(private Instance $instance)
    {
    }

    public static function for(Instance $instance): self
    {
        return new self($instance);
    }

    private function transport(): TransportInterface
    {
        if (self::$transportFactory) {
            return (self::$transportFactory)($this->instance);
        }

        $i = $this->instance;
        EmailHostGuard::assert((string) $i->smtp_host, (int) $i->smtp_port, 'smtp');

        $t = new EsmtpTransport((string) $i->smtp_host, (int) $i->smtp_port, $i->smtp_encryption === 'ssl');
        $t->setUsername((string) $i->smtp_username);
        $t->setPassword((string) $i->smtp_password);
        $t->getStream()->setTimeout((float) config('email.timeout', 20));

        return $t;
    }

    /** Opens the SMTP connection and logs in, without sending anything. @throws EmailException */
    public function check(): void
    {
        try {
            $t = $this->transport();
            if ($t instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport) {
                $t->start();
                $t->stop();
            }
        } catch (EmailException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EmailException(__('SMTP: :error', ['error' => $this->clean($e->getMessage())]));
        }
    }

    /**
     * @param array{in_reply_to?:?string,references?:string[],auto?:bool,attachments?:array<int,array{data:string,name:string,mime:?string}>} $o
     * @return string the Message-ID (without < >) given to the message
     * @throws EmailException
     */
    /** The mailbox signature goes after a standard "-- " separator; none set = the text as it is. */
    private function withSignature(string $text): string
    {
        $sig = trim((string) $this->instance->email_signature);

        return $sig === '' ? $text : rtrim($text) . "\n\n-- \n" . $sig;
    }

    public function send(string $to, string $subject, string $text, array $o = []): string
    {
        $i = $this->instance;
        $domain = substr(strrchr((string) $i->email_address, '@') ?: '@localhost', 1);
        $id = bin2hex(random_bytes(16)) . '@' . $domain;

        $mail = (new Email())
            ->from(new Address((string) $i->email_address, (string) ($i->email_from_name ?: '')))
            ->to($to)
            ->subject($subject)
            ->text($this->withSignature($text));

        $h = $mail->getHeaders();
        $h->addIdHeader('Message-ID', $id);
        if (!empty($o['in_reply_to'])) {
            $h->addIdHeader('In-Reply-To', $o['in_reply_to']);
            $h->addIdHeader('References', array_values(array_unique(array_merge((array) ($o['references'] ?? []), [$o['in_reply_to']]))));
        }
        if (!empty($o['auto'])) {
            // tells other autoresponders not to answer this one (no mail loops)
            $h->addTextHeader('Auto-Submitted', 'auto-generated');
            $h->addTextHeader('X-Auto-Response-Suppress', 'All');
        }
        foreach ((array) ($o['attachments'] ?? []) as $a) {
            $mail->attach($a['data'], $a['name'], $a['mime'] ?: 'application/octet-stream');
        }

        try {
            $this->transport()->send($mail);
        } catch (EmailException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EmailException(__('SMTP: :error', ['error' => $this->clean($e->getMessage())]));
        }

        return $id;
    }

    /** Server messages can echo the login: never show it back. */
    private function clean(string $m): string
    {
        $m = preg_replace('/\s+/', ' ', $m);
        foreach ([$this->instance->smtp_password, $this->instance->smtp_username] as $secret) {
            if ($secret) {
                $m = str_replace((string) $secret, '***', $m);
            }
        }

        return mb_substr($m, 0, 300);
    }
}
