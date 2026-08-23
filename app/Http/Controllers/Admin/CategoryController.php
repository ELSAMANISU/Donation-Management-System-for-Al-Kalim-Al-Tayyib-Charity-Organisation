<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RestoreCategoryRequest;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryImageRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryCreationService;
use App\Services\CategoryImageService;
use App\Services\CategoryLifecycleService;
use App\Services\CategoryUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryCreationService $creationService,
        private readonly CategoryUpdateService $updateService,
        private readonly CategoryImageService $imageService,
        private readonly CategoryLifecycleService $lifecycleService,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Category::class);

        $categories = Category::query()
            ->select(['id', 'name_ar', 'name_en', 'slug', 'image_path', 'is_active', 'display_order', 'created_at'])
            ->inDisplayOrder()
            ->paginate(15);

        return view('admin.categories.index', ['categories' => $categories]);
    }

    public function create(): View
    {
        Gate::authorize('create', Category::class);

        return view('admin.categories.create');
    }

    public function trashed(): View
    {
        Gate::authorize('viewAny', Category::class);

        $categories = Category::onlyTrashed()
            ->select(['id', 'name_ar', 'name_en', 'slug', 'is_active', 'display_order', 'deleted_at'])
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('admin.categories.trashed', ['categories' => $categories]);
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

    public function updateImage(UpdateCategoryImageRequest $request, Category $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->imageService->upload(
            actor: $request->user(),
            category: $category,
            image: $request->file('image'),
            request: $request,
        );

        return redirect()
            ->route('admin.categories.edit', $category)
            ->with('status', 'category-image-updated');
    }

    public function destroyImage(Request $request, Category $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->imageService->remove($request->user(), $category, $request);

        return redirect()
            ->route('admin.categories.edit', $category)
            ->with('status', 'category-image-removed');
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $this->lifecycleService->delete($request->user(), $category, $request);

        return redirect()
            ->route('admin.categories.index')
            ->with('status', 'category-deleted');
    }

    public function restore(RestoreCategoryRequest $request, int $category): RedirectResponse
    {
        $this->lifecycleService->restore($request->user(), $category, $request);

        return redirect()
            ->route('admin.categories.index')
            ->with('status', 'category-restored');
    }
}
