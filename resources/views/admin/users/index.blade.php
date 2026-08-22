<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">
                Users / <span lang="ar" dir="rtl">المستخدمون</span>
            </h1>
            <div class="flex flex-wrap gap-4 text-sm font-medium">
                <a href="{{ route('admin.dashboard') }}" class="text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Administration Dashboard / <span lang="ar" dir="rtl">لوحة الإدارة</span>
                </a>
                <a href="{{ url('/') }}" class="text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Public homepage / <span lang="ar" dir="rtl">الصفحة الرئيسية</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                <div class="border-b border-gray-200 p-6">
                    <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end" role="search">
                        <div class="grow">
                            <label for="search" class="block text-sm font-medium text-gray-700">
                                Search by name or email / <span lang="ar" dir="rtl">البحث بالاسم أو البريد الإلكتروني</span>
                            </label>
                            <input id="search" name="search" type="search" maxlength="100" value="{{ $search }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('search')
                                <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Search / <span class="ms-1" lang="ar" dir="rtl">بحث</span>
                        </button>
                        @if ($search !== '')
                            <a href="{{ route('admin.users.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                Clear search / <span class="ms-1" lang="ar" dir="rtl">مسح البحث</span>
                            </a>
                        @endif
                    </form>
                </div>

                @if ($users->isEmpty())
                    <div class="p-10 text-center" role="status">
                        <p class="text-base font-medium text-gray-700">
                            No users found / <span lang="ar" dir="rtl">لم يتم العثور على مستخدمين</span>
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Name / <span lang="ar" dir="rtl">الاسم</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Email / <span lang="ar" dir="rtl">البريد الإلكتروني</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Role / <span lang="ar" dir="rtl">الدور</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Account status / <span lang="ar" dir="rtl">حالة الحساب</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Registration date / <span lang="ar" dir="rtl">تاريخ التسجيل</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @foreach ($users as $user)
                                    <tr>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">{{ $user->name }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ $user->email }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-700">{{ $user->role->value }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            @if ($user->is_active)
                                                <span class="inline-flex rounded-full bg-green-100 px-2 py-1 font-medium text-green-800">Active / <span class="ms-1" lang="ar" dir="rtl">مفعّل</span></span>
                                            @else
                                                <span class="inline-flex rounded-full bg-red-100 px-2 py-1 font-medium text-red-800">Disabled / <span class="ms-1" lang="ar" dir="rtl">معطّل</span></span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                            <time datetime="{{ $user->created_at->toAtomString() }}">{{ $user->created_at->format('Y-m-d') }}</time>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-gray-200 px-6 py-4">
                        {{ $users->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
