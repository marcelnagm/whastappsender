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
        return view('home.index',compact('contact','read','error'))    ;
    }

        return view('home.index');
    }
}
