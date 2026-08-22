<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">
                Administrators / <span lang="ar" dir="rtl">المسؤولون</span>
            </h1>
            @can('createAdministrator', \App\Models\User::class)
                <a href="{{ route('admin.administrators.create') }}" class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Create Administrator / <span class="ms-1" lang="ar" dir="rtl">إنشاء مسؤول</span>
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <nav class="flex flex-wrap gap-4 text-sm font-medium" aria-label="Administration links">
                <a href="{{ route('admin.dashboard') }}" class="text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Administration Dashboard / <span lang="ar" dir="rtl">لوحة الإدارة</span>
                </a>
                <a href="{{ route('admin.users.index') }}" class="text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Users / <span lang="ar" dir="rtl">المستخدمون</span>
                </a>
            </nav>

            <section class="overflow-hidden rounded-lg bg-white shadow-sm" aria-labelledby="administrator-list-heading">
                <h2 id="administrator-list-heading" class="sr-only">Administrator accounts / حسابات المسؤولين</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Name / <span lang="ar" dir="rtl">الاسم</span></th>
                                <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Email / <span lang="ar" dir="rtl">البريد الإلكتروني</span></th>
                                <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Canonical role / <span lang="ar" dir="rtl">الدور المعتمد</span></th>
                                <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Account status / <span lang="ar" dir="rtl">حالة الحساب</span></th>
                                <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Password change / <span lang="ar" dir="rtl">تغيير كلمة المرور</span></th>
                                <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Registration date / <span lang="ar" dir="rtl">تاريخ التسجيل</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($administrators as $administrator)
                                <tr>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">{{ $administrator->name }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ $administrator->email }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-700">{{ $administrator->role->value }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                        @if ($administrator->is_active)
                                            <span class="inline-flex rounded-full bg-green-100 px-2 py-1 font-medium text-green-800">Active / <span class="ms-1" lang="ar" dir="rtl">مفعّل</span></span>
                                        @else
                                            <span class="inline-flex rounded-full bg-red-100 px-2 py-1 font-medium text-red-800">Disabled / <span class="ms-1" lang="ar" dir="rtl">معطّل</span></span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                        @if ($administrator->must_change_password)
                                            Pending / <span lang="ar" dir="rtl">مطلوب</span>
                                        @else
                                            Completed / <span lang="ar" dir="rtl">مكتمل</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                        <time datetime="{{ $administrator->created_at->toAtomString() }}">{{ $administrator->created_at->format('Y-m-d') }}</time>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-6 py-4">
                    {{ $administrators->links() }}
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
