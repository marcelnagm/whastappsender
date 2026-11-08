<?php

namespace App\Services\Export;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/** CSV backups of the account's own data, streamed in chunks. Cells are neutralised against spreadsheet formula injection. */
class DataExport
{
    public const MAX_MESSAGES = 200000;

    /** key => label */
    public static function types(): array
    {
        return ['contacts' => __('Contacts'), 'companies' => __('Companies'), 'deals' => __('Deals'), 'tasks' => __('Tasks'), 'messages' => __('Messages'), 'consent' => __('Consent history')];
    }

    public static function cell($v): string
    {
        $v = is_bool($v) ? (int) $v : (string) ($v ?? '');
        $v = str_replace(["\r", "\n"], ' ', $v);

        return preg_match('/^[=+\-@\t]/', $v) ? "'" . $v : $v;
    }

    /** Writes the CSV for one type to an open stream. */
    public function write($out, string $type, int $userId): void
    {
        $put = fn (array $row) => fputcsv($out, array_map([self::class, 'cell'], $row), ';');
        fwrite($out, "\xEF\xBB\xBF");

        switch ($type) {
            case 'contacts':
                $put(['id', 'name', 'phone', 'email', 'status', 'consent', 'score', 'company_id', 'created_at']);
                Contact::where('user_id', $userId)->chunkById(500, fn ($rows) => $rows->each(fn ($c) => $put([$c->id, $c->name, $c->contact, $c->email, $c->status, $c->consent_status, $c->score, $c->company_id, $c->created_at])));
                break;
            case 'companies':
                $put(['id', 'name', 'domain', 'industry', 'phone', 'created_at']);
                Company::where('user_id', $userId)->chunkById(500, fn ($rows) => $rows->each(fn ($c) => $put([$c->id, $c->name, $c->domain, $c->industry, $c->phone, $c->created_at])));
                break;
            case 'deals':
                $put(['id', 'title', 'value', 'status', 'stage_id', 'contact_id', 'company_id', 'origin', 'created_at', 'closed_at']);
                Deal::where('user_id', $userId)->chunkById(500, fn ($rows) => $rows->each(fn ($d) => $put([$d->id, $d->title, $d->value, $d->status, $d->stage_id, $d->contact_id, $d->company_id, $d->origin, $d->created_at, $d->closed_at])));
                break;
            case 'tasks':
                $put(['id', 'title', 'due_at', 'done_at', 'assigned_to', 'contact_id', 'recurrence', 'created_at']);
                Task::where('user_id', $userId)->chunkById(500, fn ($rows) => $rows->each(fn ($t) => $put([$t->id, $t->title, $t->due_at, $t->done_at, $t->assigned_to, $t->contact_id, $t->recurrence, $t->created_at])));
                break;
            case 'messages':
                $put(['id', 'chat_id', 'direction', 'type', 'text', 'source', 'sent_by', 'at']);
                $n = 0;
                ChatMessage::whereIn('chat_id', Chat::where('user_id', $userId)->select('id'))->chunkById(1000, function ($rows) use ($put, &$n) {
                    foreach ($rows as $m) {
                        $put([$m->id, $m->chat_id, $m->from_me ? 'out' : 'in', $m->type, $m->body, $m->source, $m->sent_by, $m->message_timestamp]);
                    }
                    $n += $rows->count();

                    return $n < self::MAX_MESSAGES;
                });
                break;
            case 'consent':
                $put(['id', 'contact_id', 'action', 'source', 'created_at']);
                DB::table('consent_logs')->where('user_id', $userId)->orderBy('id')->chunk(1000, function ($rows) use ($put) {
                    foreach ($rows as $r) {
                        $put([$r->id, $r->contact_id, $r->action, $r->source, $r->created_at]);
                    }
                });
                break;
        }
    }
}
