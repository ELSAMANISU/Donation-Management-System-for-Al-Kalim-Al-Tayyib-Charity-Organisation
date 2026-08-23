<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Models\Category;
use App\Services\CategoryCreationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryCreationService $creationService) {}

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
}
