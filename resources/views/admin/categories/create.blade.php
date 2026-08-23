<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">
            Create Category / <span lang="ar" dir="rtl">إنشاء فئة</span>
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <section class="rounded-lg bg-white p-6 shadow-sm sm:p-8" aria-labelledby="create-category-heading">
                <h2 id="create-category-heading" class="text-lg font-semibold text-gray-900">
                    Category details / <span lang="ar" dir="rtl">بيانات الفئة</span>
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    New categories start active at display order zero.
                    <span class="mt-1 block" lang="ar" dir="rtl">تبدأ الفئات الجديدة مفعّلة وبترتيب عرض صفر.</span>
                </p>

                <form method="POST" action="{{ route('admin.categories.store') }}" class="mt-6 space-y-6">
                    @csrf

                    <div>
                        <x-input-label for="name_ar" value="Arabic name / الاسم بالعربية" />
                        <x-text-input id="name_ar" name="name_ar" type="text" class="mt-1 block w-full" :value="old('name_ar')" maxlength="255" dir="rtl" required autofocus />
                        <x-input-error :messages="$errors->get('name_ar')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="name_en" value="English name / الاسم بالإنجليزية" />
                        <x-text-input id="name_en" name="name_en" type="text" class="mt-1 block w-full" :value="old('name_en')" maxlength="255" required />
                        <x-input-error :messages="$errors->get('name_en')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="slug" value="Canonical slug / المعرّف المعتمد" />
                        <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full" :value="old('slug')" maxlength="160" aria-describedby="slug-help" required />
                        <p id="slug-help" class="mt-1 text-sm text-gray-600">Lowercase letters, numbers, and hyphens. It will be normalized automatically. / <span lang="ar" dir="rtl">أحرف إنجليزية صغيرة وأرقام وشرطات، وسيتم توحيده تلقائياً.</span></p>
                        <x-input-error :messages="$errors->get('slug')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description_ar" value="Arabic description / الوصف بالعربية" />
                        <textarea id="description_ar" name="description_ar" rows="5" maxlength="5000" dir="rtl" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description_ar') }}</textarea>
                        <x-input-error :messages="$errors->get('description_ar')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description_en" value="English description / الوصف بالإنجليزية" />
                        <textarea id="description_en" name="description_en" rows="5" maxlength="5000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description_en') }}</textarea>
                        <x-input-error :messages="$errors->get('description_en')" class="mt-2" />
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <x-primary-button>Create Category / إنشاء فئة</x-primary-button>
                        <a href="{{ route('admin.categories.index') }}" class="text-sm font-medium text-gray-700 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Cancel / <span lang="ar" dir="rtl">إلغاء</span>
                        </a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
