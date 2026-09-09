<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Help Applications Under Review / <span lang="ar" dir="rtl">طلبات المساعدة قيد المراجعة</span></h1></x-slot>
    <div class="py-12"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><div class="overflow-hidden rounded-lg bg-white shadow-sm">
        @if (session('status') === 'help-application-approved')
            <p role="status" class="bg-green-50 p-4 text-sm text-green-800">Help Application approved successfully. / تم قبول طلب المساعدة بنجاح.</p>
        @elseif (session('status') === 'help-application-rejected')
            <p role="status" class="bg-green-50 p-4 text-sm text-green-800">Help Application rejected successfully. / تم رفض طلب المساعدة بنجاح.</p>
        @endif
        @if ($applications->isEmpty())
            <p class="p-6 text-gray-700">No help applications are currently under review. / <span lang="ar" dir="rtl">لا توجد طلبات مساعدة قيد المراجعة حاليًا.</span></p>
        @else
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
                <caption class="sr-only">Help applications under review / طلبات المساعدة قيد المراجعة</caption>
                <thead class="bg-gray-50"><tr>
                    @foreach ([['Full name', 'الاسم الكامل'], ['Reference', 'المرجع'], ['Submitted', 'تاريخ التقديم'], ['Review started', 'تاريخ بدء المراجعة'], ['Status', 'الحالة']] as [$en, $ar])
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></th>
                    @endforeach
                    @if ($isSuperAdmin)<th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Reviewer / <span lang="ar" dir="rtl">المسؤول</span></th>@endif
                    <th scope="col" class="px-6 py-3"><span class="sr-only">View / عرض</span></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-200 bg-white">@foreach ($applications as $application)<tr>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $application->full_name }}</td>
                    <td class="px-6 py-4 font-mono text-sm text-gray-700">{{ $application->reference }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700"><time datetime="{{ $application->submitted_at->toIso8601String() }}">{{ $application->submitted_at->format('Y-m-d H:i') }}</time></td>
                    <td class="px-6 py-4 text-sm text-gray-700"><time datetime="{{ $application->review_started_at->toIso8601String() }}">{{ $application->review_started_at->format('Y-m-d H:i') }}</time></td>
                    <td class="px-6 py-4 text-sm text-gray-700">Under review / <span lang="ar" dir="rtl">قيد المراجعة</span></td>
                    @if ($isSuperAdmin)<td class="px-6 py-4 text-sm text-gray-700">{{ $application->reviewer_name ?: 'Reviewer unavailable / المسؤول غير متاح' }}</td>@endif
                    <td class="px-6 py-4 text-sm"><a class="text-indigo-600 hover:text-indigo-800" href="{{ route('admin.help-applications.in-review.show', $application->reference) }}">View / <span lang="ar" dir="rtl">عرض</span></a></td>
                </tr>@endforeach</tbody>
            </table></div><div class="border-t border-gray-200 p-4">{{ $applications->links() }}</div>
        @endif
    </div></div></div>
</x-app-layout>
