<section class="mt-10 border-t border-gray-200 pt-8" aria-labelledby="publication-heading">
    <h2 id="publication-heading" class="text-lg font-semibold">Publish Campaign / <span lang="ar" dir="rtl">نشر الحملة</span></h2>
    <p class="mt-3 text-sm">Saving edits or uploading an image does not publish. / حفظ التعديلات أو رفع صورة لا ينشر الحملة.</p>
    @if ($publicationMissing === [])
        <p id="publication-warning" class="mt-3 text-sm">Confirm before publishing: successful publication makes this Campaign public. / تأكد قبل النشر: النشر الناجح يجعل هذه الحملة متاحة للجمهور.</p>
        <form method="POST" action="{{ route('admin.campaigns.publish', $campaign) }}" class="mt-5 space-y-4" aria-describedby="publication-warning">
            @csrf
            <label for="expires_at">Expiration date and time / تاريخ ووقت الانتهاء ({{ config('app.timezone') }})</label>
            <input id="expires_at" name="expires_at" type="datetime-local" required step="1" min="{{ now()->addSecond()->format('Y-m-d\TH:i:s') }}" value="{{ is_string(old('expires_at')) ? old('expires_at') : '' }}" class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" aria-describedby="expiration-help{{ $errors->publication->has('expires_at') ? ' expiration-error' : '' }}" @if($errors->publication->has('expires_at')) aria-invalid="true" @endif>
            <p id="expiration-help">Choose a future time. / اختر وقتاً في المستقبل.</p>
            @error('expires_at', 'publication')<p id="expiration-error" role="alert">{{ $message }}</p>@enderror
            <button class="rounded bg-indigo-600 px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">Publish Campaign / نشر الحملة</button>
        </form>
    @else
        <p class="mt-3">Complete these requirements before publication: / أكمل هذه المتطلبات قبل النشر:</p>
        @php $labels = [
            'title_ar' => 'Arabic title / العنوان العربي', 'title_en' => 'English title / العنوان الإنجليزي',
            'summary_ar' => 'Arabic summary / الملخص العربي', 'summary_en' => 'English summary / الملخص الإنجليزي',
            'story_ar' => 'Arabic public story / القصة العامة بالعربية', 'story_en' => 'English public story / القصة العامة بالإنجليزية',
            'image_alt_ar' => 'Arabic image description / وصف الصورة بالعربية', 'image_alt_en' => 'English image description / وصف الصورة بالإنجليزية',
            'slug' => 'Valid Campaign slug / معرّف حملة صالح', 'target_amount' => 'Positive target amount / مبلغ مستهدف موجب',
            'category' => 'Active Category / فئة نشطة', 'image' => 'Valid stored Campaign image / صورة حملة محفوظة وصالحة',
            'lifecycle' => 'Consistent unpublished draft state / حالة مسودة غير منشورة ومتسقة',
            'application' => 'Coherent linked application history / سجل طلب مرتبط متسق',
        ]; @endphp
        <ul>@foreach($publicationMissing as $requirement)<li>{{ $labels[$requirement] }}</li>@endforeach</ul>
    @endif
</section>