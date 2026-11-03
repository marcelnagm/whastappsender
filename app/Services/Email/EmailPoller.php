<?php

namespace App\Services\Email;

use App\Models\Instance;
use Illuminate\Support\Facades\Log;

/** Checks the mailbox of one e-mail instance and hands every new message to EmailInbound. Only mail that arrives after the connection is imported. */
class EmailPoller
{
    public function __construct(private EmailInbound $inbound)
    {
    }

    /** @var callable|null tests: fn (Instance $i): ImapClient */
    public static $clientFactory = null;

    /** @return int messages imported */
    public function poll(Instance $instance): int
    {
        if (!$instance->isEmail() || !$instance->imap_host) {
            return 0;
        }

        $client = null;
        try {
            $client = self::$clientFactory ? (self::$clientFactory)($instance) : ImapClient::connect($instance);
            $box = $client->select('INBOX');

            // first check, or the server renumbered the mailbox: start from "now", never import old history
            if ($instance->email_uidvalidity === null || (int) $instance->email_uidvalidity !== (int) $box['uidvalidity'] || ($instance->email_last_uid > 0 && ($box['uidnext'] ?? 0) <= $instance->email_last_uid)) {
                $instance->forceFill(['email_uidvalidity' => $box['uidvalidity'], 'email_last_uid' => max(0, ((int) ($box['uidnext'] ?? 1)) - 1)]);
                $this->ok($instance);
                $client->logout();

                return 0;
            }

            $imported = 0;
            $last = (int) $instance->email_last_uid;
            $max = max(1, (int) config('email.max_per_poll', 20));
            foreach (array_slice($client->uidsAfter($last), 0, $max) as $uid) {
                $size = $client->size($uid);
                if ($size === 0 || $size <= (int) config('email.max_message_bytes', 10485760)) {
                    $raw = $client->fetch($uid);
                    if ($raw !== null && $raw !== '') {
                        try {
                            if ($this->inbound->handle($instance, $raw)) {
                                $imported++;
                            }
                        } catch (\Throwable $e) {
                            Log::warning('E-mail not imported: ' . $e->getMessage(), ['instance_id' => $instance->id, 'uid' => $uid]);
                        }
                    }
                }
                $last = $uid; // too big or broken messages are skipped, never retried forever
            }

            $instance->email_last_uid = $last;
            $this->ok($instance);
            $client->logout();

            return $imported;
        } catch (\Throwable $e) {
            $instance->forceFill(['status' => 'disconnected', 'email_error' => mb_substr($e->getMessage(), 0, 250), 'email_checked_at' => now()])->save();
            $client?->logout();

            return 0;
        }
    }

    private function ok(Instance $instance): void
    {
        $instance->forceFill(['status' => 'connected', 'email_error' => null, 'email_checked_at' => now()])->save();
    }
}
