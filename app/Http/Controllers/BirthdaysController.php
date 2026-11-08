<?php

namespace App\Http\Controllers;

use App\Services\Contacts\Birthdays;
use Illuminate\Http\Request;

/** Next birthdays of the account's contacts. */
class BirthdaysController extends Controller
{
    public function index(Request $request, Birthdays $svc)
    {
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;

        return view('contact.birthdays', ['rows' => $svc->upcoming($request->user(), $days), 'days' => $days]);
    }
}
