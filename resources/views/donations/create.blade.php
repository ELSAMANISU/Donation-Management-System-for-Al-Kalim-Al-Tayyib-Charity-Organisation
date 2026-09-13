@extends('donations.layout')
@section('content')
<h1>{{ $case->{'title_'.$locale} }}</h1>
<p>{{ $locale === 'ar' ? 'المتبقي' : 'Remaining' }}: {{ \App\Services\DonationMoney::remaining($case) }} {{ $locale === 'ar' ? 'ج.س' : 'SDG' }}</p>
@if($errors->any())<div class="error" role="alert" id="donation-errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="post" action="{{ route('donations.store', ['locale'=>$locale, 'campaign'=>$case->slug]) }}">
@csrf
<input type="hidden" name="idempotency_token" value="{{ $idempotencyToken }}">
<label for="amount">{{ $locale === 'ar' ? 'المبلغ بالجنيه السوداني' : 'Amount in SDG' }}</label>
<input id="amount" name="amount" type="text" inputmode="decimal" required maxlength="19" aria-describedby="amount-help{{ $errors->any() ? ' donation-errors' : '' }}" @error('amount') aria-invalid="true" @enderror>
<p id="amount-help">{{ $locale === 'ar' ? 'أدخل مبلغاً أكبر من صفر لا يتجاوز المتبقي باستخدام الأرقام 0–9 ونقطة عشرية.' : 'Use digits 0–9 and a decimal point. The amount must be positive and no greater than the remaining target.' }}</p>
@auth<label for="anonymous"><select id="anonymous" name="anonymous" required><option value="0">{{ $locale === 'ar' ? 'لا' : 'No' }}</option><option value="1">{{ $locale === 'ar' ? 'نعم' : 'Yes' }}</option></select> {{ $locale === 'ar' ? 'تبرع مجهول الاسم (يمكن للمسؤولين التعرف على الحساب)' : 'Donate anonymously (authorized administrators can still identify the account)' }}</label>@endauth
<button type="submit">{{ $locale === 'ar' ? 'متابعة إلى الدفع التجريبي' : 'Continue to sandbox checkout' }}</button>
</form>
@endsection
