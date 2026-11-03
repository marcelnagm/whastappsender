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

    /** @return int messages imported */
    public function poll(Instance $instance): int
    {
        if (!$instance->isEmail() || !$instance->imap_host) {
            return 0;
        }

        $client = null;
        try {
            $client = Mailbox::open($instance);
            $box = $client->status();

            // first check, or the server renumbered the mailbox: start from "now", never import old history
            if ($instance->email_uidvalidity === null || (int) $instance->email_uidvalidity !== (int) $box['uidvalidity'] || ($instance->email_last_uid > 0 && ($box['uidnext'] ?? 0) <= $instance->email_last_uid)) {
                $instance->forceFill(['email_uidvalidity' => $box['uidvalidity'], 'email_last_uid' => max(0, ((int) ($box['uidnext'] ?? 1)) - 1)]);
                $this->ok($instance);
                $client->close();

                return 0;
            }

            $imported = 0;
            $last = (int) $instance->email_last_uid;
            $max = max(1, (int) config('email.max_per_poll', 20));
            foreach ($client->newerThan($last, $max) as $uid => $parsed) {
                try {
                    if ($this->inbound->handle($instance, $parsed)) {
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('E-mail not imported: ' . $e->getMessage(), ['instance_id' => $instance->id, 'uid' => $uid]);
                }
                $last = max($last, $uid); // broken messages are skipped, never retried forever
            }

            $instance->email_last_uid = $last;
            $this->ok($instance);
            $client->close();

            return $imported;
        } catch (\Throwable $e) {
            $instance->forceFill(['status' => 'disconnected', 'email_error' => mb_substr($e->getMessage(), 0, 250), 'email_checked_at' => now()])->save();
            $client?->close();

            return 0;
        }
    }

    private function ok(Instance $instance): void
    {
        $instance->forceFill(['status' => 'connected', 'email_error' => null, 'email_checked_at' => now()])->save();
    }
}
