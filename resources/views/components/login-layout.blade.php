<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Sign In') }} — {{ config('app.name', 'Al-Kalimah Foundation') }}</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@300;400;600;700;900&family=Montserrat:wght@300;400;600;700;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        :root {
            --c-amber:       #cd9053;
            --c-amber-dark:  #b37540;
            --c-amber-glow:  rgba(205, 144, 83, 0.18);
            --c-white:       #ffffff;
            --c-slate-900:   #0f172a;
            --c-slate-700:   #334155;
            --c-slate-600:   #475569;
            --c-slate-500:   #64748b;
            --c-slate-400:   #94a3b8;
            --c-slate-300:   #cbd5e1;
            --c-slate-200:   #e2e8f0;
            --font-main:     'Montserrat', sans-serif;
            --font-arabic:   'Cairo', sans-serif;
        }
        html[lang="ar"] { --font-main: 'Cairo', sans-serif; }

        *, *::before, *::after { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            height: 100vh;
            font-family: var(--font-main);
            background: var(--c-white);
            color: var(--c-slate-900);
        }

        body {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ════════════════════════════════════════════
           GLOBAL HEADER — unified top-center brand
        ════════════════════════════════════════════ */
        .ls-global-header {
            flex-shrink: 0;
            height: 84px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: var(--c-white);
            border-bottom: 1px solid var(--c-slate-200);
            padding: 0 1.5rem;
        }

        .ls-brand {
            text-align: center;
            text-decoration: none;
        }
        .ls-brand-name {
            font-family: var(--font-main);
            font-size: 1.45rem;
            font-weight: 700;
            color: var(--c-slate-900);
            display: block;
            line-height: 1.2;
            letter-spacing: -0.2px;
        }
        html[lang="ar"] .ls-brand-name {
            font-family: var(--font-arabic);
            font-size: 1.65rem;
        }
        .ls-brand-accent { color: var(--c-amber); }
        .ls-brand-sub {
            font-size: 0.63rem;
            color: var(--c-slate-400);
            letter-spacing: 2.8px;
            text-transform: uppercase;
            display: block;
            margin-top: 4px;
        }
        html[lang="ar"] .ls-brand-sub {
            letter-spacing: 0;
            font-size: 0.73rem;
            text-transform: none;
        }

        /* Language Switcher */
        .ls-lang-switcher {
            position: absolute;
            right: 1.75rem;
            top: 50%;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: 1.5px solid var(--c-slate-200);
            border-radius: 7px;
            padding: 5px 12px;
            text-decoration: none;
            transition: border-color 0.22s ease, box-shadow 0.22s ease;
        }
        html[dir="rtl"] .ls-lang-switcher {
            right: auto;
            left: 1.75rem;
        }
        .ls-lang-switcher:hover {
            border-color: var(--c-amber);
            box-shadow: 0 0 0 3px var(--c-amber-glow);
        }
        .ls-lang-btn {
            font-family: var(--font-main);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.8px;
            color: var(--c-slate-500);
            text-decoration: none;
            transition: color 0.2s;
            cursor: pointer;
        }
        .ls-lang-btn:hover { color: var(--c-amber); }
        .ls-lang-btn.ls-active {
            color: var(--c-amber);
            font-weight: 700;
        }
        .ls-lang-sep {
            font-size: 0.68rem;
            color: var(--c-slate-300);
            user-select: none;
            line-height: 1;
        }

        /* ════════════════════════════════════════════
           SPLIT-SCREEN SHELL
        ════════════════════════════════════════════ */
        .ls-root {
            flex: 1;
            display: flex;
            overflow: hidden;
            min-height: 0;
        }

        .ls-divider {
            width: 1px;
            flex-shrink: 0;
            background: var(--c-slate-200);
        }

        /* ════════════════════════════════════════════
           LEFT — Auth Form Panel
        ════════════════════════════════════════════ */
        .ls-form-panel {
            width: 50%;
            flex-shrink: 0;
            background: var(--c-white);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 3.5rem;
            overflow-y: auto;
        }

        .ls-form-inner {
            width: 100%;
            max-width: 420px;
        }

        .ls-heading {
            font-family: var(--font-main);
            font-size: 1.9rem;
            font-weight: 700;
            color: var(--c-slate-900);
            line-height: 1.2;
            margin: 0 0 0.45rem;
        }
        html[lang="ar"] .ls-heading {
            font-family: var(--font-arabic);
            font-size: 2rem;
        }
        .ls-subheading {
            font-size: 0.86rem;
            color: var(--c-slate-500);
            margin: 0 0 1.35rem;
            line-height: 1.55;
        }
        .ls-heading-rule {
            width: 38px;
            height: 3px;
            background: var(--c-amber);
            border-radius: 2px;
            margin-bottom: 1.6rem;
        }
        html[dir="rtl"] .ls-heading-rule { margin-right: 0; margin-left: auto; }

        /* Labels */
        .ls-form-inner label {
            font-family: var(--font-main) !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            color: var(--c-slate-700) !important;
            letter-spacing: 0.5px !important;
            text-transform: uppercase !important;
            display: block !important;
            margin-bottom: 0.35rem !important;
        }
        html[lang="ar"] .ls-form-inner label {
            font-family: var(--font-arabic) !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
        }

        /* Text inputs */
        .ls-form-inner input[type="email"],
        .ls-form-inner input[type="password"],
        .ls-form-inner input[type="text"] {
            appearance: none;
            background: var(--c-white) !important;
            border: 1.5px solid var(--c-slate-200) !important;
            border-radius: 8px !important;
            color: var(--c-slate-900) !important;
            font-family: var(--font-main) !important;
            font-size: 0.9rem !important;
            padding: 0.7rem 1rem !important;
            width: 100% !important;
            box-shadow: none !important;
            outline: none !important;
            transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
            --tw-ring-shadow: 0 0 #0000 !important;
            --tw-ring-offset-shadow: 0 0 #0000 !important;
        }
        .ls-form-inner input[type="email"]::placeholder,
        .ls-form-inner input[type="password"]::placeholder {
            color: var(--c-slate-400) !important;
        }
        .ls-form-inner input[type="email"]:focus,
        .ls-form-inner input[type="password"]:focus,
        .ls-form-inner input[type="text"]:focus {
            border-color: var(--c-amber) !important;
            box-shadow: 0 0 0 3px var(--c-amber-glow) !important;
            --tw-ring-shadow: 0 0 0 3px var(--c-amber-glow) !important;
            --tw-ring-offset-shadow: 0 0 #0000 !important;
            outline: none !important;
        }

        /* Checkbox */
        .ls-form-inner input[type="checkbox"] {
            cursor: pointer;
            accent-color: var(--c-amber);
        }
        .ls-remember-text {
            font-size: 0.8rem !important;
            color: var(--c-slate-500) !important;
            font-weight: 400 !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
        }

        /* Forgot link */
        .ls-forgot {
            font-size: 0.79rem;
            color: var(--c-amber) !important;
            font-weight: 500;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .ls-forgot:hover { opacity: 0.7; text-decoration: underline; }

        /* Submit button */
        .ls-btn-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            background: var(--c-amber) !important;
            color: #ffffff !important;
            font-family: var(--font-main) !important;
            font-size: 0.92rem !important;
            font-weight: 700 !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 0.78rem 1.5rem !important;
            cursor: pointer;
            letter-spacing: 0.4px;
            box-shadow: 0 4px 16px rgba(205, 144, 83, 0.36) !important;
            transition: background-color 0.2s ease, transform 0.18s ease, box-shadow 0.2s ease !important;
        }
        .ls-btn-submit:hover {
            background: var(--c-amber-dark) !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 6px 22px rgba(205, 144, 83, 0.48) !important;
        }
        .ls-btn-submit:active { transform: translateY(0) !important; }

        /* Validation errors */
        .ls-form-inner .text-sm.text-red-600,
        .ls-form-inner p.text-red-600,
        .ls-form-inner span.text-red-600 {
            color: #dc2626 !important;
            font-size: 0.74rem !important;
        }
        .ls-form-inner .text-green-600,
        .ls-form-inner p.text-green-600 {
            color: #16a34a !important;
            font-size: 0.82rem !important;
        }

        /* Back link */
        .ls-back {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: var(--c-slate-400);
            font-size: 0.74rem;
            text-decoration: none;
            transition: color 0.2s;
        }
        .ls-back:hover { color: var(--c-amber); }

        /* ════════════════════════════════════════════
           RIGHT — Image Carousel Panel
        ════════════════════════════════════════════ */
        .ls-feature-panel {
            width: 50%;
            flex-shrink: 0;
            background: var(--c-white);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 2.5rem;
            overflow: hidden;
        }

        .ls-feature-card {
            width: 100%;
            max-width: 460px;
            height: calc(100vh - 84px - 4rem);
            border: 2px solid var(--c-amber);
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow:
                0 0 0 7px rgba(205, 144, 83, 0.06),
                0 12px 48px rgba(205, 144, 83, 0.14);
        }

        /* Carousel image container */
        .ls-carousel {
            flex: 1;
            position: relative;
            overflow: hidden;
            background: #ede8df;
            min-height: 0;
        }

        .ls-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 0.75s ease;
        }
        .ls-slide.ls-active { opacity: 1; }

        .ls-slide-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }

        /* Amber bottom banner */
        .ls-feat-banner {
            background: var(--c-amber);
            padding: 1.05rem 2rem;
            min-height: 62px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }
        .ls-banner-text {
            font-family: var(--font-arabic);
            font-size: 1.12rem;
            font-weight: 700;
            color: #ffffff;
            text-align: center;
            direction: rtl;
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 1.5rem;
            opacity: 0;
            transition: opacity 0.6s ease;
            pointer-events: none;
        }
        .ls-banner-text.ls-active { opacity: 1; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            body { overflow: auto; }
            .ls-root { flex-direction: column; flex: none; }
            .ls-form-panel {
                width: 100%;
                min-height: calc(100vh - 84px);
                overflow-y: visible;
                padding: 2rem 1.5rem;
            }
            .ls-divider,
            .ls-feature-panel { display: none; }
        }

        /* ── RTL ── */
        html[dir="rtl"] .ls-form-inner  { text-align: right; }
        html[dir="rtl"] .ls-heading     { text-align: right; }
        html[dir="rtl"] .ls-subheading  { text-align: right; }
        html[dir="rtl"] .ls-back        { text-align: right; }
    </style>
</head>
<body>

{{-- ═══ GLOBAL HEADER — top-center brand identity ═══ --}}
<header class="ls-global-header">

    <a href="{{ url('/') }}" class="ls-brand">
        <span class="ls-brand-name">
            @if(app()->getLocale() === 'ar')
                مؤسسة <span class="ls-brand-accent">الكلم الطيب</span>
            @else
                Al-Kalimah<span class="ls-brand-accent">.</span>Foundation
            @endif
        </span>
        <span class="ls-brand-sub">
            @if(app()->getLocale() === 'ar')
                للخير والعطاء
            @else
                For Good &amp; Giving
            @endif
        </span>
    </a>

    {{-- Language Switcher --}}
    @php $currentLocale = app()->getLocale(); @endphp
    <div class="ls-lang-switcher">
        @if($currentLocale === 'ar')
            <a href="{{ url()->current() }}?lang=en" class="ls-lang-btn">EN</a>
            <span class="ls-lang-sep">|</span>
            <span class="ls-lang-btn ls-active">AR</span>
        @else
            <span class="ls-lang-btn ls-active">EN</span>
            <span class="ls-lang-sep">|</span>
            <a href="{{ url()->current() }}?lang=ar" class="ls-lang-btn">AR</a>
        @endif
    </div>

</header>

{{-- ═══ SPLIT-SCREEN BODY ═══ --}}
<div class="ls-root">

    {{-- LEFT: Auth Form Panel --}}
    <div class="ls-form-panel">
        <div class="ls-form-inner">

            <h1 class="ls-heading">
                @if(app()->getLocale() === 'ar')
                    مرحباً بعودتك
                @else
                    Welcome Back
                @endif
            </h1>
            <p class="ls-subheading">
                @if(app()->getLocale() === 'ar')
                    سجّل دخولك لمواصلة رحلة العطاء والخير
                @else
                    Sign in to continue your journey of giving
                @endif
            </p>
            <div class="ls-heading-rule"></div>

            {{-- Form slot (includes @csrf, fields, errors, remember, submit) --}}
            {{ $slot }}

            <a href="{{ url('/') }}" class="ls-back">
                @if(app()->getLocale() === 'ar')
                    ← العودة إلى الرئيسية
                @else
                    ← Back to home
                @endif
            </a>

        </div>
    </div>

    {{-- Thin separator --}}
    <div class="ls-divider"></div>

    {{-- RIGHT: Image Carousel Panel --}}
    <div class="ls-feature-panel" aria-hidden="true">
        <div class="ls-feature-card">

            {{-- Image carousel --}}
            <div class="ls-carousel">
                <div class="ls-slide ls-active">
                    <img src="{{ asset('images/quran.jpg') }}"
                         alt="توزيع المصحف الشريف"
                         class="ls-slide-img">
                </div>
                <div class="ls-slide">
                    <img src="{{ asset('images/orphans.jpg') }}"
                         alt="كفالة الأيتام والأسر المحتاجة"
                         class="ls-slide-img">
                </div>
                <div class="ls-slide">
                    <img src="{{ asset('images/relief.jpg') }}"
                         alt="إغاثة المتضررين ومشاريع التنمية"
                         class="ls-slide-img">
                </div>
            </div>

            {{-- Synced amber banner --}}
            <div class="ls-feat-banner">
                <span class="ls-banner-text ls-active">توزيع المصحف الشريف</span>
                <span class="ls-banner-text">كفالة الأيتام والأسر المحتاجة</span>
                <span class="ls-banner-text">إغاثة المتضررين ومشاريع التنمية</span>
            </div>

        </div>
    </div>

</div>

<script>
(function () {
    'use strict';
    var slides  = document.querySelectorAll('.ls-slide');
    var banners = document.querySelectorAll('.ls-banner-text');
    var total   = slides.length;
    var current = 0;

    function tick() {
        slides[current].classList.remove('ls-active');
        banners[current].classList.remove('ls-active');
        current = (current + 1) % total;
        slides[current].classList.add('ls-active');
        banners[current].classList.add('ls-active');
    }

    setInterval(tick, 4000);
}());
</script>
</body>
</html>
