<section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="conversion-heading">
    <h2 id="conversion-heading" class="text-lg font-semibold">Create Campaign draft / إنشاء مسودة حملة</h2>
    <p>Inherited category / الفئة الموروثة: {{ $assignedCategory->name_en }} / {{ $assignedCategory->name_ar }}</p>
    <p>Requested amount / المبلغ المطلوب: {{ $application->requested_amount }} SDG / ج.س</p>
    <p role="note" class="mt-4 bg-amber-50 p-4">Privacy warning: Write separate public content. Do not copy private or identifying information. / تنبيه الخصوصية: اكتب محتوى عاماً مستقلاً. لا تنسخ معلومات خاصة أو معلومات تحدد الهوية.</p>
    <form method="POST" action="{{ route('admin.help-applications.decided.convert-to-campaign', $application->reference) }}" class="mt-4 space-y-4">
        @csrf
        @foreach (['slug' => ['Slug / المعرّف', 160], 'title_en' => ['English title / العنوان الإنجليزي', 255], 'title_ar' => ['Arabic title / العنوان العربي', 255], 'target_amount' => ['Target amount (SDG) / المبلغ المستهدف', 19]] as $name => [$label, $maximum])
            @php($value = old($name, ''))
            <div><label for="{{ $name }}">{{ $label }}</label><input id="{{ $name }}" name="{{ $name }}" value="{{ is_string($value) ? $value : '' }}" required maxlength="{{ $maximum }}" @if ($name === 'target_amount') inputmode="decimal" @endif dir="{{ $name === 'title_ar' ? 'rtl' : 'ltr' }}" class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>@error($name)<p id="{{ $name }}-error" class="text-sm text-red-800">{{ $message }}</p>@enderror</div>
        @endforeach
        @foreach (['summary_en' => ['English summary / الملخص الإنجليزي', 1000, 'en', 'ltr'], 'summary_ar' => ['Arabic summary / الملخص العربي', 1000, 'ar', 'rtl'], 'story_en' => ['English public story / القصة العامة الإنجليزية', 20000, 'en', 'ltr'], 'story_ar' => ['Arabic public story / القصة العامة العربية', 20000, 'ar', 'rtl']] as $name => [$label, $maximum, $language, $direction])
            <div><label for="{{ $name }}">{{ $label }}</label><textarea id="{{ $name }}" name="{{ $name }}" required maxlength="{{ $maximum }}" lang="{{ $language }}" dir="{{ $direction }}" class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif></textarea>@error($name)<p id="{{ $name }}-error" class="text-sm text-red-800">{{ $message }}</p>@enderror</div>
        @endforeach
        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">Create Campaign draft / إنشاء مسودة حملة</button>
    </form>
</section>