<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">
            Edit Category / <span lang="ar" dir="rtl">تعديل الفئة</span>
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status') === 'category-image-updated')
                <div class="rounded-md bg-green-50 p-4 text-sm font-medium text-green-800" role="status">
                    Category image updated successfully. / <span lang="ar" dir="rtl">تم تحديث صورة الفئة بنجاح.</span>
                </div>
            @endif
            @if (session('status') === 'category-image-removed')
                <div class="rounded-md bg-green-50 p-4 text-sm font-medium text-green-800" role="status">
                    Category image removed successfully. / <span lang="ar" dir="rtl">تمت إزالة صورة الفئة بنجاح.</span>
                </div>
            @endif

            @can('update', $category)
                <section class="rounded-lg bg-white p-6 shadow-sm sm:p-8" aria-labelledby="category-image-heading">
                    <h2 id="category-image-heading" class="text-lg font-semibold text-gray-900">
                        Public category image / <span lang="ar" dir="rtl">صورة الفئة العامة</span>
                    </h2>

                    <div class="mt-4">
                        @if ($category->publicImageUrl())
                            <img src="{{ $category->publicImageUrl() }}" alt="{{ $category->name_en }} category image" class="h-48 w-full rounded-lg object-cover sm:w-80">
                        @else
                            <p class="rounded-md bg-gray-50 p-4 text-sm text-gray-600">No image / <span lang="ar" dir="rtl">لا توجد صورة</span></p>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('admin.categories.image.update', $category) }}" enctype="multipart/form-data" class="mt-6 space-y-4">
                        @csrf
                        @method('PATCH')

                        <div>
                            <x-input-label for="image" value="Upload image / رفع صورة" />
                            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" aria-describedby="image-help" class="mt-1 block w-full text-sm text-gray-700 file:me-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100" required>
                            <p id="image-help" class="mt-2 text-sm text-gray-600">JPEG, PNG, or WebP; maximum 5 MiB and 8000×8000 pixels. / <span lang="ar" dir="rtl">JPEG أو PNG أو WebP؛ بحد أقصى 5 ميبيبايت و8000×8000 بكسل.</span></p>
                            <x-input-error :messages="$errors->get('image')" class="mt-2" />
                        </div>

                        <x-primary-button>
                            {{ $category->image_path ? 'Replace Image / استبدال الصورة' : 'Upload Image / رفع الصورة' }}
                        </x-primary-button>
                    </form>

                    @if ($category->image_path)
                        <form method="POST" action="{{ route('admin.categories.image.destroy', $category) }}" class="mt-4" onsubmit="return confirm('Remove this category image? / إزالة صورة هذه الفئة؟')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                                Remove Image / <span class="ms-1" lang="ar" dir="rtl">إزالة الصورة</span>
                            </button>
                        </form>
                    @endif
                </section>
            @endcan

            <section class="rounded-lg bg-white p-6 shadow-sm sm:p-8" aria-labelledby="edit-category-heading">
                <h2 id="edit-category-heading" class="text-lg font-semibold text-gray-900">
                    Category details / <span lang="ar" dir="rtl">بيانات الفئة</span>
                </h2>

                <form method="POST" action="{{ route('admin.categories.update', $category) }}" class="mt-6 space-y-6">
                    @csrf
                    @method('PATCH')

                    <div>
                        <x-input-label for="name_ar" value="Arabic name / الاسم بالعربية" />
                        <x-text-input id="name_ar" name="name_ar" type="text" class="mt-1 block w-full" :value="old('name_ar', $category->name_ar)" maxlength="255" dir="rtl" required autofocus />
                        <x-input-error :messages="$errors->get('name_ar')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="name_en" value="English name / الاسم بالإنجليزية" />
                        <x-text-input id="name_en" name="name_en" type="text" class="mt-1 block w-full" :value="old('name_en', $category->name_en)" maxlength="255" required />
                        <x-input-error :messages="$errors->get('name_en')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="slug" value="Canonical slug / المعرّف المعتمد" />
                        <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full" :value="old('slug', $category->slug)" maxlength="160" aria-describedby="slug-help" required />
                        <p id="slug-help" class="mt-1 text-sm text-gray-600">Lowercase letters, numbers, and single hyphens; normalized automatically. / <span lang="ar" dir="rtl">أحرف إنجليزية صغيرة وأرقام وشرطات مفردة، ويتم توحيده تلقائياً.</span></p>
                        <x-input-error :messages="$errors->get('slug')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description_ar" value="Arabic description / الوصف بالعربية" />
                        <textarea id="description_ar" name="description_ar" rows="5" maxlength="5000" dir="rtl" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description_ar', $category->description_ar) }}</textarea>
                        <x-input-error :messages="$errors->get('description_ar')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description_en" value="English description / الوصف بالإنجليزية" />
                        <textarea id="description_en" name="description_en" rows="5" maxlength="5000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description_en', $category->description_en) }}</textarea>
                        <x-input-error :messages="$errors->get('description_en')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="display_order" value="Display order / ترتيب العرض" />
                        <x-text-input id="display_order" name="display_order" type="number" class="mt-1 block w-full" :value="old('display_order', $category->display_order)" min="0" max="4294967295" step="1" required />
                        <x-input-error :messages="$errors->get('display_order')" class="mt-2" />
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">Visibility / <span lang="ar" dir="rtl">الظهور</span></legend>
                        <input type="hidden" name="is_active" value="0">
                        <label for="is_active" class="mt-2 inline-flex items-start gap-3">
                            <input id="is_active" name="is_active" type="checkbox" value="1" class="mt-1 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @checked(old('is_active', $category->is_active))>
                            <span class="text-sm text-gray-700">
                                Active / <span lang="ar" dir="rtl">مفعّلة</span>
                                <span class="mt-1 block text-gray-600">Inactive categories will be hidden from future public display. / <span lang="ar" dir="rtl">ستُخفى الفئات غير المفعّلة من العرض العام مستقبلاً.</span></span>
                            </span>
                        </label>
                        <x-input-error :messages="$errors->get('is_active')" class="mt-2" />
                    </fieldset>

                    <div class="flex flex-wrap items-center gap-4">
                        <x-primary-button>Save Category / حفظ الفئة</x-primary-button>
                        <a href="{{ route('admin.categories.index') }}" class="text-sm font-medium text-gray-700 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Cancel / <span lang="ar" dir="rtl">إلغاء</span>
                        </a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
