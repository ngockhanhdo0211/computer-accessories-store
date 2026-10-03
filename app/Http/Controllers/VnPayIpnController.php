<?php

namespace App\Http\Controllers;

use App\Actions\ProcessVnPayIpn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VnPayIpnController extends Controller
{
    public function __invoke(Request $request, ProcessVnPayIpn $action): JsonResponse
    {
        $rawQuery = $request->server('QUERY_STRING');
        $response = $action->handle(is_string($rawQuery) ? $rawQuery : '');

        return response()->json($response->toArray(), 200, [], JSON_UNESCAPED_UNICODE);
    }
}
