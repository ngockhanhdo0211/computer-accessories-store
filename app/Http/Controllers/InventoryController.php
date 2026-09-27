<?php

namespace App\Http\Controllers;

use App\Actions\ApproveStockAdjustment;
use App\Actions\DirectStockAdjustment;
use App\Actions\ImportStock;
use App\Actions\RecordDamagedStock;
use App\Actions\RejectStockAdjustment;
use App\Actions\RequestStockAdjustment;
use App\Http\Requests\InventoryIndexRequest;
use App\Http\Requests\InventoryMovementRequest;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(InventoryIndexRequest $request): View|RedirectResponse
    {
        $filters = $request->validated();
        $query = Product::query()->with(['primaryImage:id,product_id,path,alt_text'])->search($filters['search'] ?? null);
        match ($filters['stock'] ?? null) {
            'out' => $query->where('sellable_quantity', 0),
            'low' => $query->where('sellable_quantity', '>', 0)->whereColumn('sellable_quantity', '<=', 'low_stock_threshold'),
            'in_stock' => $query->whereColumn('sellable_quantity', '>', 'low_stock_threshold'),
            default => null,
        };
        match ($filters['sort']) {
            'stock_asc' => $query->orderBy('sellable_quantity')->orderBy('id'),
            'stock_desc' => $query->orderByDesc('sellable_quantity')->orderBy('id'),
            default => $query->orderBy('name')->orderBy('id'),
        };
        $products = $query->paginate(20)->withQueryString();
        if ($products->isEmpty() && $products->total() > 0) {
            return redirect()->route('inventory.index', [...$request->safe()->except('page'), 'page' => $products->lastPage()]);
        }

        return view('inventory.index', compact('products', 'filters'));
    }

    public function history(Product $product): View
    {
        $product->load('primaryImage:id,product_id,path,alt_text');
        $transactions = InventoryTransaction::query()->where('product_id', $product->id)
            ->with(['actor:id,name'])->latest('created_at')->latest('id')->paginate(20);

        return view('inventory.history', compact('product', 'transactions'));
    }

    public function importForm(Product $product): View
    {
        return view('inventory.movement', ['product' => $product, 'mode' => 'import']);
    }

    public function damagedForm(Product $product): View
    {
        return view('inventory.movement', ['product' => $product, 'mode' => 'damaged']);
    }

    public function import(InventoryMovementRequest $request, Product $product, ImportStock $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($product, $request->user(), (int) $data['quantity'], $data['reason'], $data['request_key']);

        return redirect()->route('inventory.history', $product)->with('status', 'Đã nhập kho và ghi sổ giao dịch.');
    }

    public function damaged(InventoryMovementRequest $request, Product $product, RecordDamagedStock $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($product, $request->user(), (int) $data['quantity'], $data['reason'], $data['request_key']);

        return redirect()->route('inventory.history', $product)->with('status', 'Đã chuyển hàng sang nhóm hỏng và ghi sổ giao dịch.');
    }

    public function adjustments(Request $request): View|RedirectResponse
    {
        $query = InventoryAdjustmentRequest::query()->with(['product:id,name,slug,sku', 'requester:id,name', 'reviewer:id,name'])
            ->when(! $request->user()->isAdmin(), fn (Builder $query) => $query->where('requested_by', $request->user()->id))
            ->latest('created_at')->latest('id');
        $adjustments = $query->paginate(20);
        if ($adjustments->isEmpty() && $adjustments->total() > 0) {
            return redirect()->route('inventory.adjustments.index', ['page' => $adjustments->lastPage()]);
        }

        return view('inventory.adjustments', compact('adjustments'));
    }

    public function adjustmentForm(Product $product): View
    {
        return view('inventory.adjustment-form', ['product' => $product, 'direct' => false]);
    }

    public function directAdjustmentForm(Product $product): View
    {
        return view('inventory.adjustment-form', ['product' => $product, 'direct' => true]);
    }

    public function requestAdjustment(StockAdjustmentRequest $request, Product $product, RequestStockAdjustment $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($product, $request->user(), (int) $data['sellable_delta'], (int) $data['damaged_delta'], $data['reason'], $data['request_key']);

        return redirect()->route('inventory.adjustments.index')->with('status', 'Đã tạo đề nghị điều chỉnh kho. Tồn kho chưa thay đổi.');
    }

    public function directAdjustment(StockAdjustmentRequest $request, Product $product, DirectStockAdjustment $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($product, $request->user(), (int) $data['sellable_delta'], (int) $data['damaged_delta'], $data['reason'], $data['request_key']);

        return redirect()->route('inventory.history', $product)->with('status', 'Đã điều chỉnh kho trực tiếp, ghi ledger và audit log.');
    }

    public function approve(Request $request, InventoryAdjustmentRequest $adjustment, ApproveStockAdjustment $action): RedirectResponse
    {
        $action->handle($adjustment, $request->user());

        return redirect()->route('inventory.adjustments.index')->with('status', 'Đã duyệt đề nghị và cập nhật tồn kho.');
    }

    public function reject(Request $request, InventoryAdjustmentRequest $adjustment, RejectStockAdjustment $action): RedirectResponse
    {
        $action->handle($adjustment, $request->user());

        return redirect()->route('inventory.adjustments.index')->with('status', 'Đã từ chối đề nghị. Tồn kho không thay đổi.');
    }
}
