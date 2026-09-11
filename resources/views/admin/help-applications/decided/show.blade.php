<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Decided Help Application / <span lang="ar" dir="rtl">طلب المساعدة المحسوم</span></h1></x-slot>
    @php
        $identityTypes = ['national_id' => ['National ID', 'بطاقة قومية'], 'passport' => ['Passport', 'جواز سفر']];
        $preferences = ['full_name' => ['Full name', 'الاسم الكامل'], 'first_name' => ['First name only', 'الاسم الأول فقط'], 'anonymous' => ['Anonymous', 'مجهول']];
        $purposes = ['medical_report' => ['Medical report', 'تقرير طبي'], 'cost_estimate' => ['Cost estimate', 'تقدير تكلفة'], 'tuition_invoice' => ['Tuition invoice', 'فاتورة رسوم دراسية'], 'admission_letter' => ['Admission letter', 'خطاب قبول'], 'other' => ['Other evidence', 'مستند آخر']];
        $securityStatuses = ['pending' => ['Processing', 'قيد المعالجة'], 'accepted_unscanned' => ['Structurally accepted; not malware-scanned', 'مقبول بنيويًا؛ لم يُفحص من البرمجيات الخبيثة'], 'clean' => ['Malware scan completed', 'اكتمل فحص البرمجيات الخبيثة'], 'rejected' => ['Not accepted', 'غير مقبول']];
    @endphp
    <div class="py-12"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <a href="{{ route('admin.help-applications.decided.index') }}" class="text-indigo-600">Decided Applications / الطلبات المحسومة</a>
        <section class="rounded-lg bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold">Application category / فئة الطلب</h2>
        @if ($assignedCategory)<p>{{ $assignedCategory->name_en }} / {{ $assignedCategory->name_ar }}</p>
        @if (! $assignedCategory->is_active || $assignedCategory->deleted_at)<p>Unavailable for new assignments / غير متاحة للتعيينات الجديدة</p>@endif
        @else <p>Category unavailable / الفئة غير متاحة</p>@endif</section>
        <section class="rounded-lg bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold">Decision / القرار</h2>
            <p class="whitespace-pre-wrap break-all">{{ $decisionNote }}</p>
            <p>Decided / تاريخ القرار: {{ $application->decided_at?->format('Y-m-d H:i') }}</p>
            <p>Status changed / تغير الحالة: {{ $application->status_changed_at?->format('Y-m-d H:i') }}</p>
            @if ($isSuperAdmin)<p>Decision-maker / صاحب القرار: {{ $decisionMakerName ?: 'Decision-maker unavailable / صاحب القرار غير متاح' }}</p>@endif
            @if ($application->status->value === 'rejected')<p>Appeal deadline / الموعد النهائي للاستئناف: {{ $application->appeal_eligibility_ended_at?->format('Y-m-d H:i') }}</p>@endif
        </section>
        @if ($canConvert) @include('admin.help-applications.decided.conversion') @endif
        @if ($linkedCampaign)<section class="rounded-lg bg-white p-6 shadow-sm"><p>{{ $linkedCampaign->slug }} — Draft / مسودة</p><a href="{{ route('admin.campaigns.edit', $linkedCampaign) }}" class="text-indigo-600">Edit Campaign draft / تعديل مسودة الحملة</a></section>@endif        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="application-status"><h2 id="application-status" class="text-lg font-semibold">Application lifecycle / <span lang="ar" dir="rtl">دورة حياة الطلب</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm font-medium text-gray-500">Reference / <span lang="ar" dir="rtl">المرجع</span></dt><dd class="mt-1 break-all font-mono">{{ $application->reference }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Status / <span lang="ar" dir="rtl">الحالة</span></dt><dd class="mt-1">@include('admin.help-applications.decided.status')</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Submitted / <span lang="ar" dir="rtl">تاريخ التقديم</span></dt><dd class="mt-1">{{ $application->submitted_at?->format('Y-m-d H:i') }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Review started / <span lang="ar" dir="rtl">تاريخ بدء المراجعة</span></dt><dd class="mt-1">{{ $application->review_started_at?->format('Y-m-d H:i') }}</dd></div>
            @if ($isSuperAdmin)<div><dt class="text-sm font-medium text-gray-500">Reviewer / <span lang="ar" dir="rtl">المسؤول</span></dt><dd class="mt-1">{{ $reviewerName ?: 'Reviewer unavailable / المسؤول غير متاح' }}</dd></div>@endif
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="contact"><h2 id="contact" class="text-lg font-semibold">Contact information / <span lang="ar" dir="rtl">معلومات الاتصال</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach ([['Full name', 'الاسم الكامل', $application->full_name], ['Email', 'البريد الإلكتروني', $application->email], ['Phone', 'الهاتف', $application->phone], ['Address', 'العنوان', $application->address], ['Date of birth', 'تاريخ الميلاد', $application->date_of_birth]] as [$en, $ar, $value])<div><dt class="text-sm font-medium text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></dt><dd class="mt-1 break-all">{{ $value }}</dd></div>@endforeach
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="assistance"><h2 id="assistance" class="text-lg font-semibold">Assistance information / <span lang="ar" dir="rtl">معلومات المساعدة</span></h2><dl class="mt-4 space-y-4">
            <div><dt class="text-sm font-medium text-gray-500">Requested amount / <span lang="ar" dir="rtl">المبلغ المطلوب</span></dt><dd class="mt-1">{{ $application->requested_amount }} SDG / <span lang="ar" dir="rtl">ج.س</span></dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Private story / <span lang="ar" dir="rtl">القصة الخاصة</span></dt><dd class="mt-1 whitespace-pre-wrap break-all">{{ $application->private_story }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Preferred way to receive assistance / <span lang="ar" dir="rtl">الطريقة المفضلة لاستلام المساعدة</span></dt><dd class="mt-1 whitespace-pre-wrap break-all">{{ $application->preferred_receiving_method }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Public identity preference / <span lang="ar" dir="rtl">تفضيل الهوية العلنية</span></dt><dd class="mt-1">{{ $preferences[$application->public_identity_preference->value][0] }} / <span lang="ar" dir="rtl">{{ $preferences[$application->public_identity_preference->value][1] }}</span></dd></div>
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="identity"><h2 id="identity" class="text-lg font-semibold">Identity metadata / <span lang="ar" dir="rtl">بيانات الهوية</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm font-medium text-gray-500">Document type / <span lang="ar" dir="rtl">نوع الوثيقة</span></dt><dd class="mt-1">{{ $identityTypes[$application->identity_document_type->value][0] }} / <span lang="ar" dir="rtl">{{ $identityTypes[$application->identity_document_type->value][1] }}</span></dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Issuing country / <span lang="ar" dir="rtl">بلد الإصدار</span></dt><dd class="mt-1">{{ $application->identity_issuing_country }}</dd></div>
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="documents"><h2 id="documents" class="text-lg font-semibold">Active supporting-document metadata / <span lang="ar" dir="rtl">بيانات المستندات الداعمة النشطة</span></h2>
            @forelse ($documents as $document) @php($purpose = $purposes[$document->purpose->value]) @php($security = $securityStatuses[$document->security_status->value])
                <dl class="mt-4 grid gap-3 border-t border-gray-200 pt-4 sm:grid-cols-2">
                    @foreach ([['Filename', 'اسم الملف', $document->original_name], ['Purpose', 'الغرض', $purpose[0].' / '.$purpose[1]], ['Format', 'التنسيق', ['pdf' => 'PDF', 'jpg' => 'JPEG', 'png' => 'PNG'][$document->extension]], ['Size', 'الحجم', number_format($document->size_bytes).' bytes'], ['Security status', 'حالة الأمان', $security[0].' / '.$security[1]], ['Uploaded', 'تاريخ الرفع', $document->created_at->format('Y-m-d H:i')]] as [$en, $ar, $value])<div><dt class="text-sm font-medium text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></dt><dd class="mt-1 break-all">{{ $value }}</dd></div>@endforeach
                </dl>
            @empty <p class="mt-4 text-gray-700">No active supporting-document metadata. / <span lang="ar" dir="rtl">لا توجد بيانات لمستندات داعمة نشطة.</span></p> @endforelse
        </section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="matches"><h2 id="matches" class="text-lg font-semibold">Duplicate warnings / تحذيرات التكرار</h2>
        @foreach (['unreviewed' => 'Unreviewed / لم تتم المراجعة', 'confirmed_match' => 'Confirmed match / تطابق مؤكد', 'dismissed' => 'Dismissed / مستبعد'] as $state => $label)<p>{{ $label }}: {{ $warningCounts[$state] ?? 0 }}</p>@endforeach
        </section>
    </div></div>
</x-app-layout>