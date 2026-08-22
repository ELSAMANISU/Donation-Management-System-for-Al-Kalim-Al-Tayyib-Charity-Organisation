<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">
                    Administration Dashboard /
                    <span lang="ar" dir="rtl">لوحة الإدارة</span>
                </h1>
            </div>
            <a href="{{ url('/') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                Public homepage / <span lang="ar" dir="rtl">الصفحة الرئيسية</span>
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <section aria-labelledby="administrator-summary" class="mb-8 rounded-lg bg-white p-6 shadow-sm">
                <h2 id="administrator-summary" class="text-lg font-semibold text-gray-900">
                    Signed-in administrator / <span lang="ar" dir="rtl">المسؤول المسجل</span>
                </h2>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">
                            Name / <span lang="ar" dir="rtl">الاسم</span>
                        </dt>
                        <dd class="mt-1 text-base text-gray-900">{{ Auth::user()->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">
                            Canonical role / <span lang="ar" dir="rtl">الدور المعتمد</span>
                        </dt>
                        <dd class="mt-1 font-mono text-base text-gray-900">{{ Auth::user()->role->value }}</dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="account-statistics">
                <h2 id="account-statistics" class="sr-only">Account statistics / إحصاءات الحسابات</h2>
                <dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        'total_users' => ['Total users', 'إجمالي المستخدمين'],
                        'active_users' => ['Active users', 'المستخدمون المفعّلون'],
                        'disabled_users' => ['Disabled users', 'المستخدمون المعطّلون'],
                        'administrator_accounts' => ['Administrator accounts', 'حسابات المسؤولين'],
                    ] as $key => [$englishLabel, $arabicLabel])
                        <div class="rounded-lg bg-white p-6 shadow-sm">
                            <dt class="text-sm font-medium text-gray-600">
                                {{ $englishLabel }} / <span lang="ar" dir="rtl">{{ $arabicLabel }}</span>
                            </dt>
                            <dd class="mt-2 text-3xl font-semibold text-gray-900">{{ $counts[$key] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>
    </div>
</x-app-layout>
