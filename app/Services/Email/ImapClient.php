<?php

namespace App\Services\Email;

use App\Models\Instance;

/**
 * Just enough IMAP (RFC 3501) to read new mail: LOGIN, SELECT, UID SEARCH, UID FETCH. No extension or library needed.
 * Messages are fetched with BODY.PEEK, so nothing is marked as read in the user's mailbox.
 */
class ImapClient
{
    private int $n = 0;
    private array $selected = [];

    public function __construct(private ImapTransport $t)
    {
    }

    /** @throws EmailException */
    public static function connect(Instance $i): self
    {
        EmailHostGuard::assert((string) $i->imap_host, (int) $i->imap_port, 'imap');
        $timeout = (int) config('email.timeout', 20);

        $client = new self(new SocketImapTransport((string) $i->imap_host, (int) $i->imap_port, $i->imap_encryption === 'ssl', $timeout));
        $client->open($i->imap_encryption === 'tls');
        $client->login((string) ($i->imap_username ?: $i->smtp_username), (string) ($i->imap_password ?: $i->smtp_password));

        return $client;
    }

    /** @throws EmailException */
    public function open(bool $startTls = false): void
    {
        $greeting = (string) $this->t->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw new EmailException(__('IMAP: unexpected answer from the server.'));
        }
        if ($startTls) {
            $this->command('STARTTLS');
            if (!$this->t->enableTls()) {
                throw new EmailException(__('IMAP: the server did not accept a secure connection.'));
            }
        }
    }

    /** @throws EmailException */
    public function login(string $user, string $pass): void
    {
        if (preg_match('/[\r\n\0]/', $user . $pass)) {
            throw new EmailException(__('IMAP: invalid characters in the login.'));
        }
        try {
            $this->command('LOGIN ' . $this->quote($user) . ' ' . $this->quote($pass));
        } catch (EmailException $e) {
            throw new EmailException(__('IMAP: login refused (:error)', ['error' => str_replace([$user, $pass], '***', $e->getMessage())]));
        }
    }

    /** @return array{exists:int,uidvalidity:?int,uidnext:?int} */
    public function select(string $mailbox = 'INBOX'): array
    {
        $r = $this->command('SELECT ' . $this->quote($mailbox));
        $info = ['exists' => 0, 'uidvalidity' => null, 'uidnext' => null];
        foreach ($r as $e) {
            if (preg_match('/^\* (\d+) EXISTS/i', $e['line'], $m)) {
                $info['exists'] = (int) $m[1];
            }
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $e['line'], $m)) {
                $info['uidvalidity'] = (int) $m[1];
            }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $e['line'], $m)) {
                $info['uidnext'] = (int) $m[1];
            }
        }

        return $this->selected = $info;
    }

    /** @return int[] UIDs greater than $after, ascending */
    public function uidsAfter(int $after): array
    {
        $r = $this->command('UID SEARCH UID ' . ($after + 1) . ':*');
        $uids = [];
        foreach ($r as $e) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', $e['line'], $m)) {
                foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $u) {
                    if ((int) $u > $after) { // "n:*" always returns the last message even when it is older than n
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);

        return array_values(array_unique($uids));
    }

    public function size(int $uid): int
    {
        foreach ($this->command("UID FETCH {$uid} (RFC822.SIZE)") as $e) {
            if (preg_match('/RFC822\.SIZE (\d+)/i', $e['line'], $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** Raw RFC 822 message (does not set \Seen) */
    public function fetch(int $uid): ?string
    {
        foreach ($this->command("UID FETCH {$uid} (BODY.PEEK[])") as $e) {
            if ($e['literals']) {
                return $e['literals'][0];
            }
        }

        return null;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\Throwable $e) {
            // closing anyway
        }
        $this->t->close();
    }

    /**
     * Sends one command and reads until its tagged answer.
     *
     * @return array<int,array{line:string,literals:string[]}> untagged responses ({n} literals pulled out of the line)
     * @throws EmailException on NO / BAD / lost connection
     */
    private function command(string $cmd): array
    {
        $tag = 'A' . (++$this->n);
        $this->t->write($tag . ' ' . $cmd . "\r\n");

        $out = [];
        while (true) {
            $line = $this->t->readLine();
            if ($line === null) {
                throw new EmailException(__('IMAP: connection lost.'));
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $line)) {
                    throw new EmailException(trim(substr($line, strlen($tag) + 1)));
                }

                return $out;
            }

            $literals = [];
            $text = $line;
            while (preg_match('/\{(\d+)\}$/', $text, $m)) {
                $size = (int) $m[1];
                if ($size > (int) config('email.max_message_bytes', 10485760) + 1024) {
                    throw new EmailException(__('IMAP: message too large.'));
                }
                $literals[] = $this->t->read($size);
                $rest = $this->t->readLine();
                if ($rest === null) {
                    throw new EmailException(__('IMAP: connection lost.'));
                }
                $text .= $rest;
            }
            $out[] = ['line' => $text, 'literals' => $literals];
        }
    }

    private function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
