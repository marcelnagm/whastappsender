<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/** Who did what in the account (owner and team members). */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $uid = $request->user()->id;
        $actor = $request->query('actor');
        $q = trim((string) $request->query('q', ''));
        $logs = AuditLog::where('user_id', $uid)
            ->when($actor, fn ($w) => $w->where('actor_id', (int) $actor))
            ->when($q !== '', fn ($w) => $w->where('action', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'))
            ->orderByDesc('id')->paginate(50)->withQueryString();
        $actors = AuditLog::where('user_id', $uid)->select('actor_id', 'actor_name')->distinct()->orderBy('actor_name')->get();

        return view('audit.index', compact('logs', 'actors', 'actor', 'q'));
    }
}
