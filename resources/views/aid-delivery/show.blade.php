<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Private sandbox aid delivery / <span lang="ar" dir="rtl">التسليم التجريبي الخاص للمساعدة</span></h1></x-slot>
    @php
        $prefix = $administrator ? 'admin.aid-delivery.' : 'help-applications.aid-delivery.';
        $params = ['helpApplication' => $application->reference, 'coordination' => $coordination->reference];
    @endphp
    <main class="max-w-4xl mx-auto py-8 px-4 space-y-6">
        <div id="sandbox-warning" role="note" class="rounded border border-amber-300 bg-amber-50 p-4">
            <p lang="en">Academic sandbox — no real financial transaction or aid transfer occurred.</p>
            <p lang="ar" dir="rtl">بيئة أكاديمية تجريبية — لم تحدث أي معاملة مالية أو عملية تسليم مساعدة حقيقية.</p>
            <p lang="en">Use synthetic demonstration values only. No uploads or real receiving credentials.</p>
            <p lang="ar" dir="rtl">استخدم قيماً تجريبية فقط. لا ترفع مستندات أو بيانات استلام حقيقية.</p>
        </div>
        <a class="text-indigo-700 underline" href="{{ route($administrator ? 'admin.coordination.show' : 'help-applications.coordination.show', $params) }}">Private coordination history / <span lang="ar" dir="rtl">سجل التنسيق الخاص</span></a>
        @if($errors->delivery->any())
            <p role="alert" class="text-red-700">{{ $errors->delivery->first() }}</p>
        @endif
        <dl class="grid gap-4 sm:grid-cols-2 bg-white p-4 rounded shadow">
            <div><dt>Simulated delivered / تم تسليمه بالمحاكاة</dt><dd>{{ $delivered }} SDG / ج.س</dd></div>
            <div><dt>Undelivered balance / الرصيد غير المسلّم</dt><dd>{{ $remaining }} SDG / ج.س</dd></div>
        </dl>
        @if($startToken)
            <form method="POST" action="{{ route('admin.aid-delivery.start', $params) }}" class="bg-white p-4 rounded space-y-4" aria-describedby="sandbox-warning">
                @csrf
                <input type="hidden" name="idempotency_token" value="{{ $startToken }}">
                <input type="hidden" name="currency" value="SDG">
                <label for="amount" class="block">Instalment amount (SDG) / <span lang="ar" dir="rtl">مبلغ الدفعة (ج.س)</span></label>
                <input id="amount" name="amount" type="text" inputmode="decimal" maxlength="19" required class="rounded border-gray-300 w-full">
                <button class="rounded bg-indigo-700 text-white px-4 py-2">Start sandbox delivery / بدء التسليم التجريبي</button>
            </form>
        @endif
        @forelse($deliveries as $delivery)
            @php($deliveryParams = $params + ['delivery' => $delivery->reference])
            <article class="bg-white rounded shadow p-4 space-y-4" aria-labelledby="delivery-{{ $loop->index }}">
                <h2 id="delivery-{{ $loop->index }}" class="font-semibold">{{ $delivery->state->label() }}</h2>
                <p>{{ $delivery->amount }} SDG / ج.س</p>
                <p class="break-all"><a class="text-indigo-700 underline" href="{{ route($prefix.'show', $deliveryParams) }}">{{ $delivery->reference }}</a></p>
                <ol class="space-y-2">
                    @foreach($transitions->where('delivery_id', $delivery->id) as $transition)
                        <li class="border-s-2 border-gray-300 ps-3">
                            <p>{{ $transition->state->label() }} — {{ $transition->created_at->format('Y-m-d H:i:s') }}</p>
                            @if($administrator && $transition->note !== null)<p dir="auto" class="whitespace-pre-wrap break-words">{{ $transition->note }}</p>@endif
                        </li>
                    @endforeach
                </ol>
                @if($proof = $proofs->firstWhere('delivery_id', $delivery->id))
                    <section class="rounded border p-4 space-y-2" aria-label="Private sandbox proof / إثبات تجريبي خاص">
                        <h3 class="font-semibold">System-generated sandbox proof / إثبات تجريبي مولّد من النظام</h3>
                        <p class="break-all">{{ $proof->reference }}</p>
                        <p class="break-all">{{ $proof->sandbox_reference }}</p>
                        <p>Simulated outcome / نتيجة بالمحاكاة — {{ $proof->created_at->format('Y-m-d H:i:s') }}</p>
                        <p lang="en">Academic sandbox — no real financial transaction or aid transfer occurred.</p>
                        <p lang="ar" dir="rtl">بيئة أكاديمية تجريبية — لم تحدث أي معاملة مالية أو عملية تسليم مساعدة حقيقية.</p>
                    </section>
                @endif
                @foreach($tokens[$delivery->reference] ?? [] as $action => $token)
                    <form method="POST" action="{{ route('admin.aid-delivery.'.$action, $deliveryParams) }}" class="space-y-3" aria-describedby="sandbox-warning">
                        @csrf
                        <input type="hidden" name="idempotency_token" value="{{ $token }}">
                        <input type="hidden" name="revision" value="{{ $delivery->revision }}">
                        @if($action === 'problem')
                            <label for="problem-{{ $delivery->reference }}" class="block">Private synthetic problem explanation (required) / شرح تجريبي خاص للمشكلة (مطلوب)</label>
                            <textarea id="problem-{{ $delivery->reference }}" name="note" rows="3" maxlength="2000" required dir="auto" class="rounded border-gray-300 w-full"></textarea>
                        @endif
                        <button class="rounded border border-indigo-600 text-indigo-800 px-4 py-2">{{ match($action) { 'problem' => 'Record problem / تسجيل مشكلة', 'resume' => 'Resume / استئناف', 'success' => 'Mark simulated delivered / تسجيل التسليم بالمحاكاة' } }}</button>
                    </form>
                @endforeach
            </article>
        @empty
            <p>No sandbox instalments yet. / لا توجد دفعات تجريبية بعد.</p>
        @endforelse
        @if(!$administrator)<p>Read-only history / سجل للقراءة فقط</p>@endif
        <p>Completion and public impact publishing are deferred. / الإكمال ونشر الأثر العام مؤجلان.</p>
    </main>
</x-app-layout>
