<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdministratorRequest;
use App\Models\User;
use App\Services\AdministratorCreationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdministratorController extends Controller
{
    public function __construct(private readonly AdministratorCreationService $creationService) {}

    public function index(Request $request): View
    {
        Gate::authorize('manageAdministrators', User::class);

        $administrators = User::query()
            ->select([
                'id',
                'name',
                'email',
                'role',
                'is_active',
                'must_change_password',
                'created_at',
            ])
            ->whereIn('role', [UserRole::Admin, UserRole::SuperAdmin])
            ->orderBy('role')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('admin.administrators.index', [
            'administrators' => $administrators,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('createAdministrator', User::class);

        return view('admin.administrators.create');
    }

    public function store(StoreAdministratorRequest $request): Response
    {
        Gate::authorize('createAdministrator', User::class);

        $result = $this->creationService->create(
            actor: $request->user(),
            name: $request->validated('name'),
            email: $request->validated('email'),
            request: $request,
        );

        return response()
            ->view('admin.administrators.created', [
                'administrator' => $result->user,
                'temporaryPassword' => $result->temporaryPassword,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
