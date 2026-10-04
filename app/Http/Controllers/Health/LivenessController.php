<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class LivenessController extends Controller
{
    public function __invoke(): Response
    {
        return response('ok', 200, [
            'Cache-Control' => 'no-store, private',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
