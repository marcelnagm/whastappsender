<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\Storage;

/** Serves a file the agent sent to Instagram. Only reachable through the temporary signed link Instagram fetches. */
class InstagramMediaController extends Controller
{
    public function show(ChatMessage $message)
    {
        abort_unless($message->from_me && $message->media_path && Storage::exists($message->media_path), 404);

        return response()->file(Storage::path($message->media_path), [
            'Content-Type' => $message->mimetype ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
