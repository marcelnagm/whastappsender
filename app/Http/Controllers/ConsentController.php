<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\Consent\Consent;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Consent register: who agreed to receive messages and how, the account switch that makes campaigns respect it, and the exportable proof. */
class ConsentController extends Controller
{
    public function index(Request $request)
    {
        $owner = $request->user();
        $status = Contact::where('user_id', $owner->id)->selectRaw('consent_status, count(*) n')->groupBy('consent_status')->pluck('n', 'consent_status');
        $sources = DB::table('consent_logs')->where('user_id', $owner->id)->where('action', 'granted')->selectRaw('source, count(*) n')->groupBy('source')->pluck('n', 'source');
        $logs = DB::table('consent_logs')->join('contacts', 'contacts.id', '=', 'consent_logs.contact_id')->where('consent_logs.user_id', $owner->id)
            ->orderByDesc('consent_logs.id')->limit(50)->get(['consent_logs.*', 'contacts.name', 'contacts.contact']);

        return view('consent.index', [
            'required' => (bool) $owner->consent_required,
            'granted' => (int) ($status['granted'] ?? 0), 'revoked' => (int) ($status['revoked'] ?? 0), 'unknown' => (int) ($status['unknown'] ?? 0),
            'sources' => $sources, 'logs' => $logs,
            'optoutMode' => in_array($owner->optout_mode, \App\Services\Consent\OptOut::MODES, true) ? $owner->optout_mode : 'off', 'optoutText' => (string) $owner->optout_text,
            'optoutDefaults' => collect(['keyword', 'link', 'both'])->mapWithKeys(fn ($m) => [$m => \App\Services\Consent\OptOut::defaultText($m)])->all(), 'optoutWord' => \App\Services\Consent\OptOut::word(),
        ]);
    }

    public function settings(Request $request)
    {
        $request->user()->forceFill(['consent_required' => $request->boolean('consent_required')])->save();

        return back()->with('success', __('Saved.'));
    }

    /** What every campaign message says about stopping (off / reply-word / link / both) and an optional own sentence. */
    public function optout(Request $request)
    {
        $data = $request->validate(['optout_mode' => 'required|in:' . implode(',', \App\Services\Consent\OptOut::MODES), 'optout_text' => 'nullable|string|max:400']);
        $request->user()->forceFill(['optout_mode' => $data['optout_mode'], 'optout_text' => trim((string) ($data['optout_text'] ?? '')) ?: null])->save();

        return back()->with('success', __('Saved.'));
    }

    /** Record consent / revoke it by hand on a contact. */
    public function contact(Request $request, int $contact)
    {
        $c = Contact::where('user_id', $request->user()->id)->findOrFail($contact);
        $data = $request->validate(['action' => 'required|in:granted,revoked', 'evidence' => 'nullable|string|max:500']);
        $actor = TeamContext::actor($request);
        $svc = app(Consent::class);
        $note = $data['evidence'] ?? null;
        $data['action'] === 'granted'
            ? $svc->grant($c, 'manual', $note ?: __('Recorded by :name', ['name' => $actor->name]), $request->ip(), $actor->id)
            : $svc->revoke($c, 'manual', $note ?: __('Recorded by :name', ['name' => $actor->name]), $request->ip(), $actor->id);

        return back()->with('success', __('Saved.'));
    }

    /** CSV with every record: the proof to show on request. */
    public function export(Request $request)
    {
        $owner = $request->user();
        $rows = DB::table('consent_logs')->join('contacts', 'contacts.id', '=', 'consent_logs.contact_id')->where('consent_logs.user_id', $owner->id)
            ->orderBy('consent_logs.id')->select(['consent_logs.created_at', 'contacts.name', 'contacts.contact', 'contacts.email', 'consent_logs.action', 'consent_logs.source', 'consent_logs.evidence', 'consent_logs.ip']);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'name', 'contact', 'email', 'action', 'source', 'evidence', 'ip']);
            foreach ($rows->cursor() as $r) {
                // a leading = + - @ would be run as a formula by spreadsheets
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, (array) $r));
            }
            fclose($out);
        }, 'consent-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
