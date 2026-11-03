<?php

namespace App\Services\Email;

use App\Models\Instance;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * Thin glue over the webklex/php-imap library: it does the connecting, the IMAP protocol and the MIME parsing;
 * this class only opens the account of an Instance and turns a library Message into the array EmailInbound consumes.
 * Messages are fetched without marking them as read in the user's mailbox.
 */
class Mailbox
{
    /** @var callable|null tests: fn (Instance $i): object with status(), newerThan(int,int), close() */
    public static $factory = null;

    private ?object $folder = null;

    private function __construct(private Client $client)
    {
    }

    /** @throws EmailException */
    public static function open(Instance $i): object
    {
        if (self::$factory) {
            return (self::$factory)($i);
        }
        EmailHostGuard::assert((string) $i->imap_host, (int) $i->imap_port, 'imap');

        try {
            $client = (new ClientManager(['options' => ['fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK]]))->make([
                'host' => $i->imap_host,
                'port' => (int) $i->imap_port,
                'encryption' => $i->imap_encryption === 'none' ? false : ($i->imap_encryption ?: 'ssl'),
                'validate_cert' => true,
                'username' => $i->imap_username ?: $i->smtp_username,
                'password' => $i->imap_password ?: $i->smtp_password,
                'protocol' => 'imap',
                'timeout' => (int) config('email.timeout', 20),
            ]);
            $client->connect();
        } catch (\Throwable $e) {
            throw new EmailException('IMAP: ' . $e->getMessage());
        }

        return new self($client);
    }

    /** @return array{uidvalidity:int,uidnext:int} */
    public function status(): array
    {
        $s = $this->inbox()->examine();

        return ['uidvalidity' => (int) ($s['uidvalidity'] ?? 0), 'uidnext' => (int) ($s['uidnext'] ?? 1)];
    }

    /** @return array<int,array> uid => parsed message, oldest first, at most $max, already above $lastUid */
    public function newerThan(int $lastUid, int $max): array
    {
        $out = [];
        $messages = $this->inbox()->query()->leaveUnread()->setFetchOrderAsc()->softFail()->getByUidGreater($lastUid);
        foreach ($messages as $message) {
            if (count($out) >= $max) {
                break;
            }
            $out[(int) $message->getUid()] = self::map($message);
        }

        return $out;
    }

    public function close(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable $e) {
        }
    }

    private function inbox(): object
    {
        return $this->folder ??= $this->client->getFolderByPath('INBOX');
    }

    /** The array EmailInbound works with (same shape whatever the source of the message). */
    public static function map(Message $m): array
    {
        $from = $m->getFrom()->first();
        $email = $from && $from->mail ? strtolower($from->mail) : null;
        $local = strtolower(explode('@', (string) $email)[0]);
        $dec = fn (string $v) => str_contains($v, '=?') ? trim((string) iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')) : $v;
        $h = fn (string $n) => trim((string) $m->getHeader()->get($n));

        $text = trim($m->getTextBody());
        if ($text === '') {
            $html = preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $m->getHTMLBody());
            $html = preg_replace('#<\s*br\s*/?>|</\s*(p|div|tr|li|h[1-6])\s*>#i', "\n", $html);
            $text = trim(preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }

        $attachments = [];
        $maxBytes = (int) config('email.max_attachment_bytes', 10485760);
        foreach ($m->getAttachments() as $a) {
            $data = (string) $a->getContent();
            if ($data !== '' && strlen($data) <= $maxBytes && count($attachments) < 5) {
                $attachments[] = ['name' => preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', (string) ($a->getName() ?: 'attachment')), 'mime' => (string) ($a->getMimeType() ?: 'application/octet-stream'), 'data' => $data];
            }
        }

        $ids = fn (string $v) => array_slice(array_values(array_filter(preg_split('/[\s,<>]+/', $v))), -10); // the library hands ids over without the angle brackets
        $auto = ($h('auto_submitted') !== '' && strtolower($h('auto_submitted')) !== 'no')
            || $h('x_autoreply') !== '' || $h('x_autorespond') !== '' || $h('list_id') !== '' || $h('list_unsubscribe') !== ''
            || in_array(strtolower($h('precedence')), ['bulk', 'junk', 'list', 'auto_reply'], true)
            || (bool) preg_match('/^(no-?reply|do-?not-?reply|notifications?|newsletter)/', $local);

        return [
            'subject' => trim($dec((string) $m->getSubject())),
            'from_email' => $email,
            'from_name' => $from && trim($dec($from->personal)) !== '' ? mb_substr(trim($dec($from->personal)), 0, 120) : null,
            'to' => ($t = $m->getTo()->first()) ? $t->mail : null,
            'message_id' => ($id = $ids($h('message_id'))) ? $id[0] : null,
            'in_reply_to' => ($id = $ids($h('in_reply_to'))) ? $id[0] : null,
            'references' => $ids($h('references')),
            'date' => ($d = $m->getDate()->first()) ? $d->toRfc2822String() : null,
            'text' => $text,
            'attachments' => $attachments,
            'auto' => $auto,
            'bounce' => in_array($local, ['mailer-daemon', 'postmaster'], true),
        ];
    }
}
