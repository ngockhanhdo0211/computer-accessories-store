<?php

namespace App\Http\Controllers\Admin;

use App\Actions\GetAdminDashboardStats;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(GetAdminDashboardStats $stats): View
    {
        return view('admin.dashboard', ['stats' => $stats->handle()]);
    }
}
