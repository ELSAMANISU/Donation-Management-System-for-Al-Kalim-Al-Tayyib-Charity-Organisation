<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Edit Help Application Draft / <span lang="ar" dir="rtl">تعديل مسودة طلب المساعدة</span></h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-lg bg-white p-6 shadow-sm">
                @if (session('status') === 'help-application-draft-created')
                    <div role="status" class="mb-5 rounded-md bg-green-50 p-4 text-sm text-green-800">Your private draft was created. / <span lang="ar" dir="rtl">تم إنشاء مسودتك الخاصة.</span></div>
                @elseif (session('status') === 'help-application-draft-updated')
                    <div role="status" class="mb-5 rounded-md bg-green-50 p-4 text-sm text-green-800">Your private draft was saved. / <span lang="ar" dir="rtl">تم حفظ مسودتك الخاصة.</span></div>
                @elseif (session('status') === 'help-application-draft-unchanged')
                    <div role="status" class="mb-5 rounded-md bg-blue-50 p-4 text-sm text-blue-800">No changes were made. / <span lang="ar" dir="rtl">لم يتم إجراء أي تغييرات.</span></div>
                @endif

                @if (session('status') === 'help-application-document-uploaded')
                    <div role="status" class="mb-5 rounded-md bg-green-50 p-4 text-sm text-green-800">Private supporting document uploaded. / <span lang="ar" dir="rtl">تم رفع المستند الداعم الخاص.</span></div>
                @elseif (session('status') === 'help-application-document-removed')
                    <div role="status" class="mb-5 rounded-md bg-green-50 p-4 text-sm text-green-800">Supporting document permanently removed. / <span lang="ar" dir="rtl">تمت إزالة المستند الداعم نهائياً.</span></div>
                @endif

                @include('applicant.help-applications.partials.form', [
                    'action' => route('help-applications.update', $application),
                    'method' => 'PATCH',
                ])

                <section class="mt-8 border-t pt-6" aria-labelledby="supporting-documents-heading">
                    <h2 id="supporting-documents-heading" class="text-lg font-semibold">Private supporting documents / <span lang="ar" dir="rtl">المستندات الداعمة الخاصة</span></h2>
                    <p id="document-guidance" class="mt-2 text-sm text-gray-700">PDF, JPG/JPEG, or PNG; maximum 10 MiB each, 10 documents, 50 MiB combined. Files remain private. Structural validation is not malware scanning. / <span lang="ar" dir="rtl">PDF أو JPG/JPEG أو PNG؛ بحد أقصى 10 ميبيبايت للملف و10 مستندات و50 ميبيبايت إجمالاً. تبقى الملفات خاصة. التحقق البنيوي ليس فحصاً للبرمجيات الخبيثة.</span></p>
                    @if ($errors->has('document') || $errors->has('purpose'))
                        <div role="alert" aria-live="assertive" class="mt-4 rounded-md bg-red-50 p-4 text-sm text-red-800"><p>Document upload needs attention. / <span lang="ar" dir="rtl">يرجى مراجعة رفع المستند.</span></p><ul class="mt-2 list-disc pl-5">@foreach (['document', 'purpose'] as $field) @foreach ($errors->get($field) as $error)<li>{{ $error }}</li>@endforeach @endforeach</ul></div>
                    @endif
                    <form class="mt-5 space-y-4" method="POST" enctype="multipart/form-data" action="{{ route('help-applications.documents.store', $application) }}">
                        @csrf
                        <div><label for="document" class="block text-sm font-medium">Document / <span lang="ar" dir="rtl">المستند</span></label><input id="document" name="document" type="file" required accept=".pdf,.jpg,.jpeg,.png" aria-describedby="document-guidance{{ $errors->has('document') ? ' document-error' : '' }}" @if($errors->has('document')) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border {{ $errors->has('document') ? 'border-red-200 focus:border-red-500 focus:ring-2 focus:ring-red-500' : 'border-gray-300' }}">@error('document')<p id="document-error" class="mt-1 text-sm text-red-800">{{ $message }}</p>@enderror</div>
                        <div><label for="purpose" class="block text-sm font-medium">Purpose / <span lang="ar" dir="rtl">الغرض</span></label><select id="purpose" name="purpose" required class="mt-1 block w-full rounded-md border-gray-300"><option value="">Select / اختر</option>@foreach ($documentPurposes as $purpose)<option value="{{ $purpose->value }}">{{ match($purpose->value) {'medical_report' => 'Medical report / تقرير طبي', 'cost_estimate' => 'Cost estimate / تقدير تكلفة', 'tuition_invoice' => 'Tuition invoice / فاتورة دراسية', 'admission_letter' => 'Admission letter / خطاب قبول', default => 'Other / أخرى'} }}</option>@endforeach</select></div>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" type="submit">Upload document / رفع المستند</button>
                    </form>
                    <div class="mt-6 space-y-3">
                        @forelse ($documents as $document)
                            <article class="rounded-md border p-4"><p class="font-medium break-words">{{ $document->original_name }}</p><p class="text-sm text-gray-600">{{ match($document->purpose->value) {'medical_report' => 'Medical report / تقرير طبي', 'cost_estimate' => 'Cost estimate / تقدير تكلفة', 'tuition_invoice' => 'Tuition invoice / فاتورة دراسية', 'admission_letter' => 'Admission letter / خطاب قبول', default => 'Other / أخرى'} }} · {{ strtoupper($document->extension === 'jpg' ? 'JPEG' : $document->extension) }} · {{ number_format($document->size_bytes, 0, '.', ',') }} bytes / <span lang="ar" dir="rtl">بايت</span> · <time datetime="{{ $document->created_at->toIso8601String() }}">{{ $document->created_at->format('Y-m-d H:i') }}</time></p><form class="mt-3" method="POST" action="{{ route('help-applications.documents.destroy', [$application, $document]) }}" onsubmit="return confirm('Permanently remove this private document? / هل تريد إزالة هذا المستند الخاص نهائياً؟')">@csrf @method('DELETE')<button type="submit" class="text-sm font-semibold text-red-700">Permanently remove / إزالة نهائية</button></form></article>
                        @empty
                            <p class="text-sm text-gray-600">No supporting documents uploaded. / <span lang="ar" dir="rtl">لم يتم رفع مستندات داعمة.</span></p>
                        @endforelse
                    </div>
                </section>

                <section class="mt-8 border-t pt-6" aria-labelledby="submission-consent-heading">
                    <h2 id="submission-consent-heading" class="text-lg font-semibold">Submit for review / <span lang="ar" dir="rtl">الإرسال للمراجعة</span></h2>
                    <p class="mt-2 text-sm text-gray-700" role="note">Saving the draft does not submit it. Submission locks editing, does not guarantee approval, and publishes nothing automatically. / <span lang="ar" dir="rtl">حفظ المسودة لا يرسلها. يؤدي الإرسال إلى قفل التعديل، ولا يضمن القبول، ولا ينشر أي شيء تلقائياً.</span></p>

                    @if ($errors->has('application') || $errors->has('consent'))
                        <div role="alert" class="mt-4 rounded-md bg-red-50 p-4 text-sm text-red-800">
                            @foreach (array_merge($errors->get('application'), $errors->get('consent')) as $message)<p>{{ $message }}</p>@endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('help-applications.submit', $application) }}" class="mt-5 space-y-4">
                        @csrf
                        <div class="rounded-md border p-4">
                            <p id="consent-en" lang="en">“I confirm that the information I provided is accurate to the best of my knowledge. I consent to the Al-Kalimah Foundation securely storing and processing my private personal information, identity information, story, and supporting documents solely to review and manage my request for assistance. I understand that submitting the application locks the current draft, does not guarantee approval, and does not automatically publish my story, identity, or documents.”</p>
                            <p id="consent-ar" lang="ar" dir="rtl" class="mt-3">“أؤكد أن المعلومات التي قدمتها صحيحة حسب علمي، وأوافق على قيام مؤسسة الكلمة بحفظ ومعالجة بياناتي الشخصية الخاصة ومعلومات هويتي وقصتي ومستنداتي الداعمة بصورة آمنة، وذلك فقط لمراجعة طلب المساعدة وإدارته. وأفهم أن إرسال الطلب يؤدي إلى قفل المسودة الحالية، ولا يضمن قبول الطلب، ولا ينشر قصتي أو هويتي أو مستنداتي تلقائيًا.”</p>
                            <div class="mt-4 flex items-start gap-2">
                                <input id="consent" name="consent" type="checkbox" value="1" required aria-describedby="consent-en consent-ar{{ $errors->has('consent') ? ' consent-error' : '' }}" @if($errors->has('consent')) aria-invalid="true" @endif class="mt-1 rounded text-indigo-600 focus:ring-2 {{ $errors->has('consent') ? 'border-red-200 focus:ring-red-500' : 'border-gray-300 focus:ring-indigo-500' }}">
                                <label for="consent" class="font-medium">I deliberately accept this consent / <span lang="ar" dir="rtl">أوافق صراحةً على هذا الإقرار</span></label>
                            </div>
                            @error('consent')<p id="consent-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2 font-semibold text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Submit Application / <span lang="ar" dir="rtl">إرسال الطلب</span></button>
                    </form>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
