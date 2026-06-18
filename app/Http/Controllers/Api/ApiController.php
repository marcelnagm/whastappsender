<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;

class ApiController extends Controller
{
    use AuthorizesOwnership, RespondsWithJson;
}
