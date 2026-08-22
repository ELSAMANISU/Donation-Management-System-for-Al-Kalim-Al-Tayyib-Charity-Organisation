<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">
            Create Administrator / <span lang="ar" dir="rtl">إنشاء مسؤول</span>
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <section class="rounded-lg bg-white p-6 shadow-sm sm:p-8" aria-labelledby="create-administrator-heading">
                <h2 id="create-administrator-heading" class="text-lg font-semibold text-gray-900">
                    Administrator account / <span lang="ar" dir="rtl">حساب المسؤول</span>
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    The system will generate a secure temporary password. It will be shown once after creation.
                    <span class="mt-1 block" lang="ar" dir="rtl">سينشئ النظام كلمة مرور مؤقتة وآمنة، وستظهر مرة واحدة بعد إنشاء الحساب.</span>
                </p>

                <form method="POST" action="{{ route('admin.administrators.store') }}" class="mt-6 space-y-6">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Name / الاسم" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" maxlength="255" autocomplete="name" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="email" value="Email / البريد الإلكتروني" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" maxlength="255" autocomplete="email" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <x-primary-button>
                            Create Administrator / إنشاء مسؤول
                        </x-primary-button>
                        <a href="{{ route('admin.administrators.index') }}" class="text-sm font-medium text-gray-700 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Cancel / <span lang="ar" dir="rtl">إلغاء</span>
                        </a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
