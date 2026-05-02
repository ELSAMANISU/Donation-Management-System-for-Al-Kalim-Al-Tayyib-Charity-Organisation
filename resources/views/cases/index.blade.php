<!DOCTYPE html>
<html lang="en" dir="ltr" id="htmlRoot">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Donation Cases — Al-Kalimah Foundation</title>

  <link id="bootstrapCSS"
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"/>

  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>

  <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@300;400;600;700;900&family=Montserrat:wght@300;400;600;700;900&display=swap"
        rel="stylesheet"/>

  <style>
    :root {
      --primary:       #63C1E7;
      --primary-dark:  #3a9dc7;
      --accent:        #F27C31;
      --accent-dark:   #d4611a;
      --navy:          #0d2b45;
      --navy-light:    #163d60;
      --light-gray:    #f5f7fa;
      --mid-gray:      #e2e8f0;
      --text-dark:     #1a2533;
      --text-muted:    #6b7a90;
      --white:         #ffffff;
      --font-main:     'Montserrat', sans-serif;
      --font-display:  'Montserrat', sans-serif;
    }
    html[lang="ar"] {
      --font-main:    'Cairo', sans-serif;
      --font-display: 'Amiri', serif;
    }

    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      font-family: var(--font-main);
      color: var(--text-dark);
      background: var(--white);
      overflow-x: hidden;
      transition: font-family 0.3s;
    }
    h1,h2,h3,h4,h5,h6 { font-family: var(--font-main); font-weight: 700; }
    a { text-decoration: none; }
    body * { transition: font-family 0.2s; }

    /* ─── Language Switcher ─── */
    .lang-switcher {
      display: flex; align-items: center;
      background: rgba(255,255,255,0.12);
      border-radius: 100px; padding: 3px;
      border: 1px solid rgba(255,255,255,0.25);
      backdrop-filter: blur(6px);
      transition: background 0.4s; gap: 2px;
    }
    #mainNav.scrolled .lang-switcher {
      background: rgba(13,43,69,0.08);
      border-color: var(--mid-gray);
    }
    .lang-btn {
      background: transparent; border: none;
      color: rgba(255,255,255,0.75);
      font-family: var(--font-main);
      font-size: 0.78rem; font-weight: 700;
      padding: 5px 13px; border-radius: 100px;
      cursor: pointer; transition: all 0.25s; letter-spacing: 0.5px;
    }
    #mainNav.scrolled .lang-btn { color: var(--text-muted); }
    .lang-btn.active {
      background: var(--accent); color: var(--white) !important;
      box-shadow: 0 2px 10px rgba(242,124,49,0.4);
    }

    /* ─── Navbar ─── */
    #mainNav {
      position: fixed; top: 0; left: 0; right: 0; z-index: 1050;
      transition: background 0.4s ease, box-shadow 0.4s ease, padding 0.4s ease;
      padding: 16px 0;
    }
    #mainNav.scrolled {
      background: rgba(255,255,255,0.97) !important;
      box-shadow: 0 2px 28px rgba(0,0,0,0.08);
      padding: 10px 0;
    }
    .navbar-brand .brand-main {
      font-family: var(--font-display); font-size: 1.35rem; font-weight: 700;
      color: var(--white); line-height: 1.2; transition: color 0.4s; display: block;
    }
    .navbar-brand .brand-sub {
      font-size: 0.65rem; color: var(--primary); display: block;
      letter-spacing: 1.5px; font-weight: 600; text-transform: uppercase;
    }
    #mainNav.scrolled .brand-main { color: var(--navy); }
    #mainNav .nav-link {
      color: rgba(255,255,255,0.9) !important; font-weight: 600;
      font-size: 0.88rem; padding: 6px 13px !important;
      border-radius: 7px; transition: all 0.25s;
    }
    #mainNav.scrolled .nav-link { color: var(--navy) !important; }
    #mainNav .nav-link:hover { color: var(--accent) !important; background: rgba(242,124,49,0.08); }
    .btn-donate-nav {
      background: var(--accent) !important; color: var(--white) !important;
      border-radius: 9px; padding: 8px 22px !important;
      font-weight: 700; font-size: 0.88rem;
      box-shadow: 0 4px 18px rgba(242,124,49,0.38); transition: all 0.25s !important;
    }
    .btn-donate-nav:hover {
      background: var(--accent-dark) !important; transform: translateY(-2px);
      box-shadow: 0 7px 24px rgba(242,124,49,0.48) !important;
    }
    #mainNav .navbar-toggler { border: none; outline: none; box-shadow: none; }
    #mainNav .navbar-toggler-icon { filter: invert(1) brightness(2); }
    #mainNav.scrolled .navbar-toggler-icon { filter: none; }

    /* ─── Cinematic Page Header ─── */
    #pageHeader {
      position: relative;
      height: 55vh; min-height: 360px;
      display: flex; align-items: center; justify-content: center;
      overflow: hidden;
    }
    #pageHeader .header-bg {
      position: absolute; inset: 0;
      background-image: url('https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=1600&q=80');
      background-size: cover; background-position: center;
      filter: brightness(0.38);
      transform: scale(1.04);
      transition: transform 8s ease;
    }
    #pageHeader:hover .header-bg { transform: scale(1); }
    #pageHeader .header-overlay {
      position: absolute; inset: 0;
      background: linear-gradient(140deg, rgba(13,43,69,0.72) 0%, rgba(99,193,231,0.18) 100%);
    }
    .header-content {
      position: relative; z-index: 2;
      text-align: center; padding: 0 20px;
    }
    .header-eyebrow {
      display: inline-flex; align-items: center; gap: 10px;
      background: rgba(99,193,231,0.15);
      border: 1px solid rgba(99,193,231,0.38);
      color: #a8dff5; font-size: 0.75rem; font-weight: 600;
      letter-spacing: 2.5px; text-transform: uppercase;
      padding: 6px 22px; border-radius: 100px;
      margin-bottom: 20px; backdrop-filter: blur(8px);
    }
    html[lang="ar"] .header-eyebrow { letter-spacing: 0; font-size: 0.85rem; }
    .header-eyebrow i { font-size: 0.85rem; color: var(--accent); }
    .header-title {
      font-family: var(--font-display);
      font-size: clamp(2.2rem, 5.5vw, 4rem);
      color: var(--white); font-weight: 900; line-height: 1.25;
      text-shadow: 0 3px 24px rgba(0,0,0,0.32);
      margin-bottom: 14px;
    }
    .header-subtitle {
      font-size: clamp(0.9rem, 2vw, 1.08rem);
      color: rgba(255,255,255,0.72); font-weight: 300; line-height: 1.7;
      max-width: 560px; margin: 0 auto;
    }
    .header-breadcrumb {
      display: flex; align-items: center; justify-content: center;
      gap: 8px; margin-top: 22px;
      font-size: 0.8rem; color: rgba(255,255,255,0.5);
    }
    .header-breadcrumb a { color: var(--primary); transition: color 0.2s; }
    .header-breadcrumb a:hover { color: var(--accent); }
    .header-breadcrumb i { font-size: 0.6rem; }

    /* ─── Cases Section ─── */
    #casesSection { padding: 72px 0 90px; background: var(--light-gray); }
    .section-label {
      font-size: 0.75rem; font-weight: 700; letter-spacing: 3px;
      color: var(--primary-dark); text-transform: uppercase; margin-bottom: 6px;
    }
    html[lang="ar"] .section-label { letter-spacing: 0; font-size: 0.82rem; }
    .section-title {
      font-size: clamp(1.6rem, 3vw, 2.3rem); font-weight: 900;
      color: var(--navy); margin-bottom: 6px;
    }
    .section-divider {
      width: 52px; height: 4px;
      background: linear-gradient(90deg, var(--accent), var(--primary));
      border-radius: 4px; margin: 0 auto 14px;
    }

    /* ─── Case Card ─── */
    .case-card {
      background: var(--white); border-radius: 20px; overflow: hidden;
      box-shadow: 0 4px 24px rgba(0,0,0,0.07);
      transition: transform 0.35s cubic-bezier(0.25,0.46,0.45,0.94), box-shadow 0.35s;
      height: 100%; display: flex; flex-direction: column;
    }
    .case-card:hover {
      transform: translateY(-8px);
      box-shadow: 0 18px 48px rgba(0,0,0,0.14);
    }
    .case-img-wrap {
      position: relative; height: 210px; overflow: hidden; flex-shrink: 0;
    }
    .case-img-wrap img {
      width: 100%; height: 100%; object-fit: cover;
      transition: transform 0.55s ease;
    }
    .case-card:hover .case-img-wrap img { transform: scale(1.08); }
    .case-tag {
      position: absolute; top: 14px; right: 14px;
      background: var(--primary); color: var(--white);
      font-size: 0.7rem; font-weight: 700;
      padding: 4px 14px; border-radius: 100px;
      letter-spacing: 0.4px; backdrop-filter: blur(4px);
    }
    html[dir="ltr"] .case-tag { right: auto; left: 14px; }
    .case-icon-badge {
      position: absolute; bottom: -22px; right: 18px;
      width: 46px; height: 46px; background: var(--accent);
      border-radius: 50%; display: flex; align-items: center; justify-content: center;
      color: var(--white); font-size: 1.1rem;
      box-shadow: 0 4px 16px rgba(242,124,49,0.45); z-index: 2;
    }
    html[dir="ltr"] .case-icon-badge { right: auto; left: 18px; }
    .case-body {
      padding: 32px 20px 22px; flex: 1;
      display: flex; flex-direction: column;
    }
    .case-title {
      font-size: 1.01rem; font-weight: 700;
      color: var(--navy); margin-bottom: 14px; line-height: 1.5;
    }

    /* Progress bar */
    .progress-label {
      display: flex; justify-content: space-between;
      font-size: 0.73rem; color: var(--text-muted); margin-bottom: 6px;
    }
    .progress-label .pct { color: var(--accent); font-weight: 800; font-size: 0.8rem; }
    .progress {
      height: 8px; border-radius: 10px;
      background: var(--mid-gray); margin-bottom: 16px; overflow: visible;
    }
    .progress-bar {
      background: linear-gradient(90deg, var(--accent), #f5a55a);
      border-radius: 10px; position: relative;
      transition: width 1.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .progress-bar::after {
      content: '';
      position: absolute; top: 50%;
      left: -6px;
      transform: translateY(-50%);
      width: 16px; height: 16px;
      background: var(--accent); border-radius: 50%;
      border: 2.5px solid var(--white);
      box-shadow: 0 0 0 3px rgba(242,124,49,0.22);
    }
    html[dir="ltr"] .progress-bar::after { left: auto; right: -6px; }

    /* Raised / Goal */
    .case-amounts {
      display: flex; gap: 10px;
      font-size: 0.75rem; color: var(--text-muted);
      margin-bottom: 18px; flex-wrap: wrap;
    }
    .case-amounts .amount-item {
      display: flex; align-items: center; gap: 5px;
    }
    .case-amounts .amount-item i { color: var(--primary); font-size: 0.7rem; }
    .case-amounts .amount-item strong { color: var(--navy); font-weight: 700; font-size: 0.83rem; }

    .btn-donate {
      display: block; width: 100%; text-align: center;
      background: var(--accent); color: var(--white) !important;
      border: none; border-radius: 11px;
      padding: 11px 20px;
      font-family: var(--font-main); font-weight: 700; font-size: 0.88rem;
      cursor: pointer; transition: all 0.28s;
      box-shadow: 0 4px 16px rgba(242,124,49,0.3);
      margin-top: auto;
    }
    .btn-donate:hover {
      background: var(--accent-dark); transform: scale(1.03);
      box-shadow: 0 8px 26px rgba(242,124,49,0.45);
    }

    /* Empty state */
    .empty-state {
      text-align: center; padding: 80px 20px;
    }
    .empty-state i { font-size: 3.5rem; color: var(--mid-gray); margin-bottom: 18px; display: block; }
    .empty-state h4 { color: var(--navy); margin-bottom: 10px; }
    .empty-state p { color: var(--text-muted); font-size: 0.9rem; }

    /* ─── Footer ─── */
    footer {
      background: var(--navy); color: rgba(255,255,255,0.7); padding: 60px 0 0;
    }
    .footer-brand-main {
      font-family: var(--font-display); font-size: 1.35rem;
      font-weight: 700; color: var(--white);
    }
    .footer-brand-sub {
      font-size: 0.65rem; color: var(--primary);
      letter-spacing: 1.5px; text-transform: uppercase; font-weight: 600;
    }
    .footer-about {
      font-size: 0.84rem; line-height: 1.82;
      color: rgba(255,255,255,0.55); margin-top: 12px; max-width: 300px;
    }
    .footer-heading {
      color: var(--white); font-weight: 700; font-size: 0.98rem;
      margin-bottom: 16px; position: relative; padding-bottom: 10px;
    }
    .footer-heading::after {
      content: ''; position: absolute; bottom: 0; left: 0;
      width: 34px; height: 3px; background: var(--accent); border-radius: 2px;
    }
    html[dir="rtl"] .footer-heading::after { left: auto; right: 0; }
    .footer-links { list-style: none; padding: 0; margin: 0; }
    .footer-links li { margin-bottom: 9px; }
    .footer-links a {
      color: rgba(255,255,255,0.55); font-size: 0.86rem;
      display: flex; align-items: center; gap: 8px;
      transition: color 0.25s, gap 0.25s;
    }
    .footer-links a:hover { color: var(--primary); gap: 12px; }
    .footer-links a i { color: var(--accent); font-size: 0.65rem; }
    .social-links { display: flex; gap: 9px; margin-top: 16px; flex-wrap: wrap; }
    .social-btn {
      width: 40px; height: 40px; border-radius: 10px;
      background: rgba(255,255,255,0.07);
      display: flex; align-items: center; justify-content: center;
      color: rgba(255,255,255,0.65); font-size: 0.92rem;
      border: 1px solid rgba(255,255,255,0.1); transition: all 0.25s;
    }
    .social-btn:hover {
      background: var(--accent); color: var(--white);
      border-color: var(--accent); transform: translateY(-2px);
    }
    .footer-contact-item {
      display: flex; align-items: flex-start; gap: 11px;
      margin-bottom: 13px; font-size: 0.84rem; color: rgba(255,255,255,0.55);
    }
    .footer-contact-item i { color: var(--primary); margin-top: 2px; flex-shrink: 0; }
    .footer-newsletter-input {
      flex: 1; background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.15); border-radius: 8px;
      padding: 10px 14px; color: white;
      font-family: var(--font-main); font-size: 0.83rem;
      outline: none; transition: border 0.2s;
    }
    .footer-newsletter-input:focus { border-color: var(--primary); }
    .footer-newsletter-input::placeholder { color: rgba(255,255,255,0.35); }
    .footer-newsletter-btn {
      background: var(--accent); color: var(--white); border: none;
      border-radius: 8px; padding: 10px 16px; cursor: pointer;
      font-family: var(--font-main); font-weight: 700; font-size: 0.8rem;
      transition: all 0.25s; white-space: nowrap;
    }
    .footer-newsletter-btn:hover { background: var(--accent-dark); }
    .footer-bottom {
      border-top: 1px solid rgba(255,255,255,0.08);
      padding: 18px 0; margin-top: 48px;
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 10px;
    }
    .footer-bottom p { margin: 0; font-size: 0.78rem; color: rgba(255,255,255,0.4); }
    .footer-bottom a { color: var(--primary); }

    /* ─── Back to Top ─── */
    #backToTop {
      position: fixed; bottom: 28px; left: 28px;
      width: 46px; height: 46px; background: var(--accent);
      color: var(--white); border: none; border-radius: 12px;
      font-size: 1rem; cursor: pointer;
      box-shadow: 0 4px 18px rgba(242,124,49,0.42);
      opacity: 0; pointer-events: none; transition: all 0.3s; z-index: 999;
    }
    #backToTop.visible { opacity: 1; pointer-events: all; }
    #backToTop:hover { background: var(--accent-dark); transform: translateY(-3px); }

    /* ─── Scroll Animations ─── */
    .fade-up {
      opacity: 0; transform: translateY(28px);
      transition: opacity 0.65s ease, transform 0.65s ease;
    }
    .fade-up.visible { opacity: 1; transform: translateY(0); }
    .delay-1 { transition-delay: 0.1s; }
    .delay-2 { transition-delay: 0.2s; }
    .delay-3 { transition-delay: 0.3s; }
    .delay-4 { transition-delay: 0.4s; }
    .delay-5 { transition-delay: 0.5s; }
    .delay-6 { transition-delay: 0.6s; }

    /* ─── Responsive ─── */
    @media (max-width: 767px) {
      #pageHeader { height: 46vh; min-height: 300px; }
      .footer-bottom { flex-direction: column; text-align: center; }
      .footer-about { max-width: 100%; }
      .footer-heading::after { display: none; }
    }
  </style>
</head>

<body>

{{-- ═══════════ NAVBAR ═══════════ --}}
<nav id="mainNav" class="navbar navbar-expand-lg">
  <div class="container">

    <a class="navbar-brand" href="{{ url('/') }}">
      <span class="brand-main" data-en="Al-Kalimah Foundation" data-ar="مؤسسة الكلم الطيب">Al-Kalimah Foundation</span>
      <span class="brand-sub" data-en="For Good &amp; Giving" data-ar="للخير والعطاء">For Good &amp; Giving</span>
    </a>

    <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-1">
        <li class="nav-item">
          <a class="nav-link" href="{{ url('/') }}"
             data-en="Home" data-ar="الرئيسية">Home</a>
        </li>
        <li class="nav-item">
          <a class="nav-link active" href="#"
             data-en="Donation Cases" data-ar="حالات التبرع">Donation Cases</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="{{ url('/') }}#services"
             data-en="Services" data-ar="الخدمات">Services</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="{{ url('/') }}#impactAreas"
             data-en="Impact Areas" data-ar="مجالات التأثير">Impact Areas</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="{{ url('/') }}#partners"
             data-en="About Us" data-ar="عن المؤسسة">About Us</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#"
             data-en="Login" data-ar="تسجيل الدخول">Login</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="{{ route('register') }}"
             data-en="Register" data-ar="إنشاء حساب">Register</a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-3 mt-3 mt-lg-0">
        <div class="lang-switcher">
          <button class="lang-btn active" id="btnEN" onclick="setLang('en')">EN</button>
          <button class="lang-btn" id="btnAR" onclick="setLang('ar')">عر</button>
        </div>
        <a href="#casesSection"
           class="nav-link btn-donate-nav"
           data-en="Donate Now ♥" data-ar="تبرع الآن ♥">Donate Now ♥</a>
      </div>
    </div>
  </div>
</nav>

{{-- ═══════════ CINEMATIC PAGE HEADER ═══════════ --}}
@php
  $locale = app()->getLocale();

  $categoryIcons = [
    'health'    => 'fa-solid fa-heart-pulse',
    'orphans'   => 'fa-solid fa-child-reaching',
    'education' => 'fa-solid fa-graduation-cap',
    'housing'   => 'fa-solid fa-house',
    'water'     => 'fa-solid fa-droplet',
    'mosques'   => 'fa-solid fa-mosque',
  ];

  $categoryNamesEn = [
    'health'    => 'Healthcare',
    'orphans'   => 'Orphan Care',
    'education' => 'Education',
    'housing'   => 'Housing',
    'water'     => 'Clean Water',
    'mosques'   => 'Mosques',
  ];

  $categoryNamesAr = [
    'health'    => 'الرعاية الصحية',
    'orphans'   => 'كفالة الأيتام',
    'education' => 'التعليم',
    'housing'   => 'الإسكان',
    'water'     => 'المياه النظيفة',
    'mosques'   => 'المساجد',
  ];

  $categoryBgs = [
    'health'    => 'https://images.unsplash.com/photo-1584820927498-cfe5211fd8bf?w=1600&q=80',
    'orphans'   => 'https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=1600&q=80',
    'education' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=1600&q=80',
    'housing'   => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=1600&q=80',
    'water'     => 'https://images.unsplash.com/photo-1541544537156-7627a7a4aa1c?w=1600&q=80',
    'mosques'   => 'https://images.unsplash.com/photo-1564769662533-4f00a87b4056?w=1600&q=80',
  ];

  $defaultBg  = 'https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=1600&q=80';
  $headerBg   = isset($category) && isset($categoryBgs[$category]) ? $categoryBgs[$category] : $defaultBg;
  $headerIcon = isset($category) && isset($categoryIcons[$category]) ? $categoryIcons[$category] : 'fa-solid fa-hand-holding-heart';

  $titleEn = isset($category) && isset($categoryNamesEn[$category])
    ? $categoryNamesEn[$category] . ' Cases'
    : 'All Donation Cases';

  $titleAr = isset($category) && isset($categoryNamesAr[$category])
    ? 'حالات ' . $categoryNamesAr[$category]
    : 'جميع حالات التبرع';

  $eyebrowEn = isset($category) ? ($categoryNamesEn[$category] ?? 'Donation Cases') : 'Donation Cases';
  $eyebrowAr = isset($category) ? ($categoryNamesAr[$category] ?? 'حالات التبرع') : 'حالات التبرع';
@endphp

<section id="pageHeader">
  <div class="header-bg" style="background-image: url('{{ $headerBg }}')"></div>
  <div class="header-overlay"></div>
  <div class="header-content">
    <div class="header-eyebrow fade-up">
      <i class="{{ $headerIcon }}"></i>
      <span data-en="{{ $eyebrowEn }}" data-ar="{{ $eyebrowAr }}">{{ $eyebrowEn }}</span>
    </div>
    <h1 class="header-title fade-up delay-1"
        data-en="{{ $titleEn }}"
        data-ar="{{ $titleAr }}">{{ $titleEn }}</h1>
    <p class="header-subtitle fade-up delay-2"
       data-en="Every donation you make transforms a life. Browse our verified cases and choose where your contribution goes."
       data-ar="كل تبرع تقدمه يغير حياة. تصفح حالاتنا الموثقة واختر أين تذهب مساهمتك.">
      Every donation you make transforms a life. Browse our verified cases and choose where your contribution goes.
    </p>
    <nav class="header-breadcrumb fade-up delay-3" aria-label="breadcrumb">
      <a href="{{ url('/') }}" data-en="Home" data-ar="الرئيسية">Home</a>
      <i class="fa-solid fa-chevron-right"></i>
      <span data-en="{{ $titleEn }}" data-ar="{{ $titleAr }}">{{ $titleEn }}</span>
    </nav>
  </div>
</section>

{{-- ═══════════ CASES GRID ═══════════ --}}
<section id="casesSection">
  <div class="container">

    {{-- Section heading --}}
    <div class="text-center mb-5 fade-up">
      <p class="section-label"
         data-en="Verified · Transparent · Impactful"
         data-ar="موثقة · شفافة · مؤثرة">
        Verified · Transparent · Impactful
      </p>
      <h2 class="section-title"
          data-en="{{ $titleEn }}"
          data-ar="{{ $titleAr }}">{{ $titleEn }}</h2>
      <div class="section-divider"></div>
      <p class="text-muted mt-2" style="font-size:0.92rem; max-width:540px; margin:0 auto;"
         data-en="{{ $cases->count() }} cases need your support"
         data-ar="{{ $cases->count() }} حالة تحتاج دعمك">
        {{ $cases->count() }} cases need your support
      </p>
    </div>

    @if($cases->isEmpty())
      <div class="empty-state fade-up">
        <i class="fa-solid fa-box-open"></i>
        <h4 data-en="No cases found" data-ar="لا توجد حالات">No cases found</h4>
        <p data-en="There are no donation cases in this category at the moment."
           data-ar="لا توجد حالات تبرع في هذه الفئة في الوقت الحالي.">
          There are no donation cases in this category at the moment.
        </p>
        <a href="{{ url('/') }}" class="btn-donate mt-4" style="display:inline-block; width:auto; padding:12px 34px;"
           data-en="Back to Home" data-ar="العودة للرئيسية">Back to Home</a>
      </div>
    @else
      <div class="row g-4">
        @foreach($cases as $index => $case)
          @php
            $pct      = $case['goal'] > 0 ? round(($case['raised'] / $case['goal']) * 100) : 0;
            $pct      = min($pct, 100);
            $delayMap = ['', 'delay-1', 'delay-2', 'delay-3', 'delay-4', 'delay-5'];
            $delay    = $delayMap[$index % 6] ?? '';

            $catIconMap = [
              'health'    => 'fa-heart-pulse',
              'orphans'   => 'fa-child-reaching',
              'education' => 'fa-graduation-cap',
              'housing'   => 'fa-house',
              'water'     => 'fa-droplet',
              'mosques'   => 'fa-mosque',
            ];
            $catLabelEn = $categoryNamesEn[$case['category']] ?? ucfirst($case['category']);
            $catLabelAr = $categoryNamesAr[$case['category']] ?? $case['category'];
            $icon       = $catIconMap[$case['category']] ?? 'fa-hand-holding-heart';
          @endphp
          <div class="col-sm-6 col-lg-4">
            <div class="case-card fade-up {{ $delay }}">

              {{-- Image --}}
              <a href="{{ route('cases.show', ['locale' => $locale, 'id' => $case['id']]) }}" class="case-img-wrap d-block">
                <img src="{{ $case['image_url'] }}"
                     alt="{{ $case['title_en'] }}"
                     loading="lazy"/>
                <span class="case-tag"
                      data-en="{{ $catLabelEn }}"
                      data-ar="{{ $catLabelAr }}">{{ $catLabelEn }}</span>
                <div class="case-icon-badge">
                  <i class="fa-solid {{ $icon }}"></i>
                </div>
              </a>

              {{-- Body --}}
              <div class="case-body">

                <a href="{{ route('cases.show', ['locale' => $locale, 'id' => $case['id']]) }}"
                   class="text-decoration-none">
                  <h3 class="case-title"
                      data-en="{{ $case['title_en'] }}"
                      data-ar="{{ $case['title_ar'] }}">{{ $case['title_en'] }}</h3>
                </a>

                {{-- Progress --}}
                <div class="progress-label">
                  <span data-en="Raised" data-ar="تم جمع">Raised</span>
                  <span class="pct">{{ $pct }}%</span>
                </div>
                <div class="progress">
                  <div class="progress-bar"
                       role="progressbar"
                       style="width: 0%"
                       data-target="{{ $pct }}"
                       aria-valuenow="{{ $pct }}"
                       aria-valuemin="0"
                       aria-valuemax="100">
                  </div>
                </div>

                {{-- Raised / Goal --}}
                <div class="case-amounts">
                  <div class="amount-item">
                    <i class="fa-solid fa-arrow-trend-up"></i>
                    <span data-en="Raised" data-ar="جُمع">Raised</span>
                    <strong>{{ number_format($case['raised']) }} <span data-en="SAR" data-ar="ر.س">SAR</span></strong>
                  </div>
                  <div class="amount-item">
                    <i class="fa-solid fa-bullseye"></i>
                    <span data-en="Goal" data-ar="الهدف">Goal</span>
                    <strong>{{ number_format($case['goal']) }} <span data-en="SAR" data-ar="ر.س">SAR</span></strong>
                  </div>
                </div>

                {{-- Donate Button --}}
                <a href="#"
                   class="btn-donate"
                   data-en="Donate Now ♥"
                   data-ar="تبرع الآن ♥">Donate Now ♥</a>

              </div>{{-- /.case-body --}}
            </div>{{-- /.case-card --}}
          </div>{{-- /.col --}}
        @endforeach
      </div>{{-- /.row --}}
    @endif

  </div>
</section>

{{-- ═══════════ FOOTER ═══════════ --}}
<footer>
  <div class="container">
    <div class="row g-5">

      <div class="col-lg-4">
        <div class="footer-brand-main"
             data-en="Al-Kalimah Foundation"
             data-ar="مؤسسة الكلم الطيب">Al-Kalimah Foundation</div>
        <div class="footer-brand-sub"
             data-en="For Good &amp; Giving"
             data-ar="للخير والعطاء">For Good &amp; Giving</div>
        <p class="footer-about"
           data-en="An Islamic charity foundation striving to alleviate suffering from the most vulnerable communities in Africa and beyond, through integrated and sustainable development projects."
           data-ar="مؤسسة خيرية إسلامية تسعى لرفع المعاناة عن المجتمعات الأكثر احتياجاً في أفريقيا وخارجها، عبر مشاريع تنموية متكاملة ومستدامة">
          An Islamic charity foundation striving to alleviate suffering from the most vulnerable communities in Africa and beyond, through integrated and sustainable development projects.
        </p>
        <div class="social-links">
          <a href="#" class="social-btn" title="Twitter"><i class="fa-brands fa-x-twitter"></i></a>
          <a href="#" class="social-btn" title="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
          <a href="#" class="social-btn" title="Instagram"><i class="fa-brands fa-instagram"></i></a>
          <a href="#" class="social-btn" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
          <a href="#" class="social-btn" title="YouTube"><i class="fa-brands fa-youtube"></i></a>
        </div>
      </div>

      <div class="col-6 col-lg-2">
        <h6 class="footer-heading"
            data-en="Quick Links"
            data-ar="روابط سريعة">Quick Links</h6>
        <ul class="footer-links">
          <li><a href="{{ url('/') }}#" data-en="About Us" data-ar="من نحن"><i class="fa-solid fa-chevron-right"></i> About Us</a></li>
          <li><a href="#casesSection" data-en="Donation Cases" data-ar="حالات التبرع"><i class="fa-solid fa-chevron-right"></i> Donation Cases</a></li>
          <li><a href="{{ url('/') }}#services" data-en="Services" data-ar="الخدمات"><i class="fa-solid fa-chevron-right"></i> Services</a></li>
          <li><a href="{{ url('/') }}#impactAreas" data-en="Our Projects" data-ar="مشاريعنا"><i class="fa-solid fa-chevron-right"></i> Our Projects</a></li>
          <li><a href="#" data-en="News &amp; Reports" data-ar="أخبار وتقارير"><i class="fa-solid fa-chevron-right"></i> News &amp; Reports</a></li>
        </ul>
      </div>

      <div class="col-6 col-lg-2">
        <h6 class="footer-heading"
            data-en="Help"
            data-ar="المساعدة">Help</h6>
        <ul class="footer-links">
          <li><a href="#" data-en="FAQ" data-ar="الأسئلة الشائعة"><i class="fa-solid fa-chevron-right"></i> FAQ</a></li>
          <li><a href="#" data-en="Privacy Policy" data-ar="سياسة الخصوصية"><i class="fa-solid fa-chevron-right"></i> Privacy Policy</a></li>
          <li><a href="#" data-en="Terms &amp; Conditions" data-ar="الشروط والأحكام"><i class="fa-solid fa-chevron-right"></i> Terms &amp; Conditions</a></li>
          <li><a href="#" data-en="Payment Methods" data-ar="طرق الدفع"><i class="fa-solid fa-chevron-right"></i> Payment Methods</a></li>
          <li><a href="#" data-en="Contact Us" data-ar="اتصل بنا"><i class="fa-solid fa-chevron-right"></i> Contact Us</a></li>
        </ul>
      </div>

      <div class="col-lg-4">
        <h6 class="footer-heading"
            data-en="Contact Us"
            data-ar="تواصل معنا">Contact Us</h6>
        <div class="footer-contact-item">
          <i class="fa-solid fa-location-dot"></i>
          <span data-en="Khartoum, Sudan — Riyadh, Saudi Arabia"
                data-ar="الخرطوم، السودان — المملكة العربية السعودية، الرياض">
            Khartoum, Sudan — Riyadh, Saudi Arabia
          </span>
        </div>
        <div class="footer-contact-item">
          <i class="fa-solid fa-phone"></i>
          <span dir="ltr">+966 50 000 0000</span>
        </div>
        <div class="footer-contact-item">
          <i class="fa-solid fa-envelope"></i>
          <span>info@alkalimah.org</span>
        </div>
        <div style="margin-top:20px;">
          <p style="font-size:0.8rem;color:rgba(255,255,255,0.42);margin-bottom:10px;"
             data-en="Subscribe to our newsletter:"
             data-ar="اشترك في نشرتنا البريدية:">Subscribe to our newsletter:</p>
          <div style="display:flex;gap:8px;">
            <input class="footer-newsletter-input" type="email"
                   data-en-placeholder="Your email address"
                   data-ar-placeholder="بريدك الإلكتروني"
                   placeholder="Your email address"/>
            <button class="footer-newsletter-btn"
                    data-en="Subscribe"
                    data-ar="اشتراك">Subscribe</button>
          </div>
        </div>
      </div>

    </div>

    <div class="footer-bottom">
      <p data-en="© 2025 Al-Kalimah Foundation. All Rights Reserved."
         data-ar="© 2025 مؤسسة الكلم الطيب. جميع الحقوق محفوظة.">
        © 2025 Al-Kalimah Foundation. All Rights Reserved.
      </p>
      <p>
        <span data-en="Designed with" data-ar="صُمِّم بـ">Designed with</span>
        <span style="color:var(--accent)"> ♥ </span>
        <span data-en="for humanity —" data-ar="لخدمة الإنسانية —">for humanity —</span>
        <a href="{{ url('/') }}" data-en="Al-Kalimah Foundation" data-ar="مؤسسة الكلم الطيب">Al-Kalimah Foundation</a>
      </p>
    </div>
  </div>
</footer>

<button id="backToTop" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top">
  <i class="fa-solid fa-chevron-up"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BOOTSTRAP_LTR = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
const BOOTSTRAP_RTL = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css';

let currentLang = localStorage.getItem('alkLang') || 'en';

function setLang(lang) {
  currentLang = lang;
  localStorage.setItem('alkLang', lang);

  const html   = document.getElementById('htmlRoot');
  const bsCSS  = document.getElementById('bootstrapCSS');

  html.setAttribute('lang', lang);
  html.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
  bsCSS.href = lang === 'ar' ? BOOTSTRAP_RTL : BOOTSTRAP_LTR;

  document.querySelectorAll('[data-en], [data-ar]').forEach(el => {
    const val = el.getAttribute('data-' + lang);
    if (val !== null) el.innerHTML = val;
  });

  document.querySelectorAll('[data-en-placeholder]').forEach(el => {
    el.placeholder = el.getAttribute('data-' + lang + '-placeholder') || '';
  });

  document.getElementById('btnEN').classList.toggle('active', lang === 'en');
  document.getElementById('btnAR').classList.toggle('active', lang === 'ar');

  document.querySelectorAll('.footer-links a i').forEach(icon => {
    icon.className = lang === 'ar' ? 'fa-solid fa-chevron-left' : 'fa-solid fa-chevron-right';
  });

  // Breadcrumb chevron direction
  document.querySelectorAll('.header-breadcrumb i').forEach(icon => {
    icon.className = lang === 'ar' ? 'fa-solid fa-chevron-left' : 'fa-solid fa-chevron-right';
  });
}

// Navbar scroll
const nav = document.getElementById('mainNav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 55);
  document.getElementById('backToTop').classList.toggle('visible', window.scrollY > 420);
}, { passive: true });

// Scroll fade-in + progress bar animation
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (!e.isIntersecting) return;
    e.target.classList.add('visible');
    // Animate progress bar if inside this card
    e.target.querySelectorAll('.progress-bar[data-target]').forEach(bar => {
      bar.style.width = bar.dataset.target + '%';
    });
  });
}, { threshold: 0.12 });

document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));

// Apply language on load
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => setLang(currentLang), 60);
});
</script>

</body>
</html>
