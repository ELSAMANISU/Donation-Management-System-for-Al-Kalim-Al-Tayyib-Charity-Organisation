<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Administrative reports / <span lang="ar" dir="rtl">التقارير الإدارية</span></h1></x-slot>
    @php
        $unavailable = 'Unavailable / غير متاح';
        $money = fn ($value) => $value === null ? $unavailable : $value.' SDG / ج.س';
        $labels = [
            'pending' => 'Pending / قيد الانتظار', 'succeeded' => 'Succeeded / ناجح', 'failed' => 'Failed / فاشل',
            'cancelled' => 'Cancelled / ملغاة', 'expired' => 'Expired / منتهية', 'draft' => 'Draft / مسودة',
            'active' => 'Active / نشطة', 'paused' => 'Paused / متوقفة', 'funded' => 'Funded / مكتملة التمويل',
            'aid_delivery' => 'Aid delivery / تسليم المساعدة', 'completed' => 'Completed / مكتملة',
            'under_review' => 'Under review / قيد المراجعة', 'additional_information_required' => 'Additional information required / معلومات إضافية مطلوبة',
            'approved' => 'Approved / مقبولة', 'rejected' => 'Rejected / مرفوضة', 'appealed' => 'Appealed / مستأنفة',
            'converted_to_campaign' => 'Converted to campaign / محولة إلى حملة', 'campaign_active' => 'Campaign active / الحملة نشطة',
            'closed' => 'Closed / مغلقة', 'unrecognized' => 'Unrecognized status / حالة غير معروفة',
        ];
        $warningLabels = [
            'invalid_donation_money' => 'Invalid donation amount or currency / مبلغ تبرع أو عملة غير صالحة',
            'missing_funding_link' => 'Missing funding linkage / ارتباط تمويل مفقود',
            'invalid_payment_time' => 'Payment dates require review / تواريخ الدفع تحتاج مراجعة',
            'invalid_campaign_money' => 'Campaign amounts require review / مبالغ الحملات تحتاج مراجعة',
            'raised_total_mismatch' => 'Stored raised totals differ from donations / اختلاف الإجمالي المخزن عن التبرعات',
            'invalid_delivery' => 'Delivery integrity requires review / بيانات التسليم تحتاج مراجعة',
            'delivery_exceeds_funding' => 'Delivery funding reconciliation failed / تعذرت مطابقة التسليم بالتمويل',
            'incoherent_completion' => 'Completion integrity requires review / بيانات الإكمال تحتاج مراجعة',
            'negative_balance' => 'Negative balance requires review / الرصيد السالب يحتاج مراجعة',
            'unknown_status' => 'Unrecognized lifecycle data / بيانات حالة غير معروفة',
        ];
    @endphp
    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <p>Academic sandbox: no real money or aid transfer occurred. / محاكاة أكاديمية: لم يحدث تحويل أموال أو مساعدات حقيقي.</p>
        <p>Generated / أُنشئ: <time>{{ $report->generatedAt }}</time>. All dates are UTC / جميع التواريخ بتوقيت UTC.</p>
        <form method="GET" action="{{ route('admin.reports.index') }}" class="rounded-lg bg-white p-6 shadow-sm flex flex-wrap items-end gap-4">
            <div><label for="start_date" class="block">Start date / تاريخ البداية</label><input id="start_date" name="start_date" type="date" required value="{{ $report->filters['start_date'] }}" class="rounded border-gray-300"></div>
            <div><label for="end_date" class="block">End date / تاريخ النهاية</label><input id="end_date" name="end_date" type="date" required value="{{ $report->filters['end_date'] }}" class="rounded border-gray-300"></div>
            <div><label for="group_by" class="block">Group by / التجميع</label><select id="group_by" name="group_by" class="rounded border-gray-300"><option value="day" @selected($report->filters['group_by'] === 'day')>Day / يوم</option><option value="month" @selected($report->filters['group_by'] === 'month')>Month / شهر</option></select></div>
            <x-primary-button>Apply / تطبيق</x-primary-button>
            <p class="w-full text-sm">Inclusive dates, maximum 366 days. Today is partial. These filters affect donation-period tables only. / تواريخ شاملة بحد أقصى 366 يوماً. اليوم غير مكتمل. المرشحات تخص جداول تبرعات الفترة فقط.</p>
        </form>
        @if ($report->warnings)
            <section role="status" aria-labelledby="integrity-title" class="rounded-lg bg-amber-50 p-6">
                <h2 id="integrity-title" class="font-semibold">Integrity review required / مراجعة سلامة البيانات مطلوبة</h2>
                <p>Affected amounts are unavailable; partial counts are not complete totals. No records were repaired. / المبالغ المتأثرة غير متاحة والأعداد الجزئية ليست إجماليات مكتملة. لم تُعدّل السجلات.</p>
                <ul class="list-disc ps-6">@foreach ($report->warnings as $key => $count)<li>{{ $warningLabels[$key] }}: {{ $count }}</li>@endforeach</ul>
            </section>
        @endif
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="funding-title">
            <h2 id="funding-title" class="text-lg font-semibold">Successful donations / التبرعات الناجحة</h2>
            <dl class="grid gap-4 sm:grid-cols-2 mt-4">
                <div><dt>Lifetime amount / المبلغ منذ البداية</dt><dd>{{ $money($report->lifetime['amount']) }}</dd></div>
                <div><dt>Lifetime count / العدد منذ البداية</dt><dd>{{ $report->lifetime['count'] }}</dd></div>
                <div><dt>Selected-period amount / مبلغ الفترة المحددة</dt><dd>{{ $money($report->period['amount']) }}</dd></div>
                <div><dt>Selected-period count / عدد الفترة المحددة</dt><dd>{{ $report->period['count'] }} @if($report->period['incomplete'])(Incomplete / غير مكتمل)@endif</dd></div>
            </dl>
        </section>
        <section class="rounded-lg bg-white p-6 shadow-sm overflow-x-auto">
            <table class="w-full text-start"><caption class="text-lg font-semibold text-start mb-3">Donations by period / التبرعات حسب الفترة</caption><thead><tr><th scope="col" class="text-start">UTC period / الفترة</th><th scope="col">Count / العدد</th><th scope="col">Amount / المبلغ</th></tr></thead><tbody>
                @foreach ($report->buckets as $date => $bucket)<tr><th scope="row" class="text-start">{{ $date }}</th><td class="text-center">{{ $bucket['count'] }}</td><td class="text-center">{{ $money($bucket['amount']) }}</td></tr>@endforeach
            </tbody></table>
        </section>
        <section class="rounded-lg bg-white p-6 shadow-sm overflow-x-auto">
            <table class="w-full"><caption class="text-lg font-semibold text-start mb-3">Selected-period donations by current active category / تبرعات الفترة حسب الفئة النشطة الحالية</caption><thead><tr><th scope="col" class="text-start">Category / الفئة</th><th scope="col">Count / العدد</th><th scope="col">Amount / المبلغ</th></tr></thead><tbody>
                @forelse ($report->categories as $category)<tr><th scope="row" class="text-start">{{ $category['name_en'] }} / <span lang="ar" dir="rtl">{{ $category['name_ar'] }}</span></th><td class="text-center">{{ $category['count'] }}</td><td class="text-center">{{ $money($category['amount']) }}</td></tr>@empty<tr><td colspan="3">No active categories on this page / لا توجد فئات نشطة في هذه الصفحة</td></tr>@endforelse
            </tbody></table>
            <p class="mt-4">Inactive or archived categories / الفئات غير النشطة أو المؤرشفة: {{ $report->archivedCategory['count'] }} — {{ $money($report->archivedCategory['amount']) }}</p>
            @include('admin.reports.pagination', ['parameter' => 'category_page', 'total' => $report->categoryCount, 'label' => 'Categories / الفئات'])
        </section>
        @foreach (['Current donation statuses / حالات التبرعات الحالية' => $report->donationStatuses, 'Current campaign outcomes / نتائج الحملات الحالية' => $report->campaignStatuses, 'Current application statuses / حالات الطلبات الحالية' => $report->applicationStatuses] as $title => $counts)
            <section class="rounded-lg bg-white p-6 shadow-sm"><table class="w-full"><caption class="text-lg font-semibold text-start mb-3">{{ $title }}</caption><thead><tr><th scope="col" class="text-start">Status / الحالة</th><th scope="col">Count / العدد</th></tr></thead><tbody>
                @foreach ($counts as $status => $count)<tr><th scope="row" class="text-start">{{ $labels[$status] }}</th><td class="text-center">{{ $count }}</td></tr>@endforeach
            </tbody></table></section>
        @endforeach
        <p>Archived campaigns excluded from current campaign counts / الحملات المؤرشفة المستبعدة من الأعداد الحالية: {{ $report->archivedCampaigns }}.</p>
        <p>Active campaigns past expiry / الحملات النشطة بعد انتهاء مدتها: {{ $report->expiredActiveCampaigns }}. This is a subset of active campaigns / هذه مجموعة فرعية من الحملات النشطة.</p>
        <section class="rounded-lg bg-white p-6 shadow-sm overflow-x-auto">
            <table class="w-full"><caption class="text-lg font-semibold text-start mb-3">Lifetime campaign reconciliation / مطابقة تمويل الحملات منذ البداية</caption><thead><tr><th scope="col">Campaign / الحملة</th><th scope="col">Successful funding / التمويل الناجح</th><th scope="col">Stored raised / المحصل المخزن</th><th scope="col">Target / الهدف</th><th scope="col">Stored minus ledger / المخزن ناقص السجل</th></tr></thead><tbody>
                @forelse($report->campaigns as $campaign)<tr><th scope="row" class="text-start">#{{ $campaign['id'] }} {{ $campaign['name_en'] }} / <span lang="ar" dir="rtl">{{ $campaign['name_ar'] }}</span> @if($campaign['archived'])<span>(Archived / مؤرشفة)</span>@endif</th><td class="text-center">{{ $money($campaign['ledger']) }}</td><td class="text-center">{{ $money($campaign['stored']) }}</td><td class="text-center">{{ $money($campaign['target']) }}</td><td class="text-center">{{ $money($campaign['difference']) }}</td></tr>@empty<tr><td colspan="5">No campaigns on this page / لا توجد حملات في هذه الصفحة</td></tr>@endforelse
            </tbody></table>
            @include('admin.reports.pagination', ['parameter' => 'campaign_page', 'total' => $report->campaignCount, 'label' => 'Campaigns / الحملات'])
        </section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="delivery-title">
            <h2 id="delivery-title" class="text-lg font-semibold">Lifetime assistance aggregates / إجماليات المساعدة منذ البداية</h2>
            <dl class="space-y-2 mt-4"><div><dt>Simulated delivered amount / مبلغ التسليم بالمحاكاة</dt><dd>{{ $money($report->delivery['amount']) }}</dd></div>
                <div><dt>Validated terminal deliveries / عمليات التسليم النهائية المتحققة</dt><dd>{{ $report->delivery['count'] }} @if($report->delivery['incomplete'])(Incomplete / غير مكتمل)@endif</dd></div>
                <div><dt>Undelivered raised balance / الرصيد المحصل غير المسلّم</dt><dd>{{ $money($report->undelivered) }}</dd></div>
                <div><dt>Coherent completed assistance / المساعدات المكتملة المتطابقة</dt><dd>{{ $report->completedAssistance }}</dd></div>
            </dl>
            <p class="mt-4">This is not a bank balance. It includes funding for campaigns not yet ready for delivery. Completion does not require public impact publication. / هذا ليس رصيداً بنكياً ويشمل تمويل حملات لم تجهز للتسليم بعد. الإكمال لا يتطلب نشر الأثر العام.</p>
        </section>
    </div></div>
</x-app-layout>