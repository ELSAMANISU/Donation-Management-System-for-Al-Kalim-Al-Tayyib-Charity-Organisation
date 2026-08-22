<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the administration dashboard.
     */
    public function __invoke(): View
    {
        Gate::authorize('viewAny', User::class);

        $counts = User::query()
            ->selectRaw('COUNT(*) as total_users')
            ->selectRaw('SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END) as active_users', [true])
            ->selectRaw('SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END) as disabled_users', [false])
            ->selectRaw(
                'SUM(CASE WHEN role IN (?, ?) THEN 1 ELSE 0 END) as administrator_accounts',
                [UserRole::Admin->value, UserRole::SuperAdmin->value],
            )
            ->firstOrFail();

        return view('admin.dashboard', [
            'counts' => [
                'total_users' => (int) $counts->total_users,
                'active_users' => (int) $counts->active_users,
                'disabled_users' => (int) $counts->disabled_users,
                'administrator_accounts' => (int) $counts->administrator_accounts,
            ],
        ]);
    }
}
