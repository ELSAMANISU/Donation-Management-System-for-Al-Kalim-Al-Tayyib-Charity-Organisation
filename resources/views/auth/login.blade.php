@php $loc = app()->getLocale(); @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $loc) }}"
      dir="{{ $loc === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $loc === 'ar' ? 'تسجيل الدخول' : 'Sign In' }} — {{ config('app.name', 'Al-Kalimah Foundation') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&family=Montserrat:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* ── Design Tokens ── */
        :root {
            --amber:       #cd9053;
            --amber-dark:  #b37540;
            --amber-glow:  rgba(205, 144, 83, .22);
            --amber-pale:  rgba(205, 144, 83, .08);
            --white:       #ffffff;
            --linen:       #fafaf9;
            --s900:        #0f172a;
            --s700:        #334155;
            --s500:        #64748b;
            --s400:        #94a3b8;
            --s300:        #cbd5e1;
            --s200:        #e2e8f0;
            --f-en:        'Montserrat', sans-serif;
            --f-ar:        'Cairo', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { height: 100%; }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: var(--f-en);
            color: var(--s900);
            overflow-x: hidden;
        }
        html[lang="ar"] body { font-family: var(--f-ar); }

        /* ═══════════════════════════════════════
           SCENIC BACKGROUND
        ═══════════════════════════════════════ */
        .ls-bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            /* Warm gradient: pure white → warm linen → rich honey amber base */
            background: linear-gradient(
                168deg,
                #ffffff   0%,
                #fdfcf9   18%,
                #faf4eb   38%,
                #f4e8d4   58%,
                #ead8b8   76%,
                #dfc89a   90%,
                #d4b882  100%
            );
            overflow: hidden;
        }

        /* Islamic geometric star watermark */
        .ls-bg::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: .040;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='84' height='84' viewBox='0 0 84 84'%3E%3Cg fill='none' stroke='%23cd9053' stroke-width='0.65'%3E%3Cpolygon points='42,4 47,21.5 64,16.5 54.5,31 71.5,35.5 54.5,40 64,54.5 47,49.5 42,67 37,49.5 20,54.5 29.5,40 12.5,35.5 29.5,31 20,16.5 37,21.5'/%3E%3Crect x='28.5' y='28.5' width='27' height='27' rx='1.5' transform='rotate(45 42 42)'/%3E%3Ccircle cx='42' cy='42' r='7.5' stroke-width='0.5'/%3E%3C/g%3E%3C/svg%3E");
            background-size: 84px 84px;
            pointer-events: none;
        }

        /* Radial ambient warm glow centred on the card area */
        .ls-bg::after {
            content: '';
            position: absolute;
            top: 52%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 720px;
            height: 520px;
            border-radius: 50%;
            background: radial-gradient(ellipse, rgba(205, 144, 83, .12) 0%, transparent 72%);
            pointer-events: none;
        }

        /* Islamic architecture horizon silhouette */
        .ls-bg-silhouette {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 56%;
            max-height: 380px;
        }

        /* ═══════════════════════════════════════
           NAVBAR HEADER
        ═══════════════════════════════════════ */
        .ls-header {
            position: relative;
            z-index: 30;
            flex-shrink: 0;
            height: 82px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 1.75rem;
            background: rgba(255, 255, 255, .72);
            backdrop-filter: blur(18px) saturate(140%);
            -webkit-backdrop-filter: blur(18px) saturate(140%);
            border-bottom: 1px solid rgba(205, 144, 83, .18);
            box-shadow: 0 1px 16px rgba(0, 0, 0, .05);
        }

        /* Brand lockup */
        .ls-brand {
            display: flex;
            align-items: center;
            gap: 13px;
            text-decoration: none;
            color: inherit;
        }
        .ls-mosque-icon {
            width: 44px;
            height: auto;
            color: var(--amber);
            flex-shrink: 0;
        }
        .ls-brand-text {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .ls-brand-ar {
            font-family: var(--f-ar);
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--s900);
            line-height: 1.2;
        }
        .ls-brand-en {
            font-family: var(--f-en);
            font-size: 0.70rem;
            font-weight: 600;
            letter-spacing: 0.4px;
            color: var(--s500);
        }
        .ls-accent { color: var(--amber); }

        /* Language switcher pill */
        .ls-lang {
            position: absolute;
            right: 1.75rem;
            top: 50%;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1.5px solid rgba(205, 144, 83, .30);
            border-radius: 9px;
            padding: 6px 15px;
            background: rgba(255, 255, 255, .55);
            text-decoration: none;
            transition: border-color .22s, box-shadow .22s, background .22s;
        }
        html[dir="rtl"] .ls-lang { right: auto; left: 1.75rem; }
        .ls-lang:hover {
            border-color: var(--amber);
            background: rgba(255, 255, 255, .90);
            box-shadow: 0 0 0 3px var(--amber-glow);
        }
        .ls-lang-btn {
            font-family: var(--f-en);
            font-size: 0.69rem;
            font-weight: 600;
            letter-spacing: 0.9px;
            color: var(--s500);
            text-decoration: none;
            transition: color .2s;
            cursor: pointer;
        }
        .ls-lang-btn:hover, .ls-lang-btn.on { color: var(--amber); font-weight: 700; }
        .ls-lang-sep { font-size: 0.68rem; color: var(--s300); user-select: none; }

        /* ═══════════════════════════════════════
           MAIN — centres the glass card
        ═══════════════════════════════════════ */
        .ls-main {
            position: relative;
            z-index: 20;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.25rem;
        }

        /* ═══════════════════════════════════════
           GLASSMORPHISM LOGIN CARD
        ═══════════════════════════════════════ */
        .ls-card {
            width: 100%;
            max-width: 458px;
            background: rgba(255, 255, 255, .50);
            backdrop-filter: blur(30px) saturate(175%);
            -webkit-backdrop-filter: blur(30px) saturate(175%);
            border: 1.5px solid rgba(205, 144, 83, .28);
            border-radius: 22px;
            padding: 2.8rem 3.1rem;
            box-shadow:
                0 14px 56px rgba(0, 0, 0, .09),
                0 4px 12px rgba(0, 0, 0, .05),
                inset 0 1px 0 rgba(255, 255, 255, .88),
                0 0 0 0.5px rgba(205, 144, 83, .10);
        }

        /* Eyebrow label */
        .ls-eyebrow {
            display: block;
            font-size: 0.60rem;
            font-weight: 700;
            letter-spacing: 3.2px;
            text-transform: uppercase;
            color: var(--amber);
            margin-bottom: 0.52rem;
        }
        html[lang="ar"] .ls-eyebrow {
            letter-spacing: 0;
            text-transform: none;
            font-family: var(--f-ar);
            font-size: 0.76rem;
        }

        /* Main heading */
        .ls-heading {
            font-family: var(--f-en);
            font-size: 1.88rem;
            font-weight: 800;
            color: var(--s900);
            line-height: 1.13;
            margin-bottom: 0.36rem;
        }
        html[lang="ar"] .ls-heading {
            font-family: var(--f-ar);
            font-size: 2rem;
        }

        /* Sub-heading */
        .ls-subheading {
            font-size: 0.82rem;
            color: var(--s500);
            line-height: 1.65;
            margin-bottom: 1.2rem;
        }

        /* Amber rule divider */
        .ls-rule {
            width: 34px;
            height: 3px;
            background: var(--amber);
            border-radius: 2px;
            margin-bottom: 1.75rem;
        }
        html[dir="rtl"] .ls-rule { margin-right: 0; margin-left: auto; }

        /* Session flash */
        .ls-flash {
            background: rgba(240, 253, 244, .88);
            border: 1px solid #bbf7d0;
            border-radius: 9px;
            color: #15803d;
            font-size: 0.81rem;
            padding: 0.62rem 0.95rem;
            margin-bottom: 1.1rem;
        }

        /* ── Form fields ── */
        .ls-field { margin-bottom: 1.15rem; }

        .ls-label {
            display: block;
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 0.55px;
            text-transform: uppercase;
            color: var(--s700);
            margin-bottom: 0.36rem;
        }
        html[lang="ar"] .ls-label {
            letter-spacing: 0;
            text-transform: none;
            font-family: var(--f-ar);
            font-size: 0.77rem;
        }

        .ls-input {
            appearance: none;
            width: 100%;
            background: rgba(255, 255, 255, .80);
            border: 1.5px solid var(--s200);
            border-radius: 10px;
            color: var(--s900);
            font-family: var(--f-en);
            font-size: 0.90rem;
            padding: 0.76rem 1.05rem;
            outline: none;
            transition: border-color .22s, box-shadow .22s, background .22s;
        }
        html[lang="ar"] .ls-input {
            font-family: var(--f-ar);
            direction: ltr;
            text-align: left;
        }
        .ls-input::placeholder { color: var(--s400); }
        .ls-input:focus {
            background: rgba(255, 255, 255, .97);
            border-color: var(--amber);
            box-shadow: 0 0 0 3.5px var(--amber-glow);
        }

        .ls-err {
            display: block;
            font-size: 0.72rem;
            color: #dc2626;
            margin-top: 0.32rem;
        }

        /* Remember + Forgot row */
        .ls-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .ls-remember {
            display: inline-flex;
            align-items: center;
            gap: 0.46rem;
            cursor: pointer;
        }
        .ls-remember input[type="checkbox"] {
            accent-color: var(--amber);
            cursor: pointer;
            width: 15px;
            height: 15px;
            flex-shrink: 0;
        }
        .ls-remember-label {
            font-size: 0.80rem;
            color: var(--s500);
            cursor: pointer;
        }

        .ls-forgot {
            font-size: 0.79rem;
            font-weight: 600;
            color: var(--amber);
            text-decoration: none;
            transition: opacity .2s;
        }
        .ls-forgot:hover { opacity: .68; text-decoration: underline; }

        /* Submit button — solid amber block */
        .ls-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.52rem;
            width: 100%;
            background: var(--amber);
            color: #fff;
            font-family: var(--f-en);
            font-size: 0.93rem;
            font-weight: 700;
            border: none;
            border-radius: 10px;
            padding: 0.88rem 1.5rem;
            cursor: pointer;
            letter-spacing: 0.35px;
            box-shadow: 0 5px 22px rgba(205, 144, 83, .40);
            transition: background .22s, transform .18s, box-shadow .22s;
        }
        html[lang="ar"] .ls-submit { font-family: var(--f-ar); }
        .ls-submit:hover {
            background: var(--amber-dark);
            transform: translateY(-2px);
            box-shadow: 0 9px 30px rgba(205, 144, 83, .55);
        }
        .ls-submit:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(205, 144, 83, .28);
        }

        /* Back link */
        .ls-back {
            display: block;
            text-align: center;
            margin-top: 1.55rem;
            font-size: 0.73rem;
            color: var(--s400);
            text-decoration: none;
            transition: color .2s;
        }
        .ls-back:hover { color: var(--amber); }
        html[dir="rtl"] .ls-back { text-align: right; }

        /* ── Responsive ── */
        @media (max-width: 520px) {
            .ls-header  { height: 70px; padding: 0 1.1rem; }
            .ls-lang    { right: 1.1rem; }
            html[dir="rtl"] .ls-lang { left: 1.1rem; }
            .ls-card    { padding: 2rem 1.6rem; border-radius: 18px; }
            .ls-heading { font-size: 1.62rem; }
        }

        /* ── RTL card alignment ── */
        html[dir="rtl"] .ls-card { text-align: right; }
    </style>
</head>
<body>

{{-- ═══════════════════════════════════════════════
     FULL-SCREEN SCENIC BACKGROUND
═══════════════════════════════════════════════════ --}}
<div class="ls-bg" aria-hidden="true">

    {{-- Islamic Architecture Horizon Silhouette --}}
    <svg class="ls-bg-silhouette"
         viewBox="0 0 1440 360"
         preserveAspectRatio="xMidYMax slice"
         xmlns="http://www.w3.org/2000/svg"
         aria-hidden="true">

        <defs>
            {{-- Vertical fade: transparent at sky-line, opaque at ground --}}
            <linearGradient id="archFade" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%"   stop-color="#7a4e28" stop-opacity="0"/>
                <stop offset="32%"  stop-color="#7a4e28" stop-opacity="0.055"/>
                <stop offset="75%"  stop-color="#7a4e28" stop-opacity="0.105"/>
                <stop offset="100%" stop-color="#7a4e28" stop-opacity="0.135"/>
            </linearGradient>
        </defs>

        <g fill="url(#archFade)">

            {{-- ── Ground / Horizon Base ── --}}
            <rect x="0" y="310" width="1440" height="50"/>

            {{-- ══════════════════════════════════
                 FAR-LEFT DISTANT CLUSTER
            ══════════════════════════════════ --}}
            {{-- Far-left small mosque body --}}
            <rect x="62" y="274" width="130" height="36" rx="2"/>
            {{-- Far-left domes --}}
            <path d="M 62,274 A 36,44 0 0 1 134,274 Z"/>
            <path d="M 132,274 A 28,34 0 0 1 192,274 Z"/>
            {{-- Far-left minaret --}}
            <rect x="38" y="248" width="16" height="62" rx="1.5"/>
            <rect x="32" y="242" width="28" height="10" rx="1.5"/>
            <path d="M 38,242 L 46,218 L 54,242 Z"/>

            {{-- ══════════════════════════════════
                 MID-LEFT SECONDARY MOSQUE
            ══════════════════════════════════ --}}
            <rect x="205" y="262" width="168" height="48" rx="2"/>
            <path d="M 205,262 A 44,56 0 0 1 293,262 Z"/>
            <path d="M 285,262 A 44,56 0 0 1 373,262 Z"/>
            {{-- Mid-left minaret --}}
            <rect x="176" y="228" width="20" height="82" rx="1.5"/>
            <rect x="168" y="218" width="36" height="14" rx="2"/>
            <path d="M 176,218 L 186,188 L 196,218 Z"/>
            <path d="M 184,190 Q 182,183 186,178 Q 190,183 188,190 Z" fill="rgba(255,255,255,0.22)"/>

            {{-- ══════════════════════════════════
                 FAR-RIGHT DISTANT CLUSTER
            ══════════════════════════════════ --}}
            <rect x="1248" y="274" width="130" height="36" rx="2"/>
            <path d="M 1248,274 A 36,44 0 0 1 1320,274 Z"/>
            <path d="M 1316,274 A 28,34 0 0 1 1378,274 Z"/>
            {{-- Far-right minaret --}}
            <rect x="1386" y="248" width="16" height="62" rx="1.5"/>
            <rect x="1380" y="242" width="28" height="10" rx="1.5"/>
            <path d="M 1386,242 L 1394,218 L 1402,242 Z"/>

            {{-- ══════════════════════════════════
                 MID-RIGHT SECONDARY MOSQUE
            ══════════════════════════════════ --}}
            <rect x="1067" y="262" width="168" height="48" rx="2"/>
            <path d="M 1067,262 A 44,56 0 0 1 1155,262 Z"/>
            <path d="M 1147,262 A 44,56 0 0 1 1235,262 Z"/>
            {{-- Mid-right minaret --}}
            <rect x="1244" y="228" width="20" height="82" rx="1.5"/>
            <rect x="1236" y="218" width="36" height="14" rx="2"/>
            <path d="M 1244,218 L 1254,188 L 1264,218 Z"/>
            <path d="M 1252,190 Q 1250,183 1254,178 Q 1258,183 1256,190 Z" fill="rgba(255,255,255,0.22)"/>

            {{-- ══════════════════════════════════
                 CENTRAL GRAND MOSQUE
            ══════════════════════════════════ --}}

            {{-- Main building body --}}
            <rect x="548" y="244" width="344" height="66" rx="3"/>

            {{-- Outer flanking mini domes --}}
            <path d="M 548,244 A 26,32 0 0 1 600,244 Z"/>
            <path d="M 840,244 A 26,32 0 0 1 892,244 Z"/>

            {{-- Secondary lateral domes (left & right) --}}
            <path d="M 566,244 A 60,72 0 0 1 686,244 Z"/>
            <path d="M 754,244 A 60,72 0 0 1 874,244 Z"/>

            {{-- Central grand dome --}}
            <path d="M 618,244 A 102,144 0 0 1 822,244 Z"/>

            {{-- Door arch (luminous cut-out) --}}
            <path d="M 706,310 L 706,272 Q 720,254 734,272 L 734,310 Z"
                  fill="rgba(255,255,255,0.20)"/>

            {{-- Side window niches --}}
            <rect x="564" y="257" width="24" height="20" rx="9" fill="rgba(255,255,255,0.14)"/>
            <rect x="852" y="257" width="24" height="20" rx="9" fill="rgba(255,255,255,0.14)"/>
            <rect x="633" y="258" width="18" height="17" rx="7" fill="rgba(255,255,255,0.12)"/>
            <rect x="789" y="258" width="18" height="17" rx="7" fill="rgba(255,255,255,0.12)"/>

            {{-- ── LEFT MAIN MINARET ── --}}
            <rect x="474" y="152" width="28" height="158" rx="2"/>
            {{-- Balcony band --}}
            <rect x="464" y="140" width="48" height="16" rx="2.5"/>
            {{-- Spire --}}
            <path d="M 474,140 L 488,94 L 502,140 Z"/>
            {{-- Crescent finial --}}
            <path d="M 485,96 Q 482,88 488,82 Q 494,88 491,96 Z" fill="rgba(255,255,255,0.28)"/>
            {{-- Ornament rings on shaft --}}
            <rect x="476" y="188" width="24" height="5" rx="1" fill="rgba(255,255,255,0.16)"/>
            <rect x="476" y="230" width="24" height="5" rx="1" fill="rgba(255,255,255,0.16)"/>

            {{-- ── RIGHT MAIN MINARET (mirror) ── --}}
            <rect x="938" y="152" width="28" height="158" rx="2"/>
            <rect x="928" y="140" width="48" height="16" rx="2.5"/>
            <path d="M 938,140 L 952,94 L 966,140 Z"/>
            <path d="M 949,96 Q 946,88 952,82 Q 958,88 955,96 Z" fill="rgba(255,255,255,0.28)"/>
            <rect x="940" y="188" width="24" height="5" rx="1" fill="rgba(255,255,255,0.16)"/>
            <rect x="940" y="230" width="24" height="5" rx="1" fill="rgba(255,255,255,0.16)"/>

        </g>
    </svg>

</div>


{{-- ═══════════════════════════════════════════════
     TOP NAVBAR HEADER
═══════════════════════════════════════════════════ --}}
<header class="ls-header">

    <a href="{{ url('/') }}" class="ls-brand" aria-label="Al-Kalimah Foundation — Home">

        {{-- Mosque SVG logotype --}}
        <svg class="ls-mosque-icon" viewBox="0 0 100 68" fill="currentColor"
             xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
            {{-- Left minaret --}}
            <rect x="3"  y="22" width="9" height="43" rx="1.5"/>
            <path d="M7.5 10 C5 10 3 15 3 22 H12 C12 15 10 10 7.5 10Z"/>
            <circle cx="7.5" cy="9" r="4"/>
            {{-- Right minaret --}}
            <rect x="88" y="22" width="9" height="43" rx="1.5"/>
            <path d="M92.5 10 C90 10 88 15 88 22 H97 C97 15 95 10 92.5 10Z"/>
            <circle cx="92.5" cy="9" r="4"/>
            {{-- Main building --}}
            <rect x="16" y="47" width="68" height="18" rx="2.5"/>
            {{-- Domes --}}
            <path d="M28 47 Q50 17 72 47Z"/>
            <path d="M16 47 Q24 36 33 47Z"/>
            <path d="M67 47 Q76 36 84 47Z"/>
            {{-- Door arch --}}
            <path d="M44 65 V53 Q50 47 56 53 V65Z" fill="rgba(255,255,255,.22)"/>
            {{-- Crescent finial --}}
            <path d="M50 18 Q47 14 50 11 Q54 14 51 18Z" fill="rgba(255,255,255,.35)"/>
        </svg>

        <div class="ls-brand-text">
            <span class="ls-brand-ar">
                مؤسسة <span class="ls-accent">الكلم الطيب</span>
            </span>
            <span class="ls-brand-en">
                Al-<span class="ls-accent">Kalimah</span> Foundation
            </span>
        </div>
    </a>

    {{-- Language Switcher --}}
    <div class="ls-lang" role="navigation" aria-label="Language selector">
        @if($loc === 'ar')
            <a href="{{ url()->current() }}?lang=en" class="ls-lang-btn">EN</a>
            <span class="ls-lang-sep" aria-hidden="true">|</span>
            <span class="ls-lang-btn on" aria-current="true">AR</span>
        @else
            <span class="ls-lang-btn on" aria-current="true">EN</span>
            <span class="ls-lang-sep" aria-hidden="true">|</span>
            <a href="{{ url()->current() }}?lang=ar" class="ls-lang-btn">AR</a>
        @endif
    </div>

</header>


{{-- ═══════════════════════════════════════════════
     MAIN — Glassmorphism Login Card
═══════════════════════════════════════════════════ --}}
<main class="ls-main">

    <div class="ls-card"
         role="region"
         aria-label="{{ $loc === 'ar' ? 'تسجيل الدخول' : 'Sign In' }}">

        {{-- Eyebrow + heading + rule --}}
        <span class="ls-eyebrow">
            @if($loc === 'ar') بوابة الأعضاء @else Member Portal @endif
        </span>

        <h1 class="ls-heading">
            @if($loc === 'ar') مرحباً بعودتك @else Welcome Back @endif
        </h1>

        <p class="ls-subheading">
            @if($loc === 'ar')
                سجّل دخولك لمواصلة رحلة العطاء
            @else
                Sign in to continue your journey of giving
            @endif
        </p>

        <div class="ls-rule" aria-hidden="true"></div>

        {{-- Session status (e.g. password-reset link sent) --}}
        @if (session('status'))
            <div class="ls-flash" role="status">{{ session('status') }}</div>
        @endif

        {{-- ── Login Form ── --}}
        <form method="POST" action="{{ route('login') }}" novalidate>
            @csrf

            {{-- Email --}}
            <div class="ls-field">
                <label class="ls-label" for="email">
                    @if($loc === 'ar') البريد الإلكتروني @else Email Address @endif
                </label>
                <input
                    class="ls-input"
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    placeholder="{{ $loc === 'ar' ? 'example@domain.com' : 'you@example.com' }}"
                    required
                    autofocus
                    autocomplete="username"
                    aria-describedby="{{ $errors->has('email') ? 'email-error' : '' }}"
                >
                @error('email')
                    <span class="ls-err" id="email-error" role="alert">{{ $message }}</span>
                @enderror
            </div>

            {{-- Password --}}
            <div class="ls-field">
                <label class="ls-label" for="password">
                    @if($loc === 'ar') كلمة المرور @else Password @endif
                </label>
                <input
                    class="ls-input"
                    id="password"
                    type="password"
                    name="password"
                    placeholder="••••••••"
                    required
                    autocomplete="current-password"
                    aria-describedby="{{ $errors->has('password') ? 'pw-error' : '' }}"
                >
                @error('password')
                    <span class="ls-err" id="pw-error" role="alert">{{ $message }}</span>
                @enderror
            </div>

            {{-- Remember me + Forgot password --}}
            <div class="ls-meta">
                <label class="ls-remember" for="remember_me">
                    <input id="remember_me" type="checkbox" name="remember">
                    <span class="ls-remember-label">
                        @if($loc === 'ar') تذكّرني @else Remember me @endif
                    </span>
                </label>

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="ls-forgot">
                        @if($loc === 'ar') نسيت كلمة المرور؟ @else Forgot password? @endif
                    </a>
                @endif
            </div>

            {{-- Primary submit button --}}
            <button type="submit" class="ls-submit">
                <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
                @if($loc === 'ar') تسجيل الدخول @else Sign In @endif
            </button>

        </form>

        <a href="{{ url('/') }}" class="ls-back">
            @if($loc === 'ar') → العودة إلى الصفحة الرئيسية @else ← Back to home @endif
        </a>

    </div>

</main>

</body>
</html>
