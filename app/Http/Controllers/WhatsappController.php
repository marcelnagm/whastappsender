<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\User;
use App\Status;
use App\Models\WhatsappMessage;
use Artisan;
use Carbon\Carbon;
use DB;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\WhastappService as WhatsappService;

use Image;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Spatie\Geocoder\Geocoder;

class WhatsappController extends Controller
{



    private $parameters = [''];


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id)
    {
        
        $ins = Instance::findOrFail($id);
        
        //    dd($result);
        if (WhatsappService::isConnected($ins->instance_name)) {
            // Evolution is the source of truth: record it (the webhook may not reach this server, e.g. local dev)
            app(\App\Services\Instances\InstanceHealth::class)->report($ins, true);

            return redirect()->route('instances.index')
            ->with('success', 'WhatsApp connected successfully.');
        } else {
            
            return view('whatsapp.index_no', [
                'res' => WhatsappService::qr($ins->instance_name),
                'instance' => $ins->instance_name,
                'instanceId' => $ins->id,
            ]);
        }
    }

    /** Polled by the QR page: after the scan, stores "connected" and tells the page to leave. */
    public function status($id)
    {
        $ins = Instance::findOrFail($id);
        abort_unless($ins->isMine(), 403);

        $connected = app(\App\Services\Instances\EvolutionStatus::class)->state($ins->instance_name) === 'open';
        if ($connected) {
            app(\App\Services\Instances\InstanceHealth::class)->report($ins, true);
        }

        return response()->json(['connected' => $connected]);
    }

    public function qr($id)
    {

        return WhatsappService::qr($id);
    }

}
