<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\WhatsappJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function index() 
    {
        
        if(Auth::user()){
        $contact = Contact::where('user_id',Auth::user()->id)->count();
        $read =  \App\Models\WhatsappJob::getDeliveryRate('user_id',Auth::user()->id);
        $error =  \App\Models\WhatsappJob::getErrorRate('user_id',Auth::user()->id);
        $instances =  \App\Models\Instance::where('user_id',Auth::user()->id)->where('status','connected')->count();
        return view('home.index',compact('contact','read','error','instances'))    ;
    }

        return view('home.guest');
    }
}
