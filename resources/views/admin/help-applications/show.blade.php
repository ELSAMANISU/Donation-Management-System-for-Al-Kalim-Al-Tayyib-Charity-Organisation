<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Help Application Review / <span lang="ar" dir="rtl">مراجعة طلب المساعدة</span></h1></x-slot>
    @php
        $identityTypes = ['national_id' => ['National ID', 'بطاقة قومية'], 'passport' => ['Passport', 'جواز سفر']];
        $preferences = ['full_name' => ['Full name', 'الاسم الكامل'], 'first_name' => ['First name only', 'الاسم الأول فقط'], 'anonymous' => ['Anonymous', 'مجهول']];
        $purposes = ['medical_report' => ['Medical report', 'تقرير طبي'], 'cost_estimate' => ['Cost estimate', 'تقدير تكلفة'], 'tuition_invoice' => ['Tuition invoice', 'فاتورة رسوم دراسية'], 'admission_letter' => ['Admission letter', 'خطاب قبول'], 'other' => ['Other evidence', 'مستند آخر']];
        $securityStatuses = ['pending' => ['Processing', 'قيد المعالجة'], 'accepted_unscanned' => ['Structurally accepted; not malware-scanned', 'مقبول بنيويًا؛ لم يُفحص من البرمجيات الخبيثة'], 'clean' => ['Malware scan completed', 'اكتمل فحص البرمجيات الخبيثة'], 'rejected' => ['Not accepted', 'غير مقبول']];
    @endphp
    <div class="py-12"><div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('admin.help-applications.start-review', $application->reference) }}">
            @csrf
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Start review / <span lang="ar" dir="rtl">بدء المراجعة</span></button>
        </form>
        <a href="{{ route('admin.help-applications.index') }}" class="text-indigo-600 hover:text-indigo-800">← Pending applications / <span lang="ar" dir="rtl">الطلبات قيد الانتظار</span></a>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="application-status"><h2 id="application-status" class="text-lg font-semibold">Application status / <span lang="ar" dir="rtl">حالة الطلب</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm font-medium text-gray-500">Reference / <span lang="ar" dir="rtl">المرجع</span></dt><dd class="mt-1 break-all font-mono">{{ $application->reference }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Status / <span lang="ar" dir="rtl">الحالة</span></dt><dd class="mt-1">Pending / <span lang="ar" dir="rtl">قيد الانتظار</span></dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Submitted / <span lang="ar" dir="rtl">تاريخ التقديم</span></dt><dd class="mt-1">{{ $application->submitted_at->format('Y-m-d H:i') }}</dd></div>
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="contact"><h2 id="contact" class="text-lg font-semibold">Contact information / <span lang="ar" dir="rtl">معلومات الاتصال</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach ([['Full name', 'الاسم الكامل', $application->full_name], ['Email', 'البريد الإلكتروني', $application->email], ['Phone', 'الهاتف', $application->phone], ['Address', 'العنوان', $application->address], ['Date of birth', 'تاريخ الميلاد', $application->date_of_birth]] as [$en, $ar, $value])<div><dt class="text-sm font-medium text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></dt><dd class="mt-1 break-words">{{ $value }}</dd></div>@endforeach
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="assistance"><h2 id="assistance" class="text-lg font-semibold">Assistance details / <span lang="ar" dir="rtl">تفاصيل المساعدة</span></h2><dl class="mt-4 space-y-4">
            <div><dt class="text-sm font-medium text-gray-500">Requested amount / <span lang="ar" dir="rtl">المبلغ المطلوب</span></dt><dd class="mt-1">{{ number_format((float) $application->requested_amount, 2, '.', ',') }} SDG / <span lang="ar" dir="rtl">ج.س</span></dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Private story / <span lang="ar" dir="rtl">القصة الخاصة</span></dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ $application->private_story }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Preferred way to receive assistance / <span lang="ar" dir="rtl">الطريقة المفضلة لاستلام المساعدة</span></dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ $application->preferred_receiving_method }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Public identity preference / <span lang="ar" dir="rtl">تفضيل الهوية العلنية</span></dt><dd class="mt-1">{{ $preferences[$application->public_identity_preference->value][0] }} / <span lang="ar" dir="rtl">{{ $preferences[$application->public_identity_preference->value][1] }}</span></dd></div>
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="identity"><h2 id="identity" class="text-lg font-semibold">Identity metadata / <span lang="ar" dir="rtl">بيانات الهوية</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm font-medium text-gray-500">Document type / <span lang="ar" dir="rtl">نوع الوثيقة</span></dt><dd class="mt-1">{{ $identityTypes[$application->identity_document_type->value][0] }} / <span lang="ar" dir="rtl">{{ $identityTypes[$application->identity_document_type->value][1] }}</span></dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Issuing country / <span lang="ar" dir="rtl">بلد الإصدار</span></dt><dd class="mt-1">{{ $application->identity_issuing_country }}</dd></div>
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="documents"><h2 id="documents" class="text-lg font-semibold">Supporting-document metadata / <span lang="ar" dir="rtl">بيانات المستندات الداعمة</span></h2>
            @forelse ($documents as $document) @php($purpose = $purposes[$document->purpose->value]) @php($security = $securityStatuses[$document->security_status->value])
                <dl class="mt-4 grid gap-3 border-t border-gray-200 pt-4 sm:grid-cols-2">
                    @foreach ([['Filename', 'اسم الملف', $document->original_name], ['Purpose', 'الغرض', $purpose[0].' / '.$purpose[1]], ['Format', 'التنسيق', ['pdf' => 'PDF', 'jpg' => 'JPEG', 'png' => 'PNG'][$document->extension]], ['Size', 'الحجم', number_format($document->size_bytes).' bytes'], ['Security status', 'حالة الأمان', $security[0].' / '.$security[1]], ['Uploaded', 'تاريخ الرفع', $document->created_at->format('Y-m-d H:i')]] as [$en, $ar, $value])<div><dt class="text-sm font-medium text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></dt><dd class="mt-1 break-all">{{ $value }}</dd></div>@endforeach
                </dl>
            @empty <p class="mt-4 text-gray-700">No active supporting-document metadata. / <span lang="ar" dir="rtl">لا توجد بيانات لمستندات داعمة نشطة.</span></p> @endforelse
        </section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="matches"><h2 id="matches" class="text-lg font-semibold">Possible prior matches / <span lang="ar" dir="rtl">المطابقات السابقة المحتملة</span></h2><p class="mt-4">@if ($duplicateWarningCount) Possible prior-application matches require later authorized review: {{ $duplicateWarningCount }}. / <span lang="ar" dir="rtl">توجد مطابقات محتملة مع طلبات سابقة وتتطلب مراجعة مخولة لاحقًا: {{ $duplicateWarningCount }}.</span> @else No possible prior matches recorded. / <span lang="ar" dir="rtl">لا توجد مطابقات سابقة محتملة مسجلة.</span> @endif</p></section>
    </div></div>
</x-app-layout>
