<?php

namespace App\Http\Controllers;

use App\Actions\CompleteReturnInspection;
use App\Actions\GetReturnInspectionOperations;
use App\Actions\ReceiveReturnInspection;
use App\Http\Requests\CompleteReturnInspectionRequest;
use App\Http\Requests\ReceiveReturnInspectionRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReturnInspectionController extends Controller
{
    public function show(Request $request, GetReturnInspectionOperations $operations, string $orderCode): View
    {
        return view('managed-orders.return-inspections', [
            'order' => $operations->handle($orderCode, $request->user()),
            'routePrefix' => $request->routeIs('admin.*') ? 'admin' : 'employee',
        ]);
    }

    public function receive(ReceiveReturnInspectionRequest $request, ReceiveReturnInspection $receive, string $orderCode, int $orderItem): RedirectResponse
    {
        try {
            $receive->handle($orderCode, $orderItem, $request->user(), $request->validated('event_key'), $request->validated('note'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors(), 'receive-'.$orderItem)->withInput($request->safe()->only(['event_key', 'note']));
        }

        return back()->with('status', 'Đã tiếp nhận Order Item để kiểm tra.');
    }

    public function complete(CompleteReturnInspectionRequest $request, CompleteReturnInspection $complete, string $orderCode, int $orderItem): RedirectResponse
    {
        try {
            $complete->handle($orderCode, $orderItem, $request->user(), $request->validated('event_key'),
                $request->integer('sellable_quantity'), $request->integer('damaged_quantity'), $request->validated('note'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors(), 'complete-'.$orderItem)
                ->withInput($request->safe()->only(['event_key', 'sellable_quantity', 'damaged_quantity', 'note']));
        }

        return back()->with('status', 'Đã hoàn tất phân loại Order Item.');
    }
}
