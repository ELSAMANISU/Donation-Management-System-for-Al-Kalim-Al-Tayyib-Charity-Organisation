<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>{{ $locale === 'ar' ? 'التبرع التجريبي' : 'Sandbox donation' }}</title>
<style>body{font-family:system-ui,sans-serif;margin:0;background:#f6f8f7;color:#163343}main{max-width:720px;margin:2rem auto;padding:2rem;background:white;border-radius:16px}a{color:#146b55}label{display:block;margin-top:1rem}input{padding:.6rem;border:1px solid #687b85;border-radius:6px}button,.action{display:inline-block;padding:.8rem;margin:.6rem 0;background:#146b55;color:white;border:0;border-radius:6px;font:inherit}p{line-height:1.7}.warning{padding:1rem;border:2px solid #a85b08;background:#fff5dd}.error{color:#a01717}table{width:100%;border-collapse:collapse}td,th{padding:.6rem;text-align:start;border-bottom:1px solid #ddd}</style></head>
<body><main><nav><a href="{{ route('cases.index', ['locale'=>$locale]) }}">{{ $locale === 'ar' ? 'الحملات' : 'Campaigns' }}</a> @auth | <a href="{{ route('donations.index', ['locale'=>$locale]) }}">{{ $locale === 'ar' ? 'تبرعاتي' : 'My donations' }}</a> @endauth</nav>
<p class="warning" role="note">{{ $locale === 'ar' ? 'عرض جامعي تجريبي فقط. لا تتم أي معاملة مالية حقيقية. لا تدخل أي بيانات بطاقة أو بيانات دفع.' : 'Academic sandbox demonstration only. No real financial transaction occurs. Do not enter card or payment details.' }}</p>
@yield('content')
</main></body></html>
