<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">
                User details / <span lang="ar" dir="rtl">تفاصيل المستخدم</span>
            </h1>
            <nav class="flex flex-wrap gap-4 text-sm font-medium" aria-label="Administration links">
                <a href="{{ route('admin.users.index') }}" class="text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Users / <span lang="ar" dir="rtl">المستخدمون</span>
                </a>
                <a href="{{ route('admin.dashboard') }}" class="text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Administration Dashboard / <span lang="ar" dir="rtl">لوحة الإدارة</span>
                </a>
                <a href="{{ url('/') }}" class="text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Public homepage / <span lang="ar" dir="rtl">الصفحة الرئيسية</span>
                </a>
            </nav>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
            <section class="overflow-hidden rounded-lg bg-white shadow-sm" aria-labelledby="account-information">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 id="account-information" class="text-lg font-semibold text-gray-900">
                        Account information / <span lang="ar" dir="rtl">معلومات الحساب</span>
                    </h2>
                </div>

                <dl class="grid gap-x-8 gap-y-6 p-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">ID / <span lang="ar" dir="rtl">المعرّف</span></dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $user->id }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Name / <span lang="ar" dir="rtl">الاسم</span></dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $user->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Email / <span lang="ar" dir="rtl">البريد الإلكتروني</span></dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $user->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Role / <span lang="ar" dir="rtl">الدور</span></dt>
                        <dd class="mt-1 font-mono text-sm text-gray-900">{{ $user->role->value }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Account status / <span lang="ar" dir="rtl">حالة الحساب</span></dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if ($user->is_active)
                                Active / <span lang="ar" dir="rtl">مفعّل</span>
                            @else
                                Disabled / <span lang="ar" dir="rtl">معطّل</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Registration date / <span lang="ar" dir="rtl">تاريخ التسجيل</span></dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            <time datetime="{{ $user->created_at->toAtomString() }}">{{ $user->created_at->format('Y-m-d H:i') }}</time>
                        </dd>
                    </div>

                    @unless ($user->is_active)
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Disabled at / <span lang="ar" dir="rtl">تاريخ التعطيل</span></dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                @if ($user->disabled_at)
                                    <time datetime="{{ $user->disabled_at->toAtomString() }}">{{ $user->disabled_at->format('Y-m-d H:i') }}</time>
                                @else
                                    Unavailable / <span lang="ar" dir="rtl">غير متاح</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Disabled by / <span lang="ar" dir="rtl">تم التعطيل بواسطة</span></dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ $user->disabledBy?->name ?? 'Unavailable' }}
                                @unless ($user->disabledBy)
                                    / <span lang="ar" dir="rtl">غير متاح</span>
                                @endunless
                            </dd>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <dt class="text-sm font-medium text-gray-500">Disable reason / <span lang="ar" dir="rtl">سبب التعطيل</span></dt>
                            <dd class="mt-1 whitespace-pre-wrap text-sm text-gray-900">{{ $user->disabled_reason ?? 'Unavailable' }}</dd>
                        </div>
                    @endunless
                </dl>
            </section>

            <section class="overflow-hidden rounded-lg bg-white shadow-sm" aria-labelledby="activity-history">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 id="activity-history" class="text-lg font-semibold text-gray-900">
                        Activity history / <span lang="ar" dir="rtl">سجل النشاط</span>
                    </h2>
                </div>

                @if ($activity->isEmpty())
                    <div class="p-10 text-center" role="status">
                        <p class="font-medium text-gray-700">
                            No activity recorded / <span lang="ar" dir="rtl">لا يوجد نشاط مسجل</span>
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Action / <span lang="ar" dir="rtl">الإجراء</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Performed by / <span lang="ar" dir="rtl">نُفّذ بواسطة</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Actor role / <span lang="ar" dir="rtl">دور المنفّذ</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Date and time / <span lang="ar" dir="rtl">التاريخ والوقت</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">State transition / <span lang="ar" dir="rtl">تغيير الحالة</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @foreach ($activity as $entry)
                                    <tr>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            @switch($entry->action)
                                                @case('user.disabled')
                                                    Account disabled / <span lang="ar" dir="rtl">تم تعطيل الحساب</span>
                                                    @break
                                                @case('user.reactivated')
                                                    Account reactivated / <span lang="ar" dir="rtl">تمت إعادة تفعيل الحساب</span>
                                                    @break
                                                @default
                                                    {{ $entry->action }}
                                            @endswitch
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                            {{ $entry->actor_name ?? 'System' }}
                                            @unless ($entry->actor_name)
                                                / <span lang="ar" dir="rtl">النظام</span>
                                            @endunless
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-700">{{ $entry->actor_role ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                            <time datetime="{{ $entry->created_at->toAtomString() }}">{{ $entry->created_at->format('Y-m-d H:i:s') }}</time>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                            @if (data_get($entry->old_values, 'is_active') === true && data_get($entry->new_values, 'is_active') === false)
                                                Active / <span lang="ar" dir="rtl">مفعّل</span> → Disabled / <span lang="ar" dir="rtl">معطّل</span>
                                            @elseif (data_get($entry->old_values, 'is_active') === false && data_get($entry->new_values, 'is_active') === true)
                                                Disabled / <span lang="ar" dir="rtl">معطّل</span> → Active / <span lang="ar" dir="rtl">مفعّل</span>
                                            @else
                                                Account state updated / <span lang="ar" dir="rtl">تم تحديث حالة الحساب</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-gray-200 px-6 py-4">
                        {{ $activity->links() }}
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
