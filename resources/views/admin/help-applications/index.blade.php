<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Pending Help Applications / <span lang="ar" dir="rtl">طلبات المساعدة قيد الانتظار</span></h1></x-slot>
    <div class="py-12"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><div class="overflow-hidden rounded-lg bg-white shadow-sm">
        @if (session('status') === 'help-application-review-started')
            <div role="status" class="bg-green-50 p-4 text-sm text-green-800">Review started successfully. / <span lang="ar" dir="rtl">بدأت مراجعة الطلب بنجاح.</span></div>
        @elseif (session('status') === 'help-application-review-already-started')
            <div role="status" class="bg-blue-50 p-4 text-sm text-blue-800">Review already started. / <span lang="ar" dir="rtl">بدأت مراجعة الطلب بالفعل.</span></div>
        @endif
        @if ($applications->isEmpty())
            <p class="p-6 text-gray-700">No pending help applications. / <span lang="ar" dir="rtl">لا توجد طلبات مساعدة قيد الانتظار.</span></p>
        @else
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
                <caption class="sr-only">Pending help application review queue / قائمة مراجعة الطلبات قيد الانتظار</caption>
                <thead class="bg-gray-50"><tr>
                    @foreach ([['Full name', 'الاسم الكامل'], ['Reference', 'المرجع'], ['Submitted', 'تاريخ التقديم'], ['Status', 'الحالة']] as [$en, $ar])
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></th>
                    @endforeach
                    <th scope="col" class="px-6 py-3"><span class="sr-only">Review / مراجعة</span></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-200 bg-white">@foreach ($applications as $application)<tr>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $application->full_name }}</td>
                    <td class="px-6 py-4 font-mono text-sm text-gray-700">{{ $application->reference }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700"><time datetime="{{ $application->submitted_at->toIso8601String() }}">{{ $application->submitted_at->format('Y-m-d H:i') }}</time></td>
                    <td class="px-6 py-4 text-sm text-gray-700">Pending / <span lang="ar" dir="rtl">قيد الانتظار</span></td>
                    <td class="px-6 py-4 text-sm"><a class="text-indigo-600 hover:text-indigo-800" href="{{ route('admin.help-applications.show', $application->reference) }}">Review / <span lang="ar" dir="rtl">مراجعة</span></a></td>
                </tr>@endforeach</tbody>
            </table></div><div class="border-t border-gray-200 p-4">{{ $applications->links() }}</div>
        @endif
    </div></div></div>
</x-app-layout>
