<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">
                Categories / <span lang="ar" dir="rtl">الفئات</span>
            </h1>
            @can('create', \App\Models\Category::class)
                <a href="{{ route('admin.categories.create') }}" class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Create Category / <span class="ms-1" lang="ar" dir="rtl">إنشاء فئة</span>
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status') === 'category-created')
                <div class="rounded-md bg-green-50 p-4 text-sm font-medium text-green-800" role="status">
                    Category created successfully. / <span lang="ar" dir="rtl">تم إنشاء الفئة بنجاح.</span>
                </div>
            @endif
            @if (session('status') === 'category-updated')
                <div class="rounded-md bg-green-50 p-4 text-sm font-medium text-green-800" role="status">
                    Category updated successfully. / <span lang="ar" dir="rtl">تم تحديث الفئة بنجاح.</span>
                </div>
            @endif

            <section class="overflow-hidden rounded-lg bg-white shadow-sm" aria-labelledby="category-list-heading">
                <h2 id="category-list-heading" class="sr-only">Category list / قائمة الفئات</h2>

                @if ($categories->isEmpty())
                    <div class="px-6 py-12 text-center text-sm text-gray-600" role="status">
                        No categories have been created. / <span lang="ar" dir="rtl">لم يتم إنشاء أي فئات.</span>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Image / <span lang="ar" dir="rtl">الصورة</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Arabic name / <span lang="ar" dir="rtl">الاسم بالعربية</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">English name / <span lang="ar" dir="rtl">الاسم بالإنجليزية</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Canonical slug / <span lang="ar" dir="rtl">المعرّف المعتمد</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Status / <span lang="ar" dir="rtl">الحالة</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Display order / <span lang="ar" dir="rtl">ترتيب العرض</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Created / <span lang="ar" dir="rtl">تاريخ الإنشاء</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Actions / <span lang="ar" dir="rtl">الإجراءات</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @foreach ($categories as $category)
                                    <tr>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                            @if ($category->publicImageUrl())
                                                <img src="{{ $category->publicImageUrl() }}" alt="{{ $category->name_en }} category image" class="h-12 w-16 rounded object-cover">
                                            @else
                                                <span class="text-gray-500">No image / <span lang="ar" dir="rtl">لا توجد صورة</span></span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900" lang="ar" dir="rtl">{{ $category->name_ar }}</td>
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $category->name_en }}</td>
                                        <td class="px-6 py-4 font-mono text-sm text-gray-700">{{ $category->slug }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            @if ($category->is_active)
                                                <span class="inline-flex rounded-full bg-green-100 px-2 py-1 font-medium text-green-800">Active / <span class="ms-1" lang="ar" dir="rtl">مفعّلة</span></span>
                                            @else
                                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-1 font-medium text-gray-800">Inactive / <span class="ms-1" lang="ar" dir="rtl">غير مفعّلة</span></span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ $category->display_order }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                            <time datetime="{{ $category->created_at->toAtomString() }}">{{ $category->created_at->format('Y-m-d') }}</time>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            @can('update', $category)
                                                <a href="{{ route('admin.categories.edit', $category) }}" class="font-medium text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                                    Edit / <span lang="ar" dir="rtl">تعديل</span>
                                                </a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-gray-200 px-6 py-4">
                        {{ $categories->links() }}
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
