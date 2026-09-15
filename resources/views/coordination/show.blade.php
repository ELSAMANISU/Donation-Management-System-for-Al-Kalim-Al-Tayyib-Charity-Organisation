<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900" lang="en">Private assistance coordination</h1>
                <p class="mt-1 text-sm font-medium text-gray-600" lang="ar" dir="rtl">تنسيق المساعدة الخاص</p>
            </div>
            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                <span aria-hidden="true">●</span><span lang="en">Private</span><span lang="ar" dir="rtl">خاص</span>
            </span>
        </div>
    </x-slot>

    @php
        $prefix = $administrator ? 'admin.coordination.' : 'help-applications.coordination.';
        $params = ['helpApplication' => $application->reference];
        if ($coordination) { $params['coordination'] = $coordination->reference; }
        $button = 'inline-flex flex-wrap items-center justify-center gap-1 rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2';
        $secondaryButton = 'inline-flex flex-wrap items-center justify-center gap-1 rounded-lg border border-amber-300 bg-amber-50 px-5 py-3 text-sm font-semibold text-amber-900 shadow-sm hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2';
        $field = 'mt-2 block w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $splitLabel = static fn (string $label): array => array_pad(explode(' / ', $label, 2), 2, '');
    @endphp

    <div class="mx-auto max-w-3xl space-y-4 px-4 py-6 sm:px-6">
        <aside role="note" id="sandbox-warning" aria-labelledby="sandbox-warning-heading" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <h2 id="sandbox-warning-heading" class="font-semibold">
                <span class="block" lang="en">Academic sandbox</span>
                <span class="block text-xs" lang="ar" dir="rtl">بيئة تجريبية أكاديمية</span>
            </h2>
            <div class="mt-1 grid gap-4 lg:grid-cols-2">
                <p lang="en">Sandbox academic demonstration: enter synthetic delivery information only. No real transfer occurs. Never enter real bank credentials, PINs, passwords, card data, CVV, identity documents, or authentication secrets.</p>
                <p lang="ar" dir="rtl">عرض أكاديمي تجريبي: أدخل معلومات تسليم وهمية فقط. لا يحدث أي تحويل حقيقي. لا تدخل بيانات بنكية حقيقية أو أرقامًا سرية أو كلمات مرور أو بيانات بطاقات أو رمز CVV أو وثائق هوية أو أسرار مصادقة.</p>
            </div>
        </aside>

        @if(session('status') === 'coordination-saved')
            <div role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                <span class="block font-medium" lang="en">Coordination saved.</span>
                <span class="block" lang="ar" dir="rtl">تم حفظ التنسيق.</span>
            </div>
        @endif
        <div id="coordination-errors">
            @if($errors->coordination->any())
                <div role="alert" tabindex="-1" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 focus:outline-none focus:ring-2 focus:ring-red-500">{{ $errors->coordination->first() }}</div>
            @endif
        </div>

        @if(!$coordination)
            <section aria-labelledby="not-started-heading" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 id="not-started-heading" class="text-lg font-semibold text-gray-900">
                    <span class="block" lang="en">Coordination has not started.</span>
                    <span class="mt-1 block text-base" lang="ar" dir="rtl">لم يبدأ التنسيق بعد.</span>
                </h2>
                @if($administrator)
                    <form method="POST" action="{{ route($prefix.'start', $params) }}" class="mt-4 border-t border-gray-100 pt-4">
                        @csrf
                        <div id="start-instructions" class="mb-4 text-sm text-gray-600">
                            <p lang="en">Formally ask the applicant to select an assistance-delivery method.</p>
                            <p class="mt-1" lang="ar" dir="rtl">اطلب رسميًا من مقدم الطلب اختيار طريقة تسليم المساعدة.</p>
                        </div>
                        <button class="{{ $button }}" aria-describedby="start-instructions sandbox-warning">
                            <span lang="en">Request delivery method</span><span lang="ar" dir="rtl">طلب طريقة التسليم</span>
                        </button>
                    </form>
                @endif
            </section>
        @else
            @php([$stateEnglish, $stateArabic] = $splitLabel($coordination->state->label()))
            <section aria-labelledby="summary-heading" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <h2 id="summary-heading" class="text-xs font-semibold uppercase tracking-wide text-gray-500" lang="en">Coordination status</h2>
                        <div role="status" class="mt-2 inline-flex flex-col rounded-lg bg-indigo-50 px-3 py-2 text-indigo-700">
                            <span class="text-sm font-semibold" lang="en">{{ $stateEnglish }}</span>
                            <span class="text-sm" lang="ar" dir="rtl">{{ $stateArabic }}</span>
                        </div>
                    </div>
                    <div class="min-w-0 text-sm sm:max-w-md">
                        <div class="flex flex-col gap-1 font-medium text-gray-500"><span lang="en">Reference</span><span lang="ar" dir="rtl">المرجع</span></div>
                        <bdi class="mt-1 block break-all font-mono text-xs text-gray-700">{{ $coordination->reference }}</bdi>
                    </div>
                </div>
                @if($coordination->delivery_method)
                    @php([$methodEnglish, $methodArabic] = $splitLabel($coordination->delivery_method->label()))
                    <div class="mt-5 border-t border-gray-100 pt-4">
                        <h3 class="text-sm font-semibold text-gray-900">
                            <span class="block" lang="en">Selected delivery method</span>
                            <span class="block text-xs font-medium text-gray-600" lang="ar" dir="rtl">طريقة التسليم المختارة</span>
                        </h3>
                        <div class="mt-2 flex flex-col gap-1 text-sm text-gray-700"><span lang="en">{{ $methodEnglish }}</span><span lang="ar" dir="rtl">{{ $methodArabic }}</span></div>
                    </div>
                    @if($details !== null)
                        <div aria-labelledby="delivery-heading" class="mt-4 rounded-lg border border-gray-200 bg-indigo-50 p-4">
                            <h3 id="delivery-heading" class="font-semibold text-indigo-700">
                                @if($coordination->state === \App\Enums\AssistanceCoordinationState::Confirmed)
                                    <span class="block" lang="en">Confirmed delivery instructions — read only</span>
                                    <span class="mt-1 block text-sm" lang="ar" dir="rtl">تعليمات التسليم المؤكدة — للقراءة فقط</span>
                                @else
                                    <span class="block" lang="en">Synthetic delivery instructions</span>
                                    <span class="mt-1 block text-sm" lang="ar" dir="rtl">تعليمات التسليم الوهمية</span>
                                @endif
                            </h3>
                            <p dir="auto" class="mt-3 whitespace-pre-wrap break-all text-sm text-gray-800">{{ $details }}</p>
                        </div>

                    @endif
                @endif
            </section>

            <section aria-labelledby="thread-heading" class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4 sm:px-6">
                    <h2 id="thread-heading" class="text-lg font-semibold text-gray-900">
                        <span class="block" lang="en">Private conversation</span>
                        <span class="block text-sm font-medium text-gray-600" lang="ar" dir="rtl">المحادثة الخاصة</span>
                    </h2>
                </div>
                <div class="space-y-4 px-4 py-6 sm:px-6">
                    @forelse($messages as $message)
                        @php($fromApplicant = $message->sender_side === 'applicant')
                        <article class="flex {{ $fromApplicant ? 'justify-end' : 'justify-start' }}">
                            <div class="min-w-0 max-w-xl">
                                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 {{ $fromApplicant ? 'justify-end text-right' : '' }}">
                                    <span class="flex flex-col gap-1 font-semibold text-gray-700">
                                        <span lang="en">{{ $fromApplicant ? 'Applicant' : 'Administrator' }}</span>
                                        <span lang="ar" dir="rtl">{{ $fromApplicant ? 'مقدم الطلب' : 'المسؤول' }}</span>
                                    </span>
                                    <time datetime="{{ $message->created_at->toISOString() }}">{{ $message->created_at->format('Y-m-d H:i:s') }}</time>
                                </div>
                                <div class="rounded-lg px-4 py-3 shadow-sm {{ $fromApplicant ? 'bg-indigo-600 text-white' : 'border border-gray-200 bg-gray-50 text-gray-900' }}">
                                    <p dir="auto" class="whitespace-pre-wrap break-all text-sm">{{ $message->body }}</p>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-600">
                            <p lang="en">No messages yet.</p><p class="mt-1" lang="ar" dir="rtl">لا توجد رسائل بعد.</p>
                        </div>
                    @endforelse
                </div>
                <nav aria-label="Conversation pages" class="border-t border-gray-100 px-4 py-3 sm:px-6">{{ $messages->links() }}</nav>
            </section>

            @if($coordination->state !== \App\Enums\AssistanceCoordinationState::Confirmed)
                <section aria-labelledby="actions-heading" class="space-y-4">
                    <h2 id="actions-heading" class="px-1 text-lg font-semibold text-gray-900">
                        <span class="block" lang="en">Next actions</span><span class="block text-sm font-medium text-gray-600" lang="ar" dir="rtl">الإجراءات التالية</span>
                    </h2>
                    @if(!$administrator && in_array($coordination->state, [\App\Enums\AssistanceCoordinationState::AwaitingApplicant, \App\Enums\AssistanceCoordinationState::ChangesRequested], true))
                        <form method="POST" action="{{ route($prefix.'respond', $params) }}" class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm" autocomplete="off" aria-labelledby="response-form-heading">
                            @csrf
                            <input type="hidden" name="revision" value="{{ $coordination->revision }}">
                            <h3 id="response-form-heading" class="font-semibold text-gray-900"><span class="block" lang="en">Provide delivery information</span><span class="block text-sm" lang="ar" dir="rtl">تقديم معلومات التسليم</span></h3>
                            <div>
                                <label for="delivery-method" class="block text-sm font-medium text-gray-800"><span class="block" lang="en">Assistance-delivery method</span><span class="block text-xs text-gray-600" lang="ar" dir="rtl">طريقة تسليم المساعدة</span></label>
                                <select id="delivery-method" name="delivery_method" class="{{ $field }}" required aria-describedby="sandbox-warning coordination-errors">
                                    <option value="">Select one — اختر واحدة</option>
                                    @foreach(\App\Enums\AssistanceDeliveryMethod::cases() as $method)
                                        @php([$optionEnglish, $optionArabic] = $splitLabel($method->label()))
                                        <option value="{{ $method->value }}">{{ $optionEnglish }} — {{ $optionArabic }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="delivery-details" class="block text-sm font-medium text-gray-800"><span class="block" lang="en">Synthetic delivery instructions (encrypted)</span><span class="block text-xs text-gray-600" lang="ar" dir="rtl">تعليمات تسليم وهمية (مشفرة)</span></label>
                                <textarea id="delivery-details" name="delivery_details" dir="auto" rows="4" maxlength="2000" required class="{{ $field }}" aria-describedby="sandbox-warning details-help coordination-errors"></textarea>
                                <div id="details-help" class="mt-2 text-xs leading-5 text-gray-600"><p lang="en">Re-enter details after validation failure. Keep these instructions separate from messages.</p><p lang="ar" dir="rtl">أعد إدخال التفاصيل بعد فشل التحقق. احتفظ بهذه التعليمات منفصلة عن الرسائل.</p></div>
                            </div>
                            <button class="{{ $button }}"><span lang="en">Submit method</span><span lang="ar" dir="rtl">إرسال الطريقة</span></button>
                        </form>
                    @endif

                    @if($administrator && $coordination->state === \App\Enums\AssistanceCoordinationState::ApplicantResponded)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <form method="POST" action="{{ route($prefix.'correct', $params) }}" class="space-y-4 rounded-xl border border-amber-300 bg-white p-5 shadow-sm" autocomplete="off" aria-labelledby="correction-form-heading">
                                @csrf
                                <input type="hidden" name="revision" value="{{ $coordination->revision }}">
                                <h3 id="correction-form-heading" class="font-semibold text-gray-900"><span class="block" lang="en">Request a correction</span><span class="block text-sm" lang="ar" dir="rtl">طلب تصحيح</span></h3>
                                <label for="correction" class="block text-sm text-gray-700"><span class="block" lang="en">Optional text; do not include delivery details.</span><span class="block text-xs" lang="ar" dir="rtl">نص اختياري؛ دون تفاصيل تسليم.</span></label>
                                <textarea id="correction" name="body" dir="auto" rows="3" maxlength="2000" class="{{ $field }}" aria-describedby="message-help coordination-errors"></textarea>
                                <button class="{{ $secondaryButton }}"><span lang="en">Request corrections</span><span lang="ar" dir="rtl">طلب تصحيحات</span></button>
                            </form>
                            <form method="POST" action="{{ route($prefix.'confirm', $params) }}" class="flex flex-col justify-between rounded-xl border border-green-200 bg-white p-5 shadow-sm" aria-labelledby="confirmation-form-heading">
                                @csrf
                                <input type="hidden" name="revision" value="{{ $coordination->revision }}">
                                <div><h3 id="confirmation-form-heading" class="font-semibold text-gray-900"><span class="block" lang="en">Confirm readiness</span><span class="block text-sm" lang="ar" dir="rtl">تأكيد الجاهزية</span></h3><p class="mt-2 text-sm text-gray-600" lang="en">Confirm that the delivery information is ready for the future aid-delivery step.</p><p class="mt-1 text-sm text-gray-600" lang="ar" dir="rtl">أكد أن معلومات التسليم جاهزة لخطوة تسليم المساعدة لاحقًا.</p></div>
                                <button class="{{ $button }} mt-4"><span lang="en">Confirm delivery information ready</span><span lang="ar" dir="rtl">تأكيد جاهزية معلومات التسليم</span></button>
                            </form>
                        </div>
                    @endif

                    <form method="POST" action="{{ route($prefix.'message', $params) }}" class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm" autocomplete="off" aria-labelledby="message-form-heading">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $coordination->revision }}">
                        <h3 id="message-form-heading" class="font-semibold text-gray-900"><span class="block" lang="en">Send a private message</span><span class="block text-sm" lang="ar" dir="rtl">إرسال رسالة خاصة</span></h3>
                        <div>
                            <label for="message-body" class="block text-sm font-medium text-gray-800"><span class="block" lang="en">Private message</span><span class="block text-xs text-gray-600" lang="ar" dir="rtl">رسالة خاصة</span></label>
                            <div id="message-help" class="mt-2 text-xs leading-5 text-gray-600"><p lang="en">Text only. Never put sensitive delivery instructions or identifiers in messages. Sent messages cannot be edited or deleted.</p><p lang="ar" dir="rtl">نص فقط. لا تضع تعليمات تسليم حساسة أو معرفات في الرسائل. لا يمكن تعديل الرسائل المرسلة أو حذفها.</p></div>
                            <textarea id="message-body" name="body" dir="auto" rows="4" required maxlength="2000" class="{{ $field }}" aria-describedby="message-help sandbox-warning coordination-errors"></textarea>
                        </div>
                        <button class="{{ $button }}"><span lang="en">Send message</span><span lang="ar" dir="rtl">إرسال رسالة</span></button>
                    </form>
                </section>
            @else
                <section aria-labelledby="read-only-heading" class="rounded-xl border border-green-200 bg-green-50 p-5 text-green-800 shadow-sm">
                    <h2 id="read-only-heading" class="font-semibold"><span class="block" lang="en">Confirmed coordination is read-only.</span><span class="block text-sm" lang="ar" dir="rtl">التنسيق المؤكد للقراءة فقط.</span></h2>
                    <div class="mt-2 space-y-1 text-sm"><p lang="en">The conversation remains available as an immutable record. Actual transfer or delivery remains outside this feature.</p><p lang="ar" dir="rtl">تبقى المحادثة متاحة كسجل غير قابل للتعديل. يظل التحويل أو التسليم الفعلي خارج هذه الميزة.</p></div>
                </section>
            @endif
        @endif
    </div>
</x-app-layout>