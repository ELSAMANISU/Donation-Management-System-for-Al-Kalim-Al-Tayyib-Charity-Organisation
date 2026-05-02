<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Al-Kalimah Foundation') }}</title>

        <!-- Bootstrap 5 RTL / LTR -->
        @if(app()->getLocale() === 'ar')
            <link rel="stylesheet"
                  href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
        @else
            <link rel="stylesheet"
                  href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        @endif

        <!-- Font Awesome -->
        <link rel="stylesheet"
              href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

        <!-- Google Fonts: Amiri + Cairo (AR) + Montserrat (EN) -->
        <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@300;400;600;700;900&family=Montserrat:wght@300;400;600;700;900&display=swap"
              rel="stylesheet">

        <!-- Vite assets (Tailwind for Breeze form components) -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --primary:      #63C1E7;
                --primary-dark: #3a9dc7;
                --accent:       #F27C31;
                --accent-dark:  #d4611a;
                --navy:         #0d2b45;
                --navy-light:   #163d60;
                --white:        #ffffff;
                --text-dark:    #1a2533;
                --text-muted:   #6b7a90;
                --font-main:    'Montserrat', sans-serif;
                --font-display: 'Montserrat', sans-serif;
            }

            html[lang="ar"] {
                --font-main:    'Cairo', sans-serif;
                --font-display: 'Amiri', serif;
            }

            *, *::before, *::after { box-sizing: border-box; }

            body {
                font-family: var(--font-main);
                background: var(--navy);
                min-height: 100vh;
                margin: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem 1rem;
                /* Subtle geometric pattern overlay */
                background-image:
                    radial-gradient(ellipse at 20% 50%, rgba(99,193,231,0.08) 0%, transparent 60%),
                    radial-gradient(ellipse at 80% 20%, rgba(242,124,49,0.06) 0%, transparent 50%);
            }

            /* ── Auth wrapper ── */
            .auth-wrapper {
                width: 100%;
                max-width: 460px;
            }

            /* ── Brand logo block ── */
            .auth-brand {
                text-align: center;
                margin-bottom: 1.75rem;
                text-decoration: none;
                display: block;
            }
            .auth-brand .brand-main {
                font-family: var(--font-display);
                font-size: 1.6rem;
                font-weight: 700;
                color: var(--white);
                line-height: 1.25;
                display: block;
                letter-spacing: -0.3px;
            }
            html[lang="ar"] .auth-brand .brand-main {
                font-size: 1.8rem;
                letter-spacing: 0;
            }
            .auth-brand .brand-sub {
                font-family: var(--font-main);
                font-size: 0.7rem;
                color: var(--primary);
                display: block;
                letter-spacing: 2.5px;
                font-weight: 600;
                text-transform: uppercase;
                margin-top: 3px;
            }
            html[lang="ar"] .auth-brand .brand-sub {
                letter-spacing: 0;
                font-size: 0.8rem;
                text-transform: none;
            }
            .auth-brand .brand-accent-dot {
                display: inline-block;
                width: 7px;
                height: 7px;
                background: var(--accent);
                border-radius: 50%;
                margin: 0 3px;
                vertical-align: middle;
                position: relative;
                top: -2px;
            }

            /* ── White card ── */
            .auth-card {
                background: var(--white);
                border-radius: 18px;
                box-shadow:
                    0 20px 60px rgba(0, 0, 0, 0.35),
                    0 4px 16px rgba(0, 0, 0, 0.15),
                    inset 0 1px 0 rgba(255,255,255,0.9);
                padding: 2.25rem 2rem;
            }

            /* ── Override Tailwind form styles to stay readable on white ── */
            .auth-card label {
                font-family: var(--font-main) !important;
                color: var(--text-dark) !important;
                font-size: 0.875rem;
                font-weight: 600;
            }
            .auth-card input[type="text"],
            .auth-card input[type="email"],
            .auth-card input[type="password"] {
                font-family: var(--font-main) !important;
                border-color: #d1d5db !important;
                transition: border-color 0.2s, box-shadow 0.2s !important;
            }
            .auth-card input[type="text"]:focus,
            .auth-card input[type="email"]:focus,
            .auth-card input[type="password"]:focus {
                border-color: var(--primary) !important;
                box-shadow: 0 0 0 3px rgba(99,193,231,0.2) !important;
                outline: none !important;
                ring: none !important;
            }

            /* ── Primary button ── */
            .auth-card button[type="submit"],
            .auth-card .btn-primary-brand {
                background: var(--accent) !important;
                border-color: var(--accent) !important;
                color: var(--white) !important;
                font-family: var(--font-main) !important;
                font-weight: 700 !important;
                border-radius: 10px !important;
                padding: 0.55rem 1.5rem !important;
                font-size: 0.9rem !important;
                letter-spacing: 0.3px;
                box-shadow: 0 4px 18px rgba(242,124,49,0.38) !important;
                transition: background 0.25s, transform 0.2s, box-shadow 0.25s !important;
                text-transform: none !important;
            }
            .auth-card button[type="submit"]:hover,
            .auth-card .btn-primary-brand:hover {
                background: var(--accent-dark) !important;
                border-color: var(--accent-dark) !important;
                transform: translateY(-2px) !important;
                box-shadow: 0 7px 24px rgba(242,124,49,0.48) !important;
            }
            .auth-card button[type="submit"]:active,
            .auth-card .btn-primary-brand:active {
                transform: translateY(0) !important;
            }

            /* ── Links inside card ── */
            .auth-card a {
                color: var(--primary-dark);
                font-weight: 500;
                transition: color 0.2s;
            }
            .auth-card a:hover {
                color: var(--accent);
                text-decoration: underline;
            }

            /* ── Checkbox accent ── */
            .auth-card input[type="checkbox"]:checked {
                background-color: var(--accent) !important;
                border-color: var(--accent) !important;
            }

            /* ── Divider strip at top of card ── */
            .auth-card::before {
                content: '';
                display: block;
                height: 4px;
                background: linear-gradient(90deg, var(--accent) 0%, var(--primary) 100%);
                border-radius: 4px 4px 0 0;
                margin: -2.25rem -2rem 1.75rem;
                border-top-left-radius: 18px;
                border-top-right-radius: 18px;
            }

            /* ── RTL text adjustments ── */
            html[dir="rtl"] .auth-card {
                text-align: right;
            }
            html[dir="rtl"] .auth-card .flex.items-center.justify-end {
                flex-direction: row-reverse;
                justify-content: flex-start !important;
            }

            /* ── Back to home link ── */
            .auth-back {
                display: block;
                text-align: center;
                margin-top: 1.25rem;
                color: rgba(255,255,255,0.55);
                font-size: 0.82rem;
                font-family: var(--font-main);
                text-decoration: none;
                transition: color 0.2s;
            }
            .auth-back:hover { color: var(--primary); }
            html[lang="ar"] .auth-back { font-family: var(--font-main); }
        </style>
    </head>
    <body>
        <div class="auth-wrapper">

            <!-- Brand logo -->
            <a href="{{ url('/') }}" class="auth-brand">
                <span class="brand-main">
                    @if(app()->getLocale() === 'ar')
                        مؤسسة الكلم الطيب
                    @else
                        Al-Kalimah<span class="brand-accent-dot"></span>Foundation
                    @endif
                </span>
                <span class="brand-sub">
                    @if(app()->getLocale() === 'ar')
                        للخير والعطاء
                    @else
                        For Good &amp; Giving
                    @endif
                </span>
            </a>

            <!-- Auth card -->
            <div class="auth-card">
                {{ $slot }}
            </div>

            <!-- Back to home -->
            <a href="{{ url('/') }}" class="auth-back">
                @if(app()->getLocale() === 'ar')
                    العودة إلى الرئيسية ←
                @else
                    ← Back to home
                @endif
            </a>

        </div>
    </body>
</html>
