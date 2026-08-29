<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Create Campaign Draft / <span lang="ar" dir="rtl">إنشاء مسودة حملة</span></h1></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-6 shadow-sm">
        <p class="mb-4 text-sm text-gray-700">Saving creates a draft only and does not publish it. / <span lang="ar" dir="rtl">الحفظ ينشئ مسودة فقط ولا ينشرها.</span></p>
        <p class="mb-6 text-sm text-amber-800">Use approved public-safe copy only. Do not include identity documents, contact details, or financial account information. / <span lang="ar" dir="rtl">استخدم نصاً عاماً معتمداً وآمناً فقط، دون وثائق هوية أو بيانات اتصال أو حسابات مالية.</span></p>
        @if ($categories->isEmpty())
            <div role="status" class="rounded-md bg-amber-50 p-4 text-sm text-amber-800">No active categories are available. A draft cannot be created. / <span lang="ar" dir="rtl">لا توجد فئات نشطة متاحة، ولا يمكن إنشاء مسودة.</span></div>
        @else
            @php
                $oldCategory = old('category_id');
                $oldCategory = is_string($oldCategory) || is_int($oldCategory) ? (string) $oldCategory : '';
            @endphp
            <form method="POST" action="{{ route('admin.campaigns.store') }}" class="space-y-5">@csrf
                @if ($errors->any())<div role="alert" class="rounded-md bg-red-50 p-4 text-sm text-red-800"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div>
                    <label for="category_id">Category / الفئة</label>
                    <select id="category_id" name="category_id" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" @if($errors->has('category_id')) aria-invalid="true" aria-describedby="category_id-error" @endif><option value="">Select / اختر</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($oldCategory === (string) $category->id)>{{ $category->name_en }} / {{ $category->name_ar }}</option>@endforeach</select>
                    @error('category_id')<p id="category_id-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                @foreach (['slug' => 'Slug / المعرّف', 'title_en' => 'English title / العنوان الإنجليزي', 'title_ar' => 'Arabic title / العنوان العربي'] as $name => $label)
                    @php $oldValue = old($name); $oldValue = is_string($oldValue) ? $oldValue : ''; $isArabic = $name === 'title_ar'; @endphp
                    <div><label for="{{ $name }}">{{ $label }}</label><input id="{{ $name }}" name="{{ $name }}" value="{{ $oldValue }}" required maxlength="{{ $name === 'slug' ? 160 : 255 }}" lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>@error($name)<p id="{{ $name }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                @endforeach
                @foreach (['summary_en' => 'English summary / الملخص الإنجليزي', 'summary_ar' => 'Arabic summary / الملخص العربي'] as $name => $label)
                    @php $oldValue = old($name); $oldValue = is_string($oldValue) ? $oldValue : ''; $isArabic = $name === 'summary_ar'; @endphp
                    <div><label for="{{ $name }}">{{ $label }}</label><textarea id="{{ $name }}" name="{{ $name }}" required maxlength="1000" lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>{{ $oldValue }}</textarea>@error($name)<p id="{{ $name }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                @endforeach
                @foreach (['story_en' => 'English public story / القصة العامة الإنجليزية', 'story_ar' => 'Arabic public story / القصة العامة العربية'] as $name => $label)
                    @php $oldValue = old($name); $oldValue = is_string($oldValue) ? $oldValue : ''; $isArabic = $name === 'story_ar'; @endphp
                    <div><label for="{{ $name }}">{{ $label }}</label><textarea id="{{ $name }}" name="{{ $name }}" required maxlength="20000" rows="6" lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>{{ $oldValue }}</textarea>@error($name)<p id="{{ $name }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                @endforeach
                @php $oldAmount = old('target_amount'); $oldAmount = is_string($oldAmount) || is_int($oldAmount) || is_float($oldAmount) ? (string) $oldAmount : ''; @endphp
                <div><label for="target_amount">Target amount (SDG) / المبلغ المستهدف (ج.س)</label><input id="target_amount" name="target_amount" inputmode="decimal" dir="ltr" value="{{ $oldAmount }}" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" @if($errors->has('target_amount')) aria-invalid="true" aria-describedby="target_amount-error" @endif>@error('target_amount')<p id="target_amount-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                <button class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Save Draft / حفظ المسودة</button>
            </form>
        @endif
    </div></div></div>
</x-app-layout>
