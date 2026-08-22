<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">
            Required password change / <span lang="ar" dir="rtl">تغيير كلمة المرور مطلوب</span>
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <section class="overflow-hidden rounded-lg bg-white shadow-sm" aria-labelledby="password-change-heading">
                <div class="p-6 sm:p-8">
                    <h2 id="password-change-heading" class="text-lg font-semibold text-gray-900">
                        Create your private password / <span lang="ar" dir="rtl">أنشئ كلمة مرورك الخاصة</span>
                    </h2>
                    <p class="mt-2 text-sm text-gray-600">
                        A temporary password is in use. You must choose a new private password before continuing.
                        <span class="mt-1 block" lang="ar" dir="rtl">أنت تستخدم كلمة مرور مؤقتة. يجب اختيار كلمة مرور خاصة جديدة قبل المتابعة.</span>
                    </p>

                    <form method="POST" action="{{ route('password.change.required.update') }}" class="mt-6 space-y-6">
                        @csrf
                        @method('PATCH')

                        <div>
                            <x-input-label for="current_password" value="Current password / كلمة المرور الحالية" />
                            <x-text-input id="current_password" name="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" required autofocus />
                            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="password" value="New password / كلمة المرور الجديدة" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" required />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="password_confirmation" value="Confirm new password / تأكيد كلمة المرور الجديدة" />
                            <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" required />
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </div>

                        <div class="flex flex-wrap items-center gap-4">
                            <x-primary-button>
                                Change password / تغيير كلمة المرور
                            </x-primary-button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('logout') }}" class="mt-6 border-t border-gray-200 pt-6">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-gray-700 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Log out / <span lang="ar" dir="rtl">تسجيل الخروج</span>
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
