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
            //                          
            return redirect()->route('instances.index')
            ->with('success', 'Whatsapp conectado com sucesso.');
        } else {
            
            return view('whatsapp.index_no', [
                'res' => WhatsappService::qr($ins->instance_name),
                'instance' => $ins->instance_name
            ]);
        }
    }

    public function send($id)
    {
        if (WhatsappService::isConnected(Auth::User()->contact())) {
            WhatsappService::sender(Auth::User()->contact(), $id, 'Teste de ssitema');
        }
        return redirect()->route('whatsapp.index')->withStatus(__('Mengem removida com sucesso'));
    }
}
