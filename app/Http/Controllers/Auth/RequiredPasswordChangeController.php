<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequiredPasswordChangeRequest;
use App\Models\User;
use App\Services\RequiredPasswordChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RequiredPasswordChangeController extends Controller
{
    public function __construct(private readonly RequiredPasswordChangeService $passwordChangeService) {}

    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return $this->redirectForRole($request->user());
        }

        return view('auth.change-required-password');
    }

    public function update(RequiredPasswordChangeRequest $request): RedirectResponse
    {
        $user = $this->passwordChangeService->change(
            authenticatedUser: $request->user(),
            currentPassword: $request->validated('current_password'),
            newPassword: $request->validated('password'),
            request: $request,
        );

        return $this->redirectForRole($user);
    }

    private function redirectForRole(User $user): RedirectResponse
    {
        if ($user->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin])) {
            return redirect()->route('admin.dashboard');
        }

        return redirect('/');
    }
}
