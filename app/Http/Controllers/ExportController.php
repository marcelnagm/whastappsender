<?php

namespace App\Http\Controllers;

use App\Services\Export\DataExport;
use Illuminate\Http\Request;

/** Backup of the account's data as CSV files. */
class ExportController extends Controller
{
    public function index()
    {
        return view('export.index', ['types' => DataExport::types()]);
    }

    public function download(Request $request, string $type)
    {
        abort_unless(array_key_exists($type, DataExport::types()), 404);
        $uid = $request->user()->id;
        $name = $type . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($type, $uid) {
            $out = fopen('php://output', 'w');
            app(DataExport::class)->write($out, $type, $uid);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
