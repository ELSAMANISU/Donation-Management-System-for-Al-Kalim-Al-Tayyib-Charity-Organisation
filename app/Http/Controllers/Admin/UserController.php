<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DisableUserRequest;
use App\Http\Requests\Admin\ReactivateUserRequest;
use App\Http\Requests\Admin\UserIndexRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserAccountStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserAccountStateService $accountStateService) {}

    public function index(UserIndexRequest $request): View
    {
        Gate::authorize('viewAny', User::class);

        $search = $request->validated('search');

        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
                $pattern = "%{$escapedSearch}%";

                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->appends(['search' => $search]);

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search ?? '',
        ]);
    }

    public function disable(DisableUserRequest $request, User $user): RedirectResponse
    {
        $this->accountStateService->disable(
            actor: $request->user(),
            target: $user,
            reason: $request->validated('disabled_reason'),
            request: $request,
        );

        return redirect()->route('admin.users.index')->with(
            'success',
            'Account disabled successfully. / تم تعطيل الحساب بنجاح.',
        );
    }

    public function show(User $user): View
    {
        Gate::authorize('view', $user);

        $user = User::query()
            ->select([
                'id',
                'name',
                'email',
                'role',
                'is_active',
                'created_at',
                'disabled_at',
                'disabled_reason',
                'disabled_by',
            ])
            ->with('disabledBy:id,name')
            ->findOrFail($user->getKey());

        $activity = AuditLog::query()
            ->select([
                'id',
                'actor_name',
                'actor_role',
                'action',
                'old_values',
                'new_values',
                'created_at',
            ])
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20, pageName: 'activity_page');

        return view('admin.users.show', [
            'user' => $user,
            'activity' => $activity,
        ]);
    }

    public function reactivate(ReactivateUserRequest $request, User $user): RedirectResponse
    {
        $this->accountStateService->reactivate(
            actor: $request->user(),
            target: $user,
            request: $request,
        );

        return redirect()->route('admin.users.index')->with(
            'success',
            'Account reactivated successfully. / تمت إعادة تفعيل الحساب بنجاح.',
        );
    }
}
