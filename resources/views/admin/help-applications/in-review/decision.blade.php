<section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="application-decision">
    <h2 id="application-decision" class="text-lg font-semibold">Final review decision / <span lang="ar" dir="rtl">قرار المراجعة النهائي</span></h2>
    @if ($decisionReadiness['unreviewed'])
        <p class="mt-4">Review all possible matches before deciding. / راجع جميع المطابقات المحتملة قبل اتخاذ القرار.</p>
        <a class="text-indigo-600" href="{{ route('admin.help-applications.in-review.duplicate-warnings.index', $application->reference) }}">Review possible matches / مراجعة المطابقات المحتملة</a>
    @elseif ($decisionReadiness['ready'])
        <p class="mt-4">The first successful decision is final for this stage. / أول قرار ناجح نهائي لهذه المرحلة.</p>
        @if ($decisionReadiness['confirmed'])
            <p class="mt-4">A confirmed match prevents approval; rejection remains available. / المطابقة المؤكدة تمنع الموافقة؛ يظل الرفض متاحًا.</p>
        @endif
        @php($decisionErrors = $errors->getBag('decision'))
        <form class="mt-4" method="POST" action="{{ route('admin.help-applications.in-review.decide', $application->reference) }}">
            @csrf
            <label for="decision-outcome" class="text-sm font-medium text-gray-700">Outcome / <span lang="ar" dir="rtl">النتيجة</span></label>
            <select id="decision-outcome" name="outcome" required @if ($decisionErrors->has('outcome')) aria-invalid="true" aria-describedby="decision-outcome-error" @endif class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">Select / اختر</option>
                @if (! $decisionReadiness['confirmed'])
                    <option value="approved" @selected(old('outcome') === 'approved')>Approve / موافقة</option>
                @endif
                <option value="rejected" @selected(old('outcome') === 'rejected')>Reject / رفض</option>
            </select>
            @error('outcome', 'decision')<p id="decision-outcome-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
            <label for="decision-note" class="text-sm font-medium text-gray-700">Decision note / <span lang="ar" dir="rtl">ملاحظة القرار</span></label>
            <textarea id="decision-note" name="decision_note" required minlength="10" maxlength="2000" @if ($decisionErrors->has('decision_note')) aria-invalid="true" aria-describedby="decision-note-error" @endif class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
            @error('decision_note', 'decision')<p id="decision-note-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
            <button type="submit" class="mt-4 rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Record decision / <span lang="ar" dir="rtl">تسجيل القرار</span></button>
        </form>
    @else
        <p class="mt-4">This application is not ready for a decision. / هذا الطلب غير جاهز لاتخاذ قرار.</p>
    @endif
</section>