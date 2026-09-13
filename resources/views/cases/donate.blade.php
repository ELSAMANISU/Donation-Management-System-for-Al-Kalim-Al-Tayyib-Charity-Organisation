@if($case->status === \App\Enums\CampaignStatus::Funded)
<p role="status">{{ $locale === 'ar' ? 'اكتمل تمويل الحملة.' : 'Campaign fully funded.' }}</p>
@elseif(\App\Services\DonationMoney::eligible($case) && config('donations.driver') === 'sandbox')
<a class="btn-details" href="{{ route('donations.create', ['locale'=>$locale, 'campaign'=>$case->slug]) }}">{{ $locale === 'ar' ? 'تبرع الآن' : 'Donate now' }}</a>
@endif
