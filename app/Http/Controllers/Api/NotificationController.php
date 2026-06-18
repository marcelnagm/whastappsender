<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function markRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->success(null, 'Notifications marked as read.');
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        $notifications = $request->user()->notifications()->paginate($perPage);

        return $this->paginated($notifications);
    }
}
