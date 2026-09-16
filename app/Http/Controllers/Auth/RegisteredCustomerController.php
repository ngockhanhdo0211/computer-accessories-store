<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CreateCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCustomerRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RegisteredCustomerController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterCustomerRequest $request, CreateCustomer $createCustomer): RedirectResponse
    {
        $createCustomer->handle($request->validated());

        return redirect()->route('home')->with('status', 'Đăng ký tài khoản thành công.');
    }
}
