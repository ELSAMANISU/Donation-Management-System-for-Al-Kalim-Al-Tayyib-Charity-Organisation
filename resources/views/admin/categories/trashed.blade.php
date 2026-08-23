<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">
                Deleted Categories / <span lang="ar" dir="rtl">الفئات المحذوفة</span>
            </h1>
            <a href="{{ route('admin.categories.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                Back to Categories / <span class="ms-1" lang="ar" dir="rtl">العودة إلى الفئات</span>
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <section class="overflow-hidden rounded-lg bg-white shadow-sm" aria-labelledby="deleted-category-list-heading">
                <h2 id="deleted-category-list-heading" class="sr-only">Deleted category list / قائمة الفئات المحذوفة</h2>

                @if ($categories->isEmpty())
                    <div class="px-6 py-12 text-center text-sm text-gray-600" role="status">
                        No deleted categories. / <span lang="ar" dir="rtl">لا توجد فئات محذوفة.</span>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Arabic name / <span lang="ar" dir="rtl">الاسم بالعربية</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">English name / <span lang="ar" dir="rtl">الاسم بالإنجليزية</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Canonical slug / <span lang="ar" dir="rtl">المعرّف المعتمد</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Retained status / <span lang="ar" dir="rtl">الحالة المحفوظة</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Display order / <span lang="ar" dir="rtl">ترتيب العرض</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Deleted / <span lang="ar" dir="rtl">تاريخ الحذف</span></th>
                                    <th scope="col" class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Actions / <span lang="ar" dir="rtl">الإجراءات</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @foreach ($categories as $category)
                                    <tr>
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900" lang="ar" dir="rtl">{{ $category->name_ar }}</td>
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $category->name_en }}</td>
                                        <td class="px-6 py-4 font-mono text-sm text-gray-700">{{ $category->slug }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                            {{ $category->is_active ? 'Active' : 'Inactive' }} /
                                            <span lang="ar" dir="rtl">{{ $category->is_active ? 'مفعّلة' : 'غير مفعّلة' }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ $category->display_order }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                            <time datetime="{{ $category->deleted_at->toAtomString() }}">{{ $category->deleted_at->format('Y-m-d') }}</time>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            @can('restore', $category)
                                                <form method="POST" action="{{ route('admin.categories.restore', $category->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                                        Restore / <span lang="ar" dir="rtl">استعادة</span>
                                                    </button>
                                                </form>
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
