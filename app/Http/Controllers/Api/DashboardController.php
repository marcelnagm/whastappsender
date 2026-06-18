<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
use App\Models\Instance;
use App\Models\WhatsappJob;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return $this->success([
            'contacts_count' => Contact::where('user_id', $userId)->count(),
            'delivery_rate' => WhatsappJob::getDeliveryRate($userId),
            'error_rate' => WhatsappJob::getErrorRate($userId),
            'connected_instances' => Instance::where('user_id', $userId)->where('status', 'connected')->count(),
        ]);
    }
}
