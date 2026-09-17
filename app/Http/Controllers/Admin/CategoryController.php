<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeleteCategory;
use App\Actions\SaveCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::query()->with('parent:id,name,slug')->withCount('children')
            ->orderByRaw('parent_id IS NOT NULL')->orderBy('name')->paginate(25);

        return view('admin.categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('admin.categories.create', ['parents' => $this->parentOptions()]);
    }

    public function store(StoreCategoryRequest $request, SaveCategory $action): RedirectResponse
    {
        $action->handle($request->validated());

        return redirect()->route('admin.categories.index')->with('status', 'Đã tạo danh mục.');
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.edit', ['category' => $category, 'parents' => $this->parentOptions($category)]);
    }

    public function update(UpdateCategoryRequest $request, Category $category, SaveCategory $action): RedirectResponse
    {
        $action->handle($request->validated(), $category);

        return redirect()->route('admin.categories.index')->with('status', 'Đã cập nhật danh mục.');
    }

    public function destroy(Category $category, DeleteCategory $action): RedirectResponse
    {
        $action->handle($category);

        return redirect()->route('admin.categories.index')->with('status', 'Đã xóa danh mục.');
    }

    private function parentOptions(?Category $except = null): Collection
    {
        $categories = Category::query()->orderBy('name')->get(['id', 'name', 'is_visible', 'parent_id']);

        if ($except === null) {
            return $categories;
        }

        $childrenByParent = [];

        foreach ($categories as $candidate) {
            if ($candidate->parent_id !== null) {
                $childrenByParent[$candidate->parent_id][] = $candidate->id;
            }
        }

        $excluded = [$except->id => true];
        $queue = [$except->id];

        for ($index = 0; $index < count($queue); $index++) {
            foreach ($childrenByParent[$queue[$index]] ?? [] as $childId) {
                if (! isset($excluded[$childId])) {
                    $excluded[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }

        return $categories->reject(fn (Category $candidate) => isset($excluded[$candidate->id]))->values();
    }
}
