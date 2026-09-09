<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Review possible matches / <span lang="ar" dir="rtl">مراجعة المطابقات المحتملة</span></h1></x-slot>
    @php
        $labels = ['unreviewed' => 'Unreviewed / لم تتم مراجعته', 'confirmed_match' => 'Confirmed match / تطابق مؤكد', 'dismissed' => 'Dismissed / مستبعد'];
        $statuses = ['draft' => 'Draft / مسودة', 'pending' => 'Pending / قيد الانتظار', 'under_review' => 'Under review / قيد المراجعة', 'additional_information_required' => 'Additional information required / مطلوب معلومات إضافية', 'approved' => 'Approved / مقبول', 'rejected' => 'Rejected / مرفوض', 'appealed' => 'Appealed / قيد الاستئناف', 'converted_to_campaign' => 'Converted to campaign / تم تحويله إلى حملة', 'campaign_active' => 'Campaign active / الحملة نشطة', 'aid_delivery' => 'Aid delivery / تسليم المساعدة', 'completed' => 'Completed / مكتمل', 'closed' => 'Closed / مغلق'];
    @endphp
    <div class="py-12"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <a href="{{ route('admin.help-applications.in-review.show', $reference) }}" class="text-indigo-600">Back to application / العودة إلى الطلب</a>
        @if (session('status'))<p role="status" class="bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if ($counts->sum() > 0)
            <p>A stored identity match was detected. The identity number is not displayed. / تم اكتشاف تطابق مخزن للهوية. لا يتم عرض رقم الهوية.</p>
        @endif
        <section class="rounded-lg bg-white p-6 shadow-sm">
            <p>Total warnings / إجمالي التحذيرات: {{ $counts->sum() }}</p>
            @foreach ($labels as $status => $label)<p>{{ $label }}: {{ $counts->get($status, 0) }}</p>@endforeach
        </section>
        @if ($counts->sum() === 0)
            <p>No possible matches. / لا توجد مطابقات محتملة.</p>
        @elseif ($counts->get('unreviewed', 0) == 0)
            <p>All possible matches have been reviewed. / تمت مراجعة جميع المطابقات المحتملة.</p>
        @endif
        @foreach ($warnings as $warning)
            @php($bag = $errors->getBag('warning_'.$warning->reference))
            <section class="rounded-lg bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Warning reference / <span lang="ar" dir="rtl">مرجع التحذير</span>: <bdi dir="ltr">{{ $warning->reference }}</bdi></h2>
                <p>{{ $labels[$warning->status->value] }}</p>
                <p>Created / تاريخ الإنشاء: {{ $warning->created_at->format('Y-m-d H:i:s') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach (['current' => 'Current application / الطلب الحالي', 'prior' => 'Possible prior application / الطلب السابق المحتمل'] as $side => $heading)
                        @php($compared = $warning->getRelation($side))
                        <div><h3 class="font-semibold">{{ $heading }}</h3><dl class="space-y-4">
                            @foreach ([
                                'Reference / المرجع' => $compared->reference,
                                'Status / الحالة' => $statuses[$compared->status->value],
                                'Full name / الاسم الكامل' => $compared->full_name,
                                'Date of birth / تاريخ الميلاد' => $compared->date_of_birth,
                                'Identity document type / نوع وثيقة الهوية' => $compared->identity_document_type?->value === 'passport' ? 'Passport / جواز سفر' : 'National ID / بطاقة قومية',
                                'Identity issuing country / بلد إصدار الهوية' => $compared->identity_issuing_country,
                                'Submitted / تاريخ التقديم' => $compared->submitted_at?->format('Y-m-d H:i:s'),
                            ] as $label => $value)
                                <div><dt class="text-sm text-gray-500">{{ $label }}</dt><dd class="break-all">{{ $value ?? '—' }}</dd></div>
                            @endforeach
                        </dl></div>
                    @endforeach
                </div>
                @if ($warning->status->value === 'unreviewed')
                    <form method="POST" action="{{ route('admin.help-applications.in-review.duplicate-warnings.resolve', [$reference, $warning->reference]) }}" class="mt-4">
                        @csrf
                        <label for="outcome-{{ $warning->reference }}">Outcome / النتيجة</label>
                        <select id="outcome-{{ $warning->reference }}" name="outcome" required @if ($bag->has('outcome')) aria-invalid="true" aria-describedby="outcome-error-{{ $warning->reference }}" @endif class="block w-full rounded-md {{ $bag->has('outcome') ? 'border-red-200 focus:border-red-500 focus:ring-red-500' : 'border-gray-300' }}">
                            <option value="">Select / اختر</option>
                            @foreach (['confirmed_match', 'dismissed'] as $outcome)<option value="{{ $outcome }}" @selected($bag->any() && old('outcome') === $outcome)>{{ $labels[$outcome] }}</option>@endforeach
                        </select>
                        @if ($bag->has('outcome'))<p id="outcome-error-{{ $warning->reference }}" class="text-sm text-red-800">{{ $bag->first('outcome') }}</p>@endif
                        <label for="note-{{ $warning->reference }}">Resolution note / ملاحظة الحسم</label>
                        <textarea id="note-{{ $warning->reference }}" name="resolution_note" required minlength="10" maxlength="1000" @if ($bag->has('resolution_note')) aria-invalid="true" aria-describedby="note-error-{{ $warning->reference }}" @endif class="block w-full rounded-md {{ $bag->has('resolution_note') ? 'border-red-200 focus:border-red-500 focus:ring-red-500' : 'border-gray-300' }}"></textarea>
                        @if ($bag->has('resolution_note'))<p id="note-error-{{ $warning->reference }}" class="text-sm text-red-800">{{ $bag->first('resolution_note') }}</p>@endif
                        <button type="submit" class="mt-4 rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white">Resolve warning / حسم التحذير</button>
                    </form>
                @else
                    <p class="mt-4">Resolved / تاريخ الحسم: {{ $warning->resolved_at->format('Y-m-d H:i:s') }}</p>
                    <p class="whitespace-pre-wrap break-all">{{ $warning->resolution_note }}</p>
                @endif
            </section>
        @endforeach
        {{ $warnings->links() }}
    </div></div>
</x-app-layout>
