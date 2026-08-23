<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryCreationService;
use App\Services\CategoryUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryCreationService $creationService,
        private readonly CategoryUpdateService $updateService,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Category::class);

        $categories = Category::query()
            ->select(['id', 'name_ar', 'name_en', 'slug', 'is_active', 'display_order', 'created_at'])
            ->inDisplayOrder()
            ->paginate(15);

        return view('admin.categories.index', ['categories' => $categories]);
    }

    public function create(): View
    {
        Gate::authorize('create', Category::class);

        return view('admin.categories.create');
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Gate::authorize('create', Category::class);

        $this->creationService->create(
            actor: $request->user(),
            attributes: $request->safe()->only([
                'name_ar', 'name_en', 'slug', 'description_ar', 'description_en',
            ]),
            request: $request,
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('status', 'category-created');
    }

    public function edit(Category $category): View
    {
        Gate::authorize('update', $category);

        return view('admin.categories.edit', ['category' => $category]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->updateService->update(
            actor: $request->user(),
            category: $category,
            attributes: $request->safe()->only([
                'name_ar', 'name_en', 'slug', 'description_ar', 'description_en',
                'display_order', 'is_active',
            ]),
            request: $request,
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('status', 'category-updated');
    }
}
