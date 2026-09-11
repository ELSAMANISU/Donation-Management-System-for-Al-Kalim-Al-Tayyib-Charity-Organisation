<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Decided Applications / <span lang="ar" dir="rtl">الطلبات المحسومة</span></h1></x-slot>
    <div class="py-12"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <form method="GET" action="{{ route('admin.help-applications.decided.index') }}" class="flex flex-col gap-4 p-4 sm:flex-row sm:items-end">
            <div class="flex min-w-0 flex-col gap-2">
                <label for="status-filter" class="text-sm font-medium text-gray-700">Status / الحالة</label>
                <select id="status-filter" name="status" class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    <option value="" disabled @selected($status === null)>Filter by status / تصفية حسب الحالة</option>
                    <option value="approved" @selected($status === 'approved')>Approved (including converted) / مقبول (يشمل المحوّل)</option>
                    <option value="rejected" @selected($status === 'rejected')>Rejected / مرفوض</option>
                </select>
            </div>
            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                Filter / تصفية
            </button>
            <a href="{{ route('admin.help-applications.decided.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-indigo-600 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                All / الكل
            </a>
        </form>
        @if ($applications->isEmpty())
            <p class="p-6 text-gray-700">No decided applications. / <span lang="ar" dir="rtl">لا توجد طلبات محسومة.</span></p>
        @else
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
                <caption class="sr-only">Decided applications / الطلبات المحسومة</caption>
                <thead class="bg-gray-50"><tr>
                    @foreach ([['Full name', 'الاسم الكامل'], ['Reference', 'المرجع'], ['Submitted', 'تاريخ التقديم'], ['Decided', 'تاريخ القرار'], ['Status', 'الحالة']] as [$en, $ar])
                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium uppercase text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></th>
                    @endforeach
                    @if ($isSuperAdmin)<th scope="col" class="px-6 py-3 text-start text-xs font-medium uppercase text-gray-500">Reviewer / <span lang="ar" dir="rtl">المسؤول</span></th>@endif
                    <th scope="col" class="px-6 py-3 text-start"><span class="sr-only">View / عرض</span></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-200 bg-white">@foreach ($applications as $application)<tr>
                    <td style="vertical-align: middle" class="px-6 py-4 text-sm text-gray-900">{{ $application->full_name }}</td>
                    <td style="vertical-align: middle" class="px-6 py-4 break-all font-mono text-sm text-gray-700"><bdi dir="ltr">{{ $application->reference }}</bdi></td>
                    <td style="vertical-align: middle" class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><time datetime="{{ $application->submitted_at?->toIso8601String() }}">{{ $application->submitted_at?->format('Y-m-d H:i') }}</time></td>
                    <td style="vertical-align: middle" class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><time datetime="{{ $application->decided_at?->toIso8601String() }}">{{ $application->decided_at?->format('Y-m-d H:i') }}</time></td>
                    <td style="vertical-align: middle" class="px-6 py-4 whitespace-nowrap text-start text-sm text-gray-700">@include('admin.help-applications.decided.status')</td>
                    @if ($isSuperAdmin)<td style="vertical-align: middle" class="px-6 py-4 text-sm text-gray-700">{{ $application->reviewer_name ?: 'Reviewer unavailable / المسؤول غير متاح' }}</td>@endif
                    <td style="vertical-align: middle" class="px-6 py-4 whitespace-nowrap text-start text-sm"><a class="rounded-md text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2" href="{{ route('admin.help-applications.decided.show', $application->reference) }}">View / <span lang="ar" dir="rtl">عرض</span></a></td>
                </tr>@endforeach</tbody>
            </table></div><div class="border-t border-gray-200 p-4">{{ $applications->links() }}</div>
        @endif
    </div></div></div>
</x-app-layout>
