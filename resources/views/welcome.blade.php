<!DOCTYPE html>
<html lang="en" dir="ltr" id="htmlRoot">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Al-Kalimah Foundation — مؤسسة الكلم الطيب</title>

  <!-- ═══════════════════════════════════════════════
       BOOTSTRAP 5.3 — loaded dynamically via JS
       (LTR default; JS swaps to RTL build when Arabic)
  ═══════════════════════════════════════════════ -->
  <link id="bootstrapCSS"
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"/>

  <!-- FONT AWESOME 6 -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>

  <!-- GOOGLE FONTS: Montserrat (EN) + Cairo + Amiri (AR) -->
  <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@300;400;600;700;900&family=Montserrat:wght@300;400;600;700;900&display=swap"
        rel="stylesheet"/>

  <style>
    /* ╔══════════════════════════════════════╗
       ║         CSS CUSTOM PROPERTIES        ║
       ╚══════════════════════════════════════╝ */
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

      /* Typography — swapped by JS */
      --font-main:    'Montserrat', sans-serif;
      --font-display: 'Montserrat', sans-serif;
    }

    /* Arabic font override — applied to <html> when lang=ar */
    html[lang="ar"] {
      --font-main:    'Cairo', sans-serif;
      --font-display: 'Amiri', serif;
    }

    /* ╔══════════════════════════════════════╗
       ║              GLOBAL RESET            ║
       ╚══════════════════════════════════════╝ */
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

    /* Smooth language transition */
    body * { transition: font-family 0.2s; }

    /* ╔══════════════════════════════════════╗
       ║           LANGUAGE SWITCHER          ║
       ╚══════════════════════════════════════╝ */
    .lang-switcher {
      display: flex;
      align-items: center;
      background: rgba(255,255,255,0.12);
      border-radius: 100px;
      padding: 3px;
      border: 1px solid rgba(255,255,255,0.25);
      backdrop-filter: blur(6px);
      transition: background 0.4s;
      gap: 2px;
    }
    #mainNav.scrolled .lang-switcher {
      background: rgba(13,43,69,0.08);
      border-color: var(--mid-gray);
    }
    .lang-btn {
      background: transparent;
      border: none;
      color: rgba(255,255,255,0.75);
      font-family: var(--font-main);
      font-size: 0.78rem;
      font-weight: 700;
      padding: 5px 13px;
      border-radius: 100px;
      cursor: pointer;
      transition: all 0.25s;
      letter-spacing: 0.5px;
    }
    #mainNav.scrolled .lang-btn { color: var(--text-muted); }
    .lang-btn.active {
      background: var(--accent);
      color: var(--white) !important;
      box-shadow: 0 2px 10px rgba(242,124,49,0.4);
    }

    /* ╔══════════════════════════════════════╗
       ║              NAVBAR                  ║
       ╚══════════════════════════════════════╝ */
    #mainNav {
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 1050;
      transition: background 0.4s ease, box-shadow 0.4s ease, padding 0.4s ease;
      padding: 16px 0;
    }
    #mainNav.scrolled {
      background: rgba(255,255,255,0.97) !important;
      box-shadow: 0 2px 28px rgba(0,0,0,0.08);
      padding: 10px 0;
    }
    .navbar-brand .brand-main {
      font-family: var(--font-display);
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--white);
      line-height: 1.2;
      transition: color 0.4s;
      display: block;
    }
    .navbar-brand .brand-sub {
      font-size: 0.65rem;
      color: var(--primary);
      display: block;
      letter-spacing: 1.5px;
      font-weight: 600;
      text-transform: uppercase;
    }
    #mainNav.scrolled .brand-main { color: var(--navy); }
    #mainNav .nav-link {
      color: rgba(255,255,255,0.9) !important;
      font-weight: 600;
      font-size: 0.88rem;
      padding: 6px 13px !important;
      border-radius: 7px;
      transition: all 0.25s;
    }
    #mainNav.scrolled .nav-link { color: var(--navy) !important; }
    #mainNav .nav-link:hover {
      color: var(--accent) !important;
      background: rgba(242,124,49,0.08);
    }
    .btn-donate-nav {
      background: var(--accent) !important;
      color: var(--white) !important;
      border-radius: 9px;
      padding: 8px 22px !important;
      font-weight: 700;
      font-size: 0.88rem;
      box-shadow: 0 4px 18px rgba(242,124,49,0.38);
      transition: all 0.25s !important;
    }
    .btn-donate-nav:hover {
      background: var(--accent-dark) !important;
      transform: translateY(-2px);
      box-shadow: 0 7px 24px rgba(242,124,49,0.48) !important;
    }
    #mainNav .navbar-toggler { border: none; outline: none; box-shadow: none; }
    #mainNav .navbar-toggler-icon { filter: invert(1) brightness(2); }
    #mainNav.scrolled .navbar-toggler-icon { filter: none; }

    /* ╔══════════════════════════════════════╗
       ║          USER AUTH MENU              ║
       ╚══════════════════════════════════════╝ */
    .btn-user-menu {
      display: flex;
      align-items: center;
      gap: 8px;
      background: rgba(255,255,255,0.12);
      border: 1px solid rgba(255,255,255,0.25);
      border-radius: 100px;
      padding: 6px 14px 6px 6px;
      color: var(--white);
      font-family: var(--font-main);
      font-weight: 600;
      font-size: 0.85rem;
      cursor: pointer;
      transition: all 0.25s;
      backdrop-filter: blur(6px);
    }
    #mainNav.scrolled .btn-user-menu {
      background: rgba(13,43,69,0.08);
      border-color: var(--mid-gray);
      color: var(--navy);
    }
    .btn-user-menu:hover {
      background: rgba(242,124,49,0.15);
      border-color: var(--accent);
      color: var(--accent);
    }
    .btn-user-menu .user-avatar {
      width: 30px;
      height: 30px;
      background: var(--accent);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--white);
      flex-shrink: 0;
    }
    .btn-user-menu .user-name-label {
      max-width: 110px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .btn-user-menu .hamburger-lines {
      display: flex;
      flex-direction: column;
      gap: 3px;
      margin-left: 4px;
    }
    .btn-user-menu .hamburger-lines span {
      display: block;
      width: 16px;
      height: 2px;
      background: currentColor;
      border-radius: 2px;
    }

    /* ╔══════════════════════════════════════╗
       ║         SLIDE-OUT SIDEBAR            ║
       ╚══════════════════════════════════════╝ */
    #userSidebar {
      width: 290px;
      background: var(--navy);
      border-right: none;
      z-index: 1055;
    }
    html[dir="rtl"] #userSidebar {
      border-left: none;
    }
    .sidebar-header {
      background: var(--navy-light);
      padding: 28px 22px 22px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      position: relative;
    }
    .sidebar-avatar {
      width: 54px;
      height: 54px;
      background: var(--accent);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--white);
      margin-bottom: 12px;
    }
    .sidebar-user-name {
      font-family: var(--font-main);
      font-weight: 700;
      font-size: 1rem;
      color: var(--white);
      margin-bottom: 3px;
      line-height: 1.3;
    }
    .sidebar-user-email {
      font-size: 0.76rem;
      color: rgba(255,255,255,0.45);
      font-family: var(--font-main);
      word-break: break-all;
    }
    .sidebar-close-btn {
      position: absolute;
      top: 14px;
      right: 14px;
      background: rgba(255,255,255,0.1);
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 50%;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: rgba(255,255,255,0.7);
      font-size: 0.75rem;
      cursor: pointer;
      transition: all 0.2s;
    }
    html[dir="rtl"] .sidebar-close-btn { right: auto; left: 14px; }
    .sidebar-close-btn:hover {
      background: rgba(242,124,49,0.25);
      border-color: var(--accent);
      color: var(--white);
    }
    .sidebar-nav-list {
      list-style: none;
      padding: 10px 0;
      margin: 0;
    }
    .sidebar-nav-list li a {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 24px;
      color: rgba(255,255,255,0.78);
      font-family: var(--font-main);
      font-weight: 600;
      font-size: 0.92rem;
      transition: all 0.2s;
      border-left: 3px solid transparent;
      text-decoration: none;
    }
    html[dir="rtl"] .sidebar-nav-list li a {
      border-left: none;
      border-right: 3px solid transparent;
    }
    .sidebar-nav-list li a:hover {
      background: rgba(99,193,231,0.1);
      color: var(--primary);
      border-left-color: var(--primary);
    }
    html[dir="rtl"] .sidebar-nav-list li a:hover {
      border-left-color: transparent;
      border-right-color: var(--primary);
    }
    .sidebar-nav-list li a i {
      width: 20px;
      text-align: center;
      font-size: 0.95rem;
      opacity: 0.85;
      flex-shrink: 0;
    }
    .sidebar-divider {
      border: none;
      border-top: 1px solid rgba(255,255,255,0.1);
      margin: 6px 20px;
    }
    .sidebar-logout-item a {
      color: rgba(242,124,49,0.85) !important;
    }
    .sidebar-logout-item a:hover {
      background: rgba(242,124,49,0.1) !important;
      color: var(--accent) !important;
      border-left-color: var(--accent) !important;
    }
    html[dir="rtl"] .sidebar-logout-item a:hover {
      border-right-color: var(--accent) !important;
    }

    /* ╔══════════════════════════════════════╗
       ║            HERO SLIDER               ║
       ╚══════════════════════════════════════╝ */
    #heroCarousel { height: 100vh; min-height: 580px; }
    #heroCarousel .carousel-item {
      height: 100vh;
      min-height: 580px;
    }
    #heroCarousel .carousel-item img {
      width: 100%; height: 100%;
      object-fit: cover;
      object-position: center;
      filter: brightness(0.5);
    }
    .hero-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(140deg,
        rgba(13,43,69,0.6) 0%,
        rgba(99,193,231,0.15) 100%);
    }
    .hero-content {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      padding: 20px 24px;
    }
    .hero-badge {
      display: inline-block;
      background: rgba(99,193,231,0.18);
      border: 1px solid rgba(99,193,231,0.45);
      color: #a8dff5;
      font-size: 0.78rem;
      font-weight: 600;
      letter-spacing: 2px;
      text-transform: uppercase;
      padding: 6px 22px;
      border-radius: 100px;
      margin-bottom: 18px;
      backdrop-filter: blur(6px);
    }
    html[lang="ar"] .hero-badge { letter-spacing: 0; text-transform: none; font-size: 0.85rem; }
    .hero-title {
      font-family: var(--font-display);
      font-size: clamp(2rem, 5vw, 3.7rem);
      color: var(--white);
      font-weight: 700;
      line-height: 1.42;
      text-shadow: 0 2px 22px rgba(0,0,0,0.3);
      margin-bottom: 14px;
      max-width: 820px;
    }
    .hero-subtitle {
      font-size: clamp(0.95rem, 2.2vw, 1.18rem);
      color: rgba(255,255,255,0.8);
      margin-bottom: 36px;
      max-width: 580px;
      font-weight: 300;
      line-height: 1.7;
    }
    .btn-hero {
      background: var(--accent);
      color: var(--white);
      font-family: var(--font-main);
      font-weight: 700;
      font-size: 1rem;
      padding: 14px 46px;
      border-radius: 12px;
      border: none;
      box-shadow: 0 6px 28px rgba(242,124,49,0.45);
      cursor: pointer;
      transition: all 0.3s;
    }
    .btn-hero:hover {
      background: var(--accent-dark);
      transform: translateY(-3px);
      box-shadow: 0 10px 36px rgba(242,124,49,0.55);
    }
    .carousel-indicators [data-bs-target] {
      width: 10px; height: 10px;
      border-radius: 50%;
      background: rgba(255,255,255,0.45);
      border: none;
      margin: 0 5px;
      transition: all 0.3s;
    }
    .carousel-indicators .active {
      background: var(--accent);
      width: 28px;
      border-radius: 6px;
    }
    .carousel-control-prev-icon,
    .carousel-control-next-icon {
      background-color: rgba(255,255,255,0.12);
      border-radius: 50%;
      padding: 22px;
      backdrop-filter: blur(4px);
    }

    /* ╔══════════════════════════════════════╗
       ║           SECTION COMMON             ║
       ╚══════════════════════════════════════╝ */
    .section-label {
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 3px;
      color: var(--primary-dark);
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    html[lang="ar"] .section-label { letter-spacing: 0; font-size: 0.82rem; }
    .section-title {
      font-size: clamp(1.6rem, 3vw, 2.3rem);
      font-weight: 900;
      color: var(--navy);
      margin-bottom: 6px;
    }
    .section-divider {
      width: 52px; height: 4px;
      background: linear-gradient(90deg, var(--accent), var(--primary));
      border-radius: 4px;
      margin: 0 auto 14px;
    }

    /* ╔══════════════════════════════════════╗
       ║          DONATION CASES              ║
       ╚══════════════════════════════════════╝ */
    #donationCases {
      background: var(--light-gray);
      padding: 80px 0;
    }
    .cases-scroll-wrapper {
      overflow-x: auto;
      padding: 10px 4px 22px;
      scrollbar-width: thin;
      scrollbar-color: var(--primary) var(--mid-gray);
    }
    .cases-scroll-wrapper::-webkit-scrollbar { height: 5px; }
    .cases-scroll-wrapper::-webkit-scrollbar-thumb {
      background: var(--primary); border-radius: 4px;
    }
    .cases-row { display: flex; gap: 22px; width: max-content; }

    .case-card {
      background: var(--white);
      border-radius: 18px;
      overflow: hidden;
      width: 295px;
      flex-shrink: 0;
      box-shadow: 0 4px 24px rgba(0,0,0,0.07);
      transition: transform 0.3s, box-shadow 0.3s;
    }
    .case-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 14px 40px rgba(0,0,0,0.12);
    }
    .case-img-wrap {
      position: relative;
      height: 178px;
      overflow: hidden;
    }
    .case-img-wrap img {
      width: 100%; height: 100%;
      object-fit: cover;
      transition: transform 0.5s;
    }
    .case-card:hover .case-img-wrap img { transform: scale(1.06); }
    .case-icon-badge {
      position: absolute;
      bottom: -20px;
      right: 18px;
      width: 44px; height: 44px;
      background: var(--accent);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: var(--white);
      font-size: 1.05rem;
      box-shadow: 0 4px 14px rgba(242,124,49,0.42);
      z-index: 2;
    }
    html[dir="ltr"] .case-icon-badge { right: auto; left: 18px; }
    .case-tag {
      position: absolute;
      top: 12px; right: 12px;
      background: var(--primary);
      color: var(--white);
      font-size: 0.7rem;
      font-weight: 700;
      padding: 4px 12px;
      border-radius: 100px;
      letter-spacing: 0.5px;
    }
    html[dir="ltr"] .case-tag { right: auto; left: 12px; }
    .case-body { padding: 30px 18px 18px; }
    .case-title { font-size: 0.97rem; font-weight: 700; color: var(--navy); margin-bottom: 10px; }
    .case-progress-label {
      display: flex;
      justify-content: space-between;
      font-size: 0.73rem;
      color: var(--text-muted);
      margin-bottom: 5px;
    }
    .case-progress-label .pct { color: var(--accent); font-weight: 700; }
    .progress {
      height: 7px;
      border-radius: 10px;
      background: var(--mid-gray);
      margin-bottom: 13px;
      overflow: visible;
    }
    .progress-bar {
      background: linear-gradient(90deg, var(--accent), #f5a55a);
      border-radius: 10px;
      position: relative;
      transition: width 1.2s ease;
    }
    .progress-bar::after {
      content: '';
      position: absolute;
      left: -5px; top: 50%;
      transform: translateY(-50%);
      width: 14px; height: 14px;
      background: var(--accent);
      border-radius: 50%;
      border: 2px solid var(--white);
      box-shadow: 0 0 0 3px rgba(242,124,49,0.22);
    }
    html[dir="ltr"] .progress-bar::after { left: auto; right: -5px; }
    .case-meta {
      display: flex;
      gap: 10px;
      font-size: 0.73rem;
      color: var(--text-muted);
      margin-bottom: 14px;
      flex-wrap: wrap;
    }
    .case-meta span { display: flex; align-items: center; gap: 4px; }
    .case-meta strong { color: var(--navy); font-weight: 700; }
    .case-input-group { display: flex; gap: 8px; }
    .case-input-group input {
      flex: 1;
      border: 1.5px solid var(--mid-gray);
      border-radius: 8px;
      padding: 8px 12px;
      font-family: var(--font-main);
      font-size: 0.84rem;
      color: var(--navy);
      outline: none;
      transition: border 0.2s;
    }
    .case-input-group input:focus { border-color: var(--primary); }
    .case-input-group input::placeholder { color: var(--text-muted); }
    .btn-case {
      background: var(--accent);
      color: var(--white);
      border: none;
      border-radius: 8px;
      padding: 8px 16px;
      font-family: var(--font-main);
      font-weight: 700;
      font-size: 0.8rem;
      cursor: pointer;
      transition: all 0.25s;
      white-space: nowrap;
    }
    .btn-case:hover { background: var(--accent-dark); transform: scale(1.04); }

    /* ╔══════════════════════════════════════╗
       ║          QURAN SEPARATOR             ║
       ╚══════════════════════════════════════╝ */
    #quranSep {
      background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
      padding: 68px 20px;
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    #quranSep::before {
      content: '﴾';
      position: absolute; left: -28px; top: -18px;
      font-size: 12rem;
      color: rgba(99,193,231,0.06);
      font-family: 'Amiri', serif;
    }
    #quranSep::after {
      content: '﴿';
      position: absolute; right: -28px; bottom: -18px;
      font-size: 12rem;
      color: rgba(99,193,231,0.06);
      font-family: 'Amiri', serif;
    }
    .quran-ornament {
      display: flex; align-items: center; justify-content: center;
      gap: 14px; margin-bottom: 22px;
    }
    .quran-ornament::before, .quran-ornament::after {
      content: '';
      flex: 1; max-width: 90px; height: 1px;
      background: linear-gradient(90deg, transparent, rgba(99,193,231,0.5));
    }
    .quran-ornament-icon { color: var(--primary); font-size: 1.2rem; }
    .quran-arabic {
      font-family: 'Amiri', serif;
      font-size: clamp(1.5rem, 3.5vw, 2.4rem);
      color: var(--white);
      line-height: 1.8;
      margin-bottom: 10px;
    }
    .quran-translation {
      font-size: clamp(0.88rem, 1.5vw, 1.05rem);
      color: rgba(255,255,255,0.65);
      font-style: italic;
      font-weight: 300;
      max-width: 640px;
      margin: 0 auto 10px;
      line-height: 1.7;
    }
    .quran-ref { color: var(--primary); font-size: 0.85rem; font-weight: 600; }

    /* ╔══════════════════════════════════════╗
       ║          IMPACT AREAS GRID           ║
       ╚══════════════════════════════════════╝ */
    #impactAreas { padding: 80px 0; background: var(--white); }
    .impact-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(275px, 1fr));
      gap: 20px;
    }
    .impact-tile {
      position: relative;
      border-radius: 18px;
      overflow: hidden;
      aspect-ratio: 1/1;
      cursor: pointer;
      box-shadow: 0 4px 22px rgba(0,0,0,0.1);
    }
    .impact-tile img {
      width: 100%; height: 100%;
      object-fit: cover;
      transition: transform 0.6s;
    }
    .impact-tile:hover img { transform: scale(1.08); }
    .impact-tile-overlay {
      position: absolute; inset: 0;
      background: linear-gradient(160deg,
        rgba(99,193,231,0.68) 0%,
        rgba(13,43,69,0.68) 100%);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px;
      transition: background 0.35s;
    }
    .impact-tile:hover .impact-tile-overlay {
      background: linear-gradient(160deg,
        rgba(99,193,231,0.82) 0%,
        rgba(13,43,69,0.8) 100%);
    }
    .impact-tile-icon {
      font-size: 2.2rem; color: var(--white);
      margin-bottom: 12px;
      text-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    .impact-tile-title {
      font-size: 1.15rem; font-weight: 800;
      color: var(--white); margin-bottom: 7px; text-align: center;
    }
    .impact-tile-desc {
      font-size: 0.8rem;
      color: rgba(255,255,255,0.85);
      text-align: center; line-height: 1.6;
    }

    /* ╔══════════════════════════════════════╗
       ║              SERVICES                ║
       ╚══════════════════════════════════════╝ */
    #services { background: var(--light-gray); padding: 80px 0; }
    .service-card {
      background: var(--white);
      border-radius: 16px;
      padding: 36px 22px;
      text-align: center;
      box-shadow: 0 2px 16px rgba(0,0,0,0.05);
      border: 1.5px solid transparent;
      transition: all 0.3s;
      height: 100%;
    }
    .service-card:hover {
      border-color: var(--primary);
      transform: translateY(-5px);
      box-shadow: 0 10px 32px rgba(99,193,231,0.18);
    }
    .service-icon {
      width: 68px; height: 68px;
      border-radius: 18px;
      background: linear-gradient(135deg, rgba(99,193,231,0.12), rgba(99,193,231,0.22));
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 18px;
      font-size: 1.75rem;
      color: var(--primary-dark);
      transition: all 0.3s;
    }
    .service-card:hover .service-icon {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: var(--white);
    }
    .service-title { font-size: 1rem; font-weight: 700; color: var(--navy); margin-bottom: 9px; }
    .service-desc { font-size: 0.83rem; color: var(--text-muted); line-height: 1.72; }
    .service-link {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: 0.8rem; font-weight: 700;
      color: var(--accent); margin-top: 14px;
      transition: gap 0.2s;
    }
    .service-link:hover { gap: 10px; color: var(--accent-dark); }

    /* ╔══════════════════════════════════════╗
       ║            STATISTICS                ║
       ╚══════════════════════════════════════╝ */
    #statistics {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      padding: 80px 0;
      position: relative;
      overflow: hidden;
    }
    #statistics::before {
      content: '';
      position: absolute;
      width: 600px; height: 600px;
      border-radius: 50%;
      background: rgba(255,255,255,0.05);
      top: -200px; left: -200px;
      pointer-events: none;
    }
    .stat-item { text-align: center; padding: 18px; }
    .stat-icon { font-size: 1.8rem; color: rgba(255,255,255,0.3); margin-bottom: 10px; }
    .stat-number {
      font-size: clamp(2.4rem, 5vw, 3.8rem);
      font-weight: 900;
      color: var(--accent);
      line-height: 1;
      margin-bottom: 8px;
      text-shadow: 0 2px 10px rgba(0,0,0,0.12);
    }
    .stat-label { font-size: 0.95rem; color: rgba(255,255,255,0.88); font-weight: 600; }

    /* ╔══════════════════════════════════════╗
       ║              PARTNERS                ║
       ╚══════════════════════════════════════╝ */
    #partners { padding: 70px 0; background: var(--white); }
    .partners-track-wrapper {
      overflow: hidden;
      position: relative;
    }
    .partners-track-wrapper::before,
    .partners-track-wrapper::after {
      content: '';
      position: absolute;
      top: 0; bottom: 0;
      width: 80px; z-index: 2;
    }
    .partners-track-wrapper::before {
      left: 0;
      background: linear-gradient(90deg, var(--white), transparent);
    }
    .partners-track-wrapper::after {
      right: 0;
      background: linear-gradient(-90deg, var(--white), transparent);
    }
    .partners-track {
      display: flex; gap: 36px;
      animation: scrollPartners 24s linear infinite;
      width: max-content;
    }
    .partners-track:hover { animation-play-state: paused; }
    @keyframes scrollPartners {
      0%   { transform: translateX(0); }
      100% { transform: translateX(-50%); }
    }
    .partner-logo {
      background: var(--light-gray);
      border-radius: 14px;
      width: 155px; height: 78px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      font-family: var(--font-display);
      font-size: 1rem;
      font-weight: 700;
      color: var(--navy);
      opacity: 0.6;
      border: 1.5px solid var(--mid-gray);
      transition: opacity 0.3s, transform 0.3s;
    }
    .partner-logo:hover { opacity: 1; transform: scale(1.05); }

    /* ╔══════════════════════════════════════╗
       ║               FOOTER                 ║
       ╚══════════════════════════════════════╝ */
    footer {
      background: var(--navy);
      color: rgba(255,255,255,0.7);
      padding: 60px 0 0;
    }
    .footer-brand-main {
      font-family: var(--font-display);
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--white);
    }
    .footer-brand-sub {
      font-size: 0.65rem;
      color: var(--primary);
      letter-spacing: 1.5px;
      text-transform: uppercase;
      font-weight: 600;
    }
    .footer-about {
      font-size: 0.84rem;
      line-height: 1.82;
      color: rgba(255,255,255,0.55);
      margin-top: 12px;
      max-width: 300px;
    }
    .footer-heading {
      color: var(--white);
      font-weight: 700;
      font-size: 0.98rem;
      margin-bottom: 16px;
      position: relative;
      padding-bottom: 10px;
    }
    .footer-heading::after {
      content: '';
      position: absolute;
      bottom: 0; left: 0;
      width: 34px; height: 3px;
      background: var(--accent);
      border-radius: 2px;
    }
    html[dir="rtl"] .footer-heading::after { left: auto; right: 0; }
    .footer-links { list-style: none; padding: 0; margin: 0; }
    .footer-links li { margin-bottom: 9px; }
    .footer-links a {
      color: rgba(255,255,255,0.55);
      font-size: 0.86rem;
      display: flex; align-items: center; gap: 8px;
      transition: color 0.25s, gap 0.25s;
    }
    .footer-links a:hover { color: var(--primary); gap: 12px; }
    .footer-links a i { color: var(--accent); font-size: 0.65rem; }
    .social-links { display: flex; gap: 9px; margin-top: 16px; flex-wrap: wrap; }
    .social-btn {
      width: 40px; height: 40px;
      border-radius: 10px;
      background: rgba(255,255,255,0.07);
      display: flex; align-items: center; justify-content: center;
      color: rgba(255,255,255,0.65);
      font-size: 0.92rem;
      border: 1px solid rgba(255,255,255,0.1);
      transition: all 0.25s;
    }
    .social-btn:hover {
      background: var(--accent);
      color: var(--white);
      border-color: var(--accent);
      transform: translateY(-2px);
    }
    .footer-contact-item {
      display: flex; align-items: flex-start; gap: 11px;
      margin-bottom: 13px;
      font-size: 0.84rem;
      color: rgba(255,255,255,0.55);
    }
    .footer-contact-item i { color: var(--primary); margin-top: 2px; flex-shrink: 0; }
    .footer-newsletter-input {
      flex: 1;
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 8px;
      padding: 10px 14px;
      color: white;
      font-family: var(--font-main);
      font-size: 0.83rem;
      outline: none;
      transition: border 0.2s;
    }
    .footer-newsletter-input:focus { border-color: var(--primary); }
    .footer-newsletter-input::placeholder { color: rgba(255,255,255,0.35); }
    .footer-newsletter-btn {
      background: var(--accent);
      color: var(--white);
      border: none;
      border-radius: 8px;
      padding: 10px 16px;
      cursor: pointer;
      font-family: var(--font-main);
      font-weight: 700;
      font-size: 0.8rem;
      transition: all 0.25s;
      white-space: nowrap;
    }
    .footer-newsletter-btn:hover { background: var(--accent-dark); }
    .footer-bottom {
      border-top: 1px solid rgba(255,255,255,0.08);
      padding: 18px 0;
      margin-top: 48px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
    }
    .footer-bottom p { margin: 0; font-size: 0.78rem; color: rgba(255,255,255,0.4); }
    .footer-bottom a { color: var(--primary); }

    /* ╔══════════════════════════════════════╗
       ║           BACK TO TOP BTN            ║
       ╚══════════════════════════════════════╝ */
    #backToTop {
      position: fixed;
      bottom: 28px; left: 28px;
      width: 46px; height: 46px;
      background: var(--accent);
      color: var(--white);
      border: none;
      border-radius: 12px;
      font-size: 1rem;
      cursor: pointer;
      box-shadow: 0 4px 18px rgba(242,124,49,0.42);
      opacity: 0;
      pointer-events: none;
      transition: all 0.3s;
      z-index: 999;
    }
    #backToTop.visible { opacity: 1; pointer-events: all; }
    #backToTop:hover { background: var(--accent-dark); transform: translateY(-3px); }

    /* ╔══════════════════════════════════════╗
       ║         SCROLL ANIMATIONS            ║
       ╚══════════════════════════════════════╝ */
    .fade-up {
      opacity: 0;
      transform: translateY(28px);
      transition: opacity 0.65s ease, transform 0.65s ease;
    }
    .fade-up.visible { opacity: 1; transform: translateY(0); }
    .delay-1 { transition-delay: 0.1s; }
    .delay-2 { transition-delay: 0.2s; }
    .delay-3 { transition-delay: 0.3s; }
    .delay-4 { transition-delay: 0.4s; }
    .delay-5 { transition-delay: 0.5s; }
    .delay-6 { transition-delay: 0.6s; }

    /* ╔══════════════════════════════════════╗
       ║           RESPONSIVE                 ║
       ╚══════════════════════════════════════╝ */
    @media (max-width: 767px) {
      .impact-grid { grid-template-columns: 1fr 1fr; }
      .footer-bottom { flex-direction: column; text-align: center; }
      .footer-about { max-width: 100%; }
      .footer-heading::after { display: none; }
    }
    @media (max-width: 480px) {
      .impact-grid { grid-template-columns: 1fr; }
      .case-card { width: 268px; }
    }
  </style>
</head>

<body>

<!-- ═══════════════════════════════════════
     SECTION: NAVBAR
═══════════════════════════════════════ -->
<nav id="mainNav" class="navbar navbar-expand-lg">
  <div class="container">

    <!-- Brand -->
    <a class="navbar-brand" href="#">
      <span class="brand-main" data-en="Al-Kalimah Foundation" data-ar="مؤسسة الكلم الطيب">Al-Kalimah Foundation</span>
      <span class="brand-sub" data-en="For Good &amp; Giving" data-ar="للخير والعطاء">For Good &amp; Giving</span>
    </a>

    <!-- Toggler -->
    <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Nav Links -->
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-1">
        <li class="nav-item">
          <a class="nav-link" href="#"
             data-en="Home" data-ar="الرئيسية">Home</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#donationCases"
             data-en="Donation Cases" data-ar="حالات التبرع">Donation Cases</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#services"
             data-en="Services" data-ar="الخدمات">Services</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#impactAreas"
             data-en="Impact Areas" data-ar="مجالات التأثير">Impact Areas</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#partners"
             data-en="About Us" data-ar="عن المؤسسة">About Us</a>
        </li>
        @guest
        <li class="nav-item">
          <a class="nav-link" href="{{ route('login') }}"
             data-en="Login" data-ar="تسجيل الدخول">Login</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="{{ route('register') }}"
             data-en="Register" data-ar="إنشاء حساب">Register</a>
        </li>
        @endguest
      </ul>

      <!-- Right Controls -->
      <div class="d-flex align-items-center gap-3 mt-3 mt-lg-0">
        <!-- Language Switcher -->
        <div class="lang-switcher">
          <button class="lang-btn active" id="btnEN" onclick="setLang('en')">EN</button>
          <button class="lang-btn" id="btnAR" onclick="setLang('ar')">عر</button>
        </div>
        @auth
        <!-- Authenticated User Menu Trigger -->
        <button class="btn-user-menu" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#userSidebar"
                aria-controls="userSidebar">
          <span class="user-avatar">{{ strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}</span>
          <span class="user-name-label">{{ Auth::user()->name }}</span>
          <span class="hamburger-lines">
            <span></span><span></span><span></span>
          </span>
        </button>
        @endauth
        <!-- Donate Button -->
        <a href="#donationCases"
           class="nav-link btn-donate-nav"
           data-en="Donate Now ♥" data-ar="تبرع الآن ♥">Donate Now ♥</a>
      </div>
    </div>
  </div>
</nav>

@auth
<!-- ═══════════════════════════════════════
     USER SIDEBAR (authenticated users)
═══════════════════════════════════════ -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="userSidebar" aria-labelledby="userSidebarLabel">
  <!-- Sidebar Header -->
  <div class="sidebar-header">
    <div class="sidebar-avatar">{{ strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}</div>
    <div class="sidebar-user-name" id="userSidebarLabel">{{ Auth::user()->name }}</div>
    <div class="sidebar-user-email">{{ Auth::user()->email }}</div>
    <button type="button" class="sidebar-close-btn" data-bs-dismiss="offcanvas" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
  </div>

  <!-- Sidebar Navigation -->
  <ul class="sidebar-nav-list">
    <li>
      <a href="#">
        <i class="fas fa-heart"></i>
        <span data-en="My Donations" data-ar="تبرعاتي">My Donations</span>
      </a>
    </li>
    <li>
      <a href="#">
        <i class="fas fa-paper-plane"></i>
        <span data-en="Submit a Request" data-ar="تقديم طلب">Submit a Request</span>
      </a>
    </li>
    <li>
      <a href="{{ route('profile.edit') }}">
        <i class="fas fa-user-circle"></i>
        <span data-en="Profile" data-ar="الملف الشخصي">Profile</span>
      </a>
    </li>
    <li><hr class="sidebar-divider"></li>
    <li class="sidebar-logout-item">
      <form method="POST" action="{{ route('logout') }}" id="sidebar-logout-form">
        @csrf
        <a href="{{ route('logout') }}"
           onclick="event.preventDefault(); document.getElementById('sidebar-logout-form').submit();">
          <i class="fas fa-sign-out-alt"></i>
          <span data-en="Logout" data-ar="تسجيل الخروج">Logout</span>
        </a>
      </form>
    </li>
  </ul>
</div>
@endauth

<!-- ═══════════════════════════════════════
     SECTION: HERO SLIDER
═══════════════════════════════════════ -->
<div id="heroCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="5800">

  <div class="carousel-indicators">
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active"></button>
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1"></button>
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="2"></button>
  </div>

  <div class="carousel-inner">

    <!-- Slide 1: Aid / Children -->
    <div class="carousel-item active">
      <img src="https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=1600&q=80"
           alt="Sudan Aid"/>
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <span class="hero-badge"
              data-en="Al-Kalimah Foundation for Good &amp; Giving"
              data-ar="مؤسسة الكلم الطيب للخير والعطاء">
          Al-Kalimah Foundation for Good &amp; Giving
        </span>
        <h1 class="hero-title"
            data-en="Your Donation Makes a Difference<br/>Start Your Journey of Giving Today"
            data-ar="تبرعك يصنع الفرق<br/>ابدأ رحلة العطاء اليوم">
          Your Donation Makes a Difference<br/>Start Your Journey of Giving Today
        </h1>
        <p class="hero-subtitle"
           data-en="We support the most vulnerable families in Africa and beyond — health, education, shelter, and clean water."
           data-ar="نساعد الأسر الأكثر احتياجاً في أفريقيا وخارجها — صحة، تعليم، إيواء، ومياه نظيفة">
          We support the most vulnerable families in Africa and beyond — health, education, shelter, and clean water.
        </p>
        <button class="btn-hero"
                data-en="❤ Donate Now" data-ar="❤ تبرع الآن"
                onclick="document.getElementById('donationCases').scrollIntoView({behavior:'smooth'})">
          ❤ Donate Now
        </button>
      </div>
    </div>

    <!-- Slide 2: Mosque Construction -->
    <div class="carousel-item">
      <img src="https://images.unsplash.com/photo-1542810634-71277d95dcbb?w=1600&q=80"
           alt="Mosque Construction"/>
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <span class="hero-badge"
              data-en="Infrastructure Projects"
              data-ar="مشاريع البنية التحتية">
          Infrastructure Projects
        </span>
        <h1 class="hero-title"
            data-en="Build a Mosque — Leave a Legacy<br/>That Endures Beyond This Life"
            data-ar="ابنِ مسجداً تُذكر بالخير<br/>إلى يوم القيامة">
          Build a Mosque — Leave a Legacy<br/>That Endures Beyond This Life
        </h1>
        <p class="hero-subtitle"
           data-en="Contribute to the construction of mosques, Quranic schools, and water wells in remote and underserved communities."
           data-ar="شارك في مشاريع بناء المساجد والمدارس القرآنية وآبار المياه في المناطق النائية">
          Contribute to the construction of mosques, Quranic schools, and water wells in remote and underserved communities.
        </p>
        <button class="btn-hero"
                data-en="🕌 Support Mosque Projects" data-ar="🕌 تبرع للمساجد"
                onclick="document.getElementById('donationCases').scrollIntoView({behavior:'smooth'})">
          🕌 Support Mosque Projects
        </button>
      </div>
    </div>

    <!-- Slide 3: Clean Water -->
    <div class="carousel-item">
      <img src="https://images.unsplash.com/photo-1509099836639-18ba1795216d?w=1600&q=80"
           alt="Clean Water"/>
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <span class="hero-badge"
              data-en="Ongoing Charity — Sadaqah Jariyah"
              data-ar="صدقة جارية">
          Ongoing Charity — Sadaqah Jariyah
        </span>
        <h1 class="hero-title"
            data-en="A Drop of Water Can Nourish<br/>Thousands of Lives"
            data-ar="قطرة ماء تُروي<br/>آلاف الأرواح">
          A Drop of Water Can Nourish<br/>Thousands of Lives
        </h1>
        <p class="hero-subtitle"
           data-en="Your donation to dig a water well provides dignified living for families who face daily water scarcity."
           data-ar="تبرعك بحفر بئر ماء يوفر الحياة الكريمة لأسر تعاني شُحّ المياه النظيفة يومياً">
          Your donation to dig a water well provides dignified living for families who face daily water scarcity.
        </p>
        <button class="btn-hero"
                data-en="💧 Build a Water Well" data-ar="💧 ابنِ بئراً الآن"
                onclick="document.getElementById('donationCases').scrollIntoView({behavior:'smooth'})">
          💧 Build a Water Well
        </button>
      </div>
    </div>

  </div><!-- /.carousel-inner -->

  <button class="carousel-control-prev" type="button"
          data-bs-target="#heroCarousel" data-bs-slide="prev">
    <span class="carousel-control-prev-icon"></span>
  </button>
  <button class="carousel-control-next" type="button"
          data-bs-target="#heroCarousel" data-bs-slide="next">
    <span class="carousel-control-next-icon"></span>
  </button>
</div><!-- /#heroCarousel -->


<!-- ═══════════════════════════════════════
     SECTION: DONATION CASES
═══════════════════════════════════════ -->
<section id="donationCases">
  <div class="container">

    <div class="text-center mb-5 fade-up">
      <p class="section-label"
         data-en="Contribute to Our Projects"
         data-ar="ساهم في مشاريعنا">Contribute to Our Projects</p>
      <h2 class="section-title"
          data-en="Donation Cases"
          data-ar="حالات التبرع">Donation Cases</h2>
      <div class="section-divider"></div>
      <p class="text-muted"
         style="max-width:540px;margin:0 auto;font-size:0.9rem;"
         data-en="Choose a project close to your heart and help create a real difference in the lives of those in need."
         data-ar="اختر المشروع الذي يلامس قلبك وساهم في صنع الفرق الحقيقي في حياة المحتاجين">
        Choose a project close to your heart and help create a real difference in the lives of those in need.
      </p>
    </div>

    <div class="cases-scroll-wrapper">
      <div class="cases-row">

        <!-- Case 1: Quran Education -->
        <div class="case-card fade-up delay-1">
          <div class="case-img-wrap">
            <img src="https://images.unsplash.com/photo-1603009128563-f6c39c09e9db?w=600&q=80"
                 alt="Quran Education"/>
            <div class="case-icon-badge"><i class="fa-solid fa-book-quran"></i></div>
            <span class="case-tag"
                  data-en="Education"
                  data-ar="تعليم">Education</span>
          </div>
          <div class="case-body">
            <h5 class="case-title"
                data-en="Distributing the Holy Quran &amp; Quranic Education"
                data-ar="نشر المصاحف والتعليم القرآني">
              Distributing the Holy Quran &amp; Quranic Education
            </h5>
            <div class="case-progress-label">
              <span data-en="Raised: <strong>18,450 SAR</strong>"
                    data-ar="تم جمع: <strong>18,450 ر.س</strong>">
                Raised: <strong>18,450 SAR</strong>
              </span>
              <span class="pct">72%</span>
            </div>
            <div class="progress">
              <div class="progress-bar" style="width:72%"></div>
            </div>
            <div class="case-meta">
              <span>
                <i class="fa-regular fa-clock"></i>
                <span data-en="Remaining: <strong>7,550 SAR</strong>"
                      data-ar="المتبقي: <strong>7,550 ر.س</strong>">
                  Remaining: <strong>7,550 SAR</strong>
                </span>
              </span>
              <span>
                <i class="fa-solid fa-flag-checkered"></i>
                <span data-en="Goal: <strong>26,000 SAR</strong>"
                      data-ar="الهدف: <strong>26,000 ر.س</strong>">
                  Goal: <strong>26,000 SAR</strong>
                </span>
              </span>
            </div>
            <div class="case-input-group">
              <input type="number" min="10"
                     data-en-placeholder="Enter amount (SAR)"
                     data-ar-placeholder="أدخل المبلغ (ر.س)"
                     placeholder="Enter amount (SAR)"/>
              <button class="btn-case"
                      data-en="Donate" data-ar="تبرع">Donate</button>
            </div>
          </div>
        </div>

        <!-- Case 2: Clean Water -->
        <div class="case-card fade-up delay-2">
          <div class="case-img-wrap">
            <img src="https://images.unsplash.com/photo-1583120283327-4b8d9e6fd64c?w=600&q=80"
                 alt="Clean Water"/>
            <div class="case-icon-badge"><i class="fa-solid fa-faucet-drip"></i></div>
            <span class="case-tag"
                  data-en="Water"
                  data-ar="مياه">Water</span>
          </div>
          <div class="case-body">
            <h5 class="case-title"
                data-en="Digging Wells &amp; Providing Clean Water"
                data-ar="حفر الآبار وتوفير المياه النظيفة">
              Digging Wells &amp; Providing Clean Water
            </h5>
            <div class="case-progress-label">
              <span data-en="Raised: <strong>34,200 SAR</strong>"
                    data-ar="تم جمع: <strong>34,200 ر.س</strong>">
                Raised: <strong>34,200 SAR</strong>
              </span>
              <span class="pct">85%</span>
            </div>
            <div class="progress">
              <div class="progress-bar" style="width:85%"></div>
            </div>
            <div class="case-meta">
              <span>
                <i class="fa-regular fa-clock"></i>
                <span data-en="Remaining: <strong>5,800 SAR</strong>"
                      data-ar="المتبقي: <strong>5,800 ر.س</strong>">
                  Remaining: <strong>5,800 SAR</strong>
                </span>
              </span>
              <span>
                <i class="fa-solid fa-flag-checkered"></i>
                <span data-en="Goal: <strong>40,000 SAR</strong>"
                      data-ar="الهدف: <strong>40,000 ر.س</strong>">
                  Goal: <strong>40,000 SAR</strong>
                </span>
              </span>
            </div>
            <div class="case-input-group">
              <input type="number" min="10"
                     data-en-placeholder="Enter amount (SAR)"
                     data-ar-placeholder="أدخل المبلغ (ر.س)"
                     placeholder="Enter amount (SAR)"/>
              <button class="btn-case"
                      data-en="Donate" data-ar="تبرع">Donate</button>
            </div>
          </div>
        </div>

        <!-- Case 3: Mosque Build -->
        <div class="case-card fade-up delay-3">
          <div class="case-img-wrap">
            <img src="https://images.unsplash.com/photo-1548449112-96a38a643324?w=600&q=80"
                 alt="Mosque Construction"/>
            <div class="case-icon-badge"><i class="fa-solid fa-mosque"></i></div>
            <span class="case-tag"
                  data-en="Worship"
                  data-ar="عبادة">Worship</span>
          </div>
          <div class="case-body">
            <h5 class="case-title"
                data-en="Building a Mosque in South Sudan"
                data-ar="بناء مسجد في جنوب السودان">
              Building a Mosque in South Sudan
            </h5>
            <div class="case-progress-label">
              <span data-en="Raised: <strong>51,000 SAR</strong>"
                    data-ar="تم جمع: <strong>51,000 ر.س</strong>">
                Raised: <strong>51,000 SAR</strong>
              </span>
              <span class="pct">51%</span>
            </div>
            <div class="progress">
              <div class="progress-bar" style="width:51%"></div>
            </div>
            <div class="case-meta">
              <span>
                <i class="fa-regular fa-clock"></i>
                <span data-en="Remaining: <strong>49,000 SAR</strong>"
                      data-ar="المتبقي: <strong>49,000 ر.س</strong>">
                  Remaining: <strong>49,000 SAR</strong>
                </span>
              </span>
              <span>
                <i class="fa-solid fa-flag-checkered"></i>
                <span data-en="Goal: <strong>100,000 SAR</strong>"
                      data-ar="الهدف: <strong>100,000 ر.س</strong>">
                  Goal: <strong>100,000 SAR</strong>
                </span>
              </span>
            </div>
            <div class="case-input-group">
              <input type="number" min="10"
                     data-en-placeholder="Enter amount (SAR)"
                     data-ar-placeholder="أدخل المبلغ (ر.س)"
                     placeholder="Enter amount (SAR)"/>
              <button class="btn-case"
                      data-en="Donate" data-ar="تبرع">Donate</button>
            </div>
          </div>
        </div>

        <!-- Case 4: Orphan Sponsorship -->
        <div class="case-card fade-up delay-4">
          <div class="case-img-wrap">
            <img src="https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=600&q=80"
                 alt="Orphans"/>
            <div class="case-icon-badge"><i class="fa-solid fa-child-reaching"></i></div>
            <span class="case-tag"
                  data-en="Orphans"
                  data-ar="أيتام">Orphans</span>
          </div>
          <div class="case-body">
            <h5 class="case-title"
                data-en="Sponsoring Orphans &amp; Displaced Families"
                data-ar="كفالة أيتام وأسر متضررة">
              Sponsoring Orphans &amp; Displaced Families
            </h5>
            <div class="case-progress-label">
              <span data-en="Raised: <strong>12,300 SAR</strong>"
                    data-ar="تم جمع: <strong>12,300 ر.س</strong>">
                Raised: <strong>12,300 SAR</strong>
              </span>
              <span class="pct">41%</span>
            </div>
            <div class="progress">
              <div class="progress-bar" style="width:41%"></div>
            </div>
            <div class="case-meta">
              <span>
                <i class="fa-regular fa-clock"></i>
                <span data-en="Remaining: <strong>17,700 SAR</strong>"
                      data-ar="المتبقي: <strong>17,700 ر.س</strong>">
                  Remaining: <strong>17,700 SAR</strong>
                </span>
              </span>
              <span>
                <i class="fa-solid fa-flag-checkered"></i>
                <span data-en="Goal: <strong>30,000 SAR</strong>"
                      data-ar="الهدف: <strong>30,000 ر.س</strong>">
                  Goal: <strong>30,000 SAR</strong>
                </span>
              </span>
            </div>
            <div class="case-input-group">
              <input type="number" min="10"
                     data-en-placeholder="Enter amount (SAR)"
                     data-ar-placeholder="أدخل المبلغ (ر.س)"
                     placeholder="Enter amount (SAR)"/>
              <button class="btn-case"
                      data-en="Donate" data-ar="تبرع">Donate</button>
            </div>
          </div>
        </div>

      </div><!-- /.cases-row -->
    </div><!-- /.cases-scroll-wrapper -->
  </div>
</section>


<!-- ═══════════════════════════════════════
     SECTION: QURANIC VERSE SEPARATOR
═══════════════════════════════════════ -->
<section id="quranSep">
  <div class="container" style="position:relative;z-index:1;">
    <div class="quran-ornament">
      <div class="quran-ornament-icon"><i class="fa-solid fa-star-and-crescent"></i></div>
    </div>
    <!-- Arabic verse is ALWAYS shown in both languages -->
    <p class="quran-arabic">
      ﴿وَأَحْسِنُوا إِنَّ اللَّهَ يُحِبُّ الْمُحْسِنِينَ﴾
    </p>
    <!-- English translation (always visible in EN mode, styled subtly in AR mode) -->
    <p class="quran-translation"
       data-en='"And do good — indeed, Allah loves those who do good."'
       data-ar="وَأَحْسِنُوا — الإحسان أن تعبد الله كأنك تراه">
      "And do good — indeed, Allah loves those who do good."
    </p>
    <p class="quran-ref"
       data-en="Surah Al-Baqarah — Verse 195"
       data-ar="سورة البقرة — الآية ١٩٥">
      Surah Al-Baqarah — Verse 195
    </p>
  </div>
</section>


<!-- ═══════════════════════════════════════
     SECTION: IMPACT AREAS GRID
═══════════════════════════════════════ -->
<section id="impactAreas">
  <div class="container">

    <div class="text-center mb-5 fade-up">
      <p class="section-label"
         data-en="Our Fields of Work"
         data-ar="مجالات العمل">Our Fields of Work</p>
      <h2 class="section-title"
          data-en="Impact Areas"
          data-ar="قطاعات الأثر">Impact Areas</h2>
      <div class="section-divider"></div>
    </div>

    <div class="impact-grid">

      <div class="impact-tile fade-up delay-1">
        <a href="{{ url(app()->getLocale() . '/cases?category=health') }}" style="text-decoration: none; display: block; height: 100%; width: 100%;">
          <img src="https://images.unsplash.com/photo-1584820927498-cfe5211fd8bf?w=700&q=80" alt="Healthcare"/>
          <div class="impact-tile-overlay">
            <div class="impact-tile-icon"><i class="fa-solid fa-heart-pulse"></i></div>
            <div class="impact-tile-title"
                 data-en="Healthcare" data-ar="الرعاية الصحية">Healthcare</div>
            <div class="impact-tile-desc"
                 data-en="Providing medical care and medicines to underserved communities."
                 data-ar="توفير الرعاية الطبية والأدوية للمجتمعات المحرومة">
              Providing medical care and medicines to underserved communities.
            </div>
          </div>
        </a>
      </div>

      <div class="impact-tile fade-up delay-2">
        <a href="{{ url(app()->getLocale() . '/cases?category=orphans') }}" style="text-decoration: none; display: block; height: 100%; width: 100%;">
          <img src="https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=700&q=80" alt="Orphans"/>
          <div class="impact-tile-overlay">
            <div class="impact-tile-icon"><i class="fa-solid fa-children"></i></div>
            <div class="impact-tile-title"
                 data-en="Orphan Care" data-ar="كفالة الأيتام">Orphan Care</div>
            <div class="impact-tile-desc"
                 data-en="Comprehensive care for orphaned children to ensure a bright future."
                 data-ar="رعاية شاملة للأطفال الأيتام وضمان مستقبل مشرق لهم">
              Comprehensive care for orphaned children to ensure a bright future.
            </div>
          </div>
        </a>
      </div>

      <div class="impact-tile fade-up delay-3">
        <a href="{{ url(app()->getLocale() . '/cases?category=education') }}" style="text-decoration: none; display: block; height: 100%; width: 100%;">
          <img src="https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=700&q=80" alt="Education"/>
          <div class="impact-tile-overlay">
            <div class="impact-tile-icon"><i class="fa-solid fa-graduation-cap"></i></div>
            <div class="impact-tile-title"
                 data-en="Education &amp; Training" data-ar="التعليم والتدريب">Education &amp; Training</div>
            <div class="impact-tile-desc"
                 data-en="Building schools and providing scholarships for students in need."
                 data-ar="بناء المدارس وتوفير المنح الدراسية للطلاب المحتاجين">
              Building schools and providing scholarships for students in need.
            </div>
          </div>
        </a>
      </div>

      <div class="impact-tile fade-up delay-4">
        <a href="{{ url(app()->getLocale() . '/cases?category=housing') }}" style="text-decoration: none; display: block; height: 100%; width: 100%;">
          <img src="https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=700&q=80" alt="Housing"/>
          <div class="impact-tile-overlay">
            <div class="impact-tile-icon"><i class="fa-solid fa-house-chimney-heart"></i></div>
            <div class="impact-tile-title"
                 data-en="Housing &amp; Shelter" data-ar="الإسكان والإيواء">Housing &amp; Shelter</div>
            <div class="impact-tile-desc"
                 data-en="Providing safe shelter for displaced and conflict-affected families."
                 data-ar="توفير المأوى الآمن للأسر النازحة والمتضررة">
              Providing safe shelter for displaced and conflict-affected families.
            </div>
          </div>
        </a>
      </div>

      <div class="impact-tile fade-up delay-5">
        <a href="{{ url(app()->getLocale() . '/cases?category=water') }}" style="text-decoration: none; display: block; height: 100%; width: 100%;">
          <img src="https://images.unsplash.com/photo-1509099836639-18ba1795216d?w=700&q=80" alt="Water"/>
          <div class="impact-tile-overlay">
            <div class="impact-tile-icon"><i class="fa-solid fa-droplet"></i></div>
            <div class="impact-tile-title"
                 data-en="Clean Water" data-ar="مياه نظيفة">Clean Water</div>
            <div class="impact-tile-desc"
                 data-en="Drilling wells and establishing water networks in remote areas."
                 data-ar="حفر الآبار وإنشاء شبكات المياه في المناطق النائية">
              Drilling wells and establishing water networks in remote areas.
            </div>
          </div>
        </a>
      </div>

      <div class="impact-tile fade-up delay-6">
        <a href="{{ url(app()->getLocale() . '/cases?category=mosques') }}" style="text-decoration: none; display: block; height: 100%; width: 100%;">
          <img src="https://images.unsplash.com/photo-1542810634-71277d95dcbb?w=700&q=80" alt="Mosques"/>
          <div class="impact-tile-overlay">
            <div class="impact-tile-icon"><i class="fa-solid fa-mosque"></i></div>
            <div class="impact-tile-title"
                 data-en="Mosque Construction" data-ar="بناء المساجد">Mosque Construction</div>
            <div class="impact-tile-desc"
                 data-en="Building and renovating mosques and Islamic centres across Africa."
                 data-ar="إنشاء بيوت الله وتجديد المساجد في أرجاء أفريقيا">
              Building and renovating mosques and Islamic centres across Africa.
            </div>
          </div>
        </a>
      </div>

    </div><!-- /.impact-grid -->
  </div>
</section>


<!-- ═══════════════════════════════════════
     SECTION: SERVICES
═══════════════════════════════════════ -->
<section id="services">
  <div class="container">

    <div class="text-center mb-5 fade-up">
      <p class="section-label"
         data-en="What We Offer"
         data-ar="ما نقدمه">What We Offer</p>
      <h2 class="section-title"
          data-en="Our Specialised Services"
          data-ar="خدماتنا المتخصصة">Our Specialised Services</h2>
      <div class="section-divider"></div>
    </div>

    <div class="row g-4">

      <div class="col-md-4 col-sm-6 fade-up delay-1">
        <div class="service-card">
          <div class="service-icon"><i class="fa-solid fa-calculator"></i></div>
          <h5 class="service-title"
              data-en="Zakāt Calculation"
              data-ar="حساب الزكاة">Zakāt Calculation</h5>
          <p class="service-desc"
             data-en="Calculate your Zakāt accurately with our integrated tool and fulfil your obligation through our trusted foundation."
             data-ar="احسب زكاتك بدقة وسهولة مع أداتنا المتكاملة، وأخرجها في مكانها الصحيح عبر مؤسستنا الموثوقة">
            Calculate your Zakāt accurately with our integrated tool and fulfil your obligation through our trusted foundation.
          </p>
          <a href="#" class="service-link"
             data-en="Calculate Now →"
             data-ar="احسب الآن ←">Calculate Now →</a>
        </div>
      </div>

      <div class="col-md-4 col-sm-6 fade-up delay-2">
        <div class="service-card">
          <div class="service-icon"><i class="fa-solid fa-cow"></i></div>
          <h5 class="service-title"
              data-en="Adahi — Sacrifice"
              data-ar="الأضاحي والذبائح">Adahi — Sacrifice</h5>
          <p class="service-desc"
             data-en="Delegate us to slaughter your Adha and distribute it to needy families in Sudan and Eritrea on Eid day."
             data-ar="وكّلنا بذبح أضحيتك وتوزيعها على الأسر المحتاجة في السودان وإريتريا في يوم العيد المبارك">
            Delegate us to slaughter your Adha and distribute it to needy families in Sudan and Eritrea on Eid day.
          </p>
          <a href="#" class="service-link"
             data-en="Order Your Sacrifice →"
             data-ar="اطلب أضحيتك ←">Order Your Sacrifice →</a>
        </div>
      </div>

      <div class="col-md-4 col-sm-6 fade-up delay-3">
        <div class="service-card">
          <div class="service-icon"><i class="fa-solid fa-gift"></i></div>
          <h5 class="service-title"
              data-en="Gift Donations"
              data-ar="التبرعات الهدايا">Gift Donations</h5>
          <p class="service-desc"
             data-en="Gift someone you love a donation in their name on special occasions — a lasting legacy that remains after they are gone."
             data-ar="أهدِ شخصاً عزيزاً تبرعاً باسمه في المناسبات السعيدة، وابنِ له أثراً طيباً يبقى بعد الرحيل">
            Gift someone you love a donation in their name on special occasions — a lasting legacy that remains after they are gone.
          </p>
          <a href="#" class="service-link"
             data-en="Send a Gift →"
             data-ar="أرسل هدية ←">Send a Gift →</a>
        </div>
      </div>

      <div class="col-md-4 col-sm-6 fade-up delay-1">
        <div class="service-card">
          <div class="service-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
          <h5 class="service-title"
              data-en="Monthly Sponsorship"
              data-ar="الكفالات الشهرية">Monthly Sponsorship</h5>
          <p class="service-desc"
             data-en="Subscribe to a regular monthly sponsorship for a family or orphan and be a constant source of hope and ongoing support."
             data-ar="اشترك بكفالة شهرية منتظمة لأسرة أو يتيم وكن مصدراً ثابتاً للأمل والدعم المستمر">
            Subscribe to a regular monthly sponsorship for a family or orphan and be a constant source of hope and ongoing support.
          </p>
          <a href="#" class="service-link"
             data-en="Sponsor Now →"
             data-ar="اكفل الآن ←">Sponsor Now →</a>
        </div>
      </div>

      <div class="col-md-4 col-sm-6 fade-up delay-2">
        <div class="service-card">
          <div class="service-icon"><i class="fa-solid fa-boxes-packing"></i></div>
          <h5 class="service-title"
              data-en="Emergency Relief Parcels"
              data-ar="الطرود الإغاثية">Emergency Relief Parcels</h5>
          <p class="service-desc"
             data-en="Fund urgent food and relief parcels for victims of disasters and armed conflicts in crisis zones."
             data-ar="تمويل طرود غذائية وإغاثية عاجلة لضحايا الكوارث والنزاعات المسلحة في مناطق الأزمات">
            Fund urgent food and relief parcels for victims of disasters and armed conflicts in crisis zones.
          </p>
          <a href="#" class="service-link"
             data-en="Donate to Relief →"
             data-ar="تبرع للإغاثة ←">Donate to Relief →</a>
        </div>
      </div>

      <div class="col-md-4 col-sm-6 fade-up delay-3">
        <div class="service-card">
          <div class="service-icon"><i class="fa-solid fa-handshake-angle"></i></div>
          <h5 class="service-title"
              data-en="Corporate Partnerships"
              data-ar="الشراكات المؤسسية">Corporate Partnerships</h5>
          <p class="service-desc"
             data-en="Partner with us as a company or institution to implement meaningful CSR initiatives with measurable real-world impact."
             data-ar="تعاون معنا كشركة أو مؤسسة في تنفيذ مبادرات المسؤولية الاجتماعية ذات الأثر الفعلي">
            Partner with us as a company or institution to implement meaningful CSR initiatives with measurable real-world impact.
          </p>
          <a href="#" class="service-link"
             data-en="Contact Us →"
             data-ar="تواصل معنا ←">Contact Us →</a>
        </div>
      </div>

    </div>
  </div>
</section>


<!-- ═══════════════════════════════════════
     SECTION: STATISTICS
═══════════════════════════════════════ -->
<section id="statistics">
  <div class="container" style="position:relative;z-index:1;">

    <div class="text-center mb-5 fade-up">
      <p class="section-label"
         style="color:rgba(255,255,255,0.55);"
         data-en="Numbers Speak"
         data-ar="أرقام تتحدث">Numbers Speak</p>
      <h2 class="section-title"
          style="color:var(--white);"
          data-en="Our Impact in Numbers"
          data-ar="أثرنا في أرقام">Our Impact in Numbers</h2>
      <div class="section-divider" style="background:var(--accent)"></div>
    </div>

    <div class="row g-4 justify-content-center">

      <div class="col-6 col-md-3 fade-up delay-1">
        <div class="stat-item">
          <div class="stat-icon"><i class="fa-solid fa-hand-holding-heart"></i></div>
          <div class="stat-number">4.2M+</div>
          <div class="stat-label"
               data-en="SAR Total Donations"
               data-ar="ريال إجمالي التبرعات">SAR Total Donations</div>
        </div>
      </div>

      <div class="col-6 col-md-3 fade-up delay-2">
        <div class="stat-item">
          <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
          <div class="stat-number">18,500+</div>
          <div class="stat-label"
               data-en="Direct Beneficiaries"
               data-ar="مستفيد مباشر">Direct Beneficiaries</div>
        </div>
      </div>

      <div class="col-6 col-md-3 fade-up delay-3">
        <div class="stat-item">
          <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
          <div class="stat-number">340</div>
          <div class="stat-label"
               data-en="Completed Projects"
               data-ar="مشروع مكتمل">Completed Projects</div>
        </div>
      </div>

      <div class="col-6 col-md-3 fade-up delay-4">
        <div class="stat-item">
          <div class="stat-icon"><i class="fa-solid fa-earth-africa"></i></div>
          <div class="stat-number">12</div>
          <div class="stat-label"
               data-en="Countries Served"
               data-ar="دولة نخدمها">Countries Served</div>
        </div>
      </div>

    </div>
  </div>
</section>


<!-- ═══════════════════════════════════════
     SECTION: PARTNERS CAROUSEL
═══════════════════════════════════════ -->
<section id="partners">
  <div class="container">

    <div class="text-center mb-5 fade-up">
      <p class="section-label"
         data-en="Those Who Support Us"
         data-ar="من يدعموننا">Those Who Support Us</p>
      <h2 class="section-title"
          data-en="Partners &amp; Supporters"
          data-ar="شركاؤنا والداعمون">Partners &amp; Supporters</h2>
      <div class="section-divider"></div>
    </div>

    <div class="partners-track-wrapper fade-up">
      <div class="partners-track">
        <div class="partner-logo" data-en="Mercy Foundation" data-ar="مؤسسة الرحمة">Mercy Foundation</div>
        <div class="partner-logo" data-en="Goodness Fund" data-ar="صندوق الخير">Goodness Fund</div>
        <div class="partner-logo" data-en="Dev Bank" data-ar="بنك التنمية">Dev Bank</div>
        <div class="partner-logo" data-en="Crescent Authority" data-ar="هيئة الهلال">Crescent Authority</div>
        <div class="partner-logo" data-en="Namaa Foundation" data-ar="مؤسسة نماء">Namaa Foundation</div>
        <div class="partner-logo" data-en="Ihsan League" data-ar="رابطة الإحسان">Ihsan League</div>
        <div class="partner-logo" data-en="Giving Society" data-ar="جمعية العطاء">Giving Society</div>
        <div class="partner-logo" data-en="Hope Centre" data-ar="مركز الأمل">Hope Centre</div>
        <!-- Duplicates for seamless infinite scroll -->
        <div class="partner-logo" data-en="Mercy Foundation" data-ar="مؤسسة الرحمة">Mercy Foundation</div>
        <div class="partner-logo" data-en="Goodness Fund" data-ar="صندوق الخير">Goodness Fund</div>
        <div class="partner-logo" data-en="Dev Bank" data-ar="بنك التنمية">Dev Bank</div>
        <div class="partner-logo" data-en="Crescent Authority" data-ar="هيئة الهلال">Crescent Authority</div>
        <div class="partner-logo" data-en="Namaa Foundation" data-ar="مؤسسة نماء">Namaa Foundation</div>
        <div class="partner-logo" data-en="Ihsan League" data-ar="رابطة الإحسان">Ihsan League</div>
        <div class="partner-logo" data-en="Giving Society" data-ar="جمعية العطاء">Giving Society</div>
        <div class="partner-logo" data-en="Hope Centre" data-ar="مركز الأمل">Hope Centre</div>
      </div>
    </div>

  </div>
</section>


<!-- ═══════════════════════════════════════
     SECTION: FOOTER
═══════════════════════════════════════ -->
<footer>
  <div class="container">
    <div class="row g-5">

      <!-- Brand Column -->
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

      <!-- Quick Links -->
      <div class="col-6 col-lg-2">
        <h6 class="footer-heading"
            data-en="Quick Links"
            data-ar="روابط سريعة">Quick Links</h6>
        <ul class="footer-links">
          <li><a href="#" data-en="About Us" data-ar="من نحن"><i class="fa-solid fa-chevron-right"></i> About Us</a></li>
          <li><a href="#donationCases" data-en="Donation Cases" data-ar="حالات التبرع"><i class="fa-solid fa-chevron-right"></i> Donation Cases</a></li>
          <li><a href="#services" data-en="Services" data-ar="الخدمات"><i class="fa-solid fa-chevron-right"></i> Services</a></li>
          <li><a href="#impactAreas" data-en="Our Projects" data-ar="مشاريعنا"><i class="fa-solid fa-chevron-right"></i> Our Projects</a></li>
          <li><a href="#" data-en="News &amp; Reports" data-ar="أخبار وتقارير"><i class="fa-solid fa-chevron-right"></i> News &amp; Reports</a></li>
        </ul>
      </div>

      <!-- Help -->
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

      <!-- Contact & Newsletter -->
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
             data-ar="اشترك في نشرتنا البريدية:">
            Subscribe to our newsletter:
          </p>
          <div style="display:flex;gap:8px;">
            <input class="footer-newsletter-input"
                   type="email"
                   data-en-placeholder="Your email address"
                   data-ar-placeholder="بريدك الإلكتروني"
                   placeholder="Your email address"/>
            <button class="footer-newsletter-btn"
                    data-en="Subscribe"
                    data-ar="اشتراك">Subscribe</button>
          </div>
        </div>
      </div>

    </div><!-- /.row -->

    <!-- Footer Bottom -->
    <div class="footer-bottom">
      <p data-en="© 2025 Al-Kalimah Foundation. All Rights Reserved."
         data-ar="© 2025 مؤسسة الكلم الطيب. جميع الحقوق محفوظة.">
        © 2025 Al-Kalimah Foundation. All Rights Reserved.
      </p>
      <p>
        <span data-en="Designed with" data-ar="صُمِّم بـ">Designed with</span>
        <span style="color:var(--accent)"> ♥ </span>
        <span data-en="for humanity —" data-ar="لخدمة الإنسانية —">for humanity —</span>
        <a href="#" data-en="Al-Kalimah Foundation" data-ar="مؤسسة الكلم الطيب">Al-Kalimah Foundation</a>
      </p>
    </div>

  </div>
</footer>

<!-- Back to Top -->
<button id="backToTop" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top">
  <i class="fa-solid fa-chevron-up"></i>
</button>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ════════════════════════════════════════════════════════════
   LANGUAGE SWITCHER ENGINE
   - Swaps Bootstrap CSS (LTR <-> RTL)
   - Toggles html[lang] and html[dir]
   - Updates all [data-en] / [data-ar] text nodes
   - Swaps input placeholders
   - Persists choice to localStorage
════════════════════════════════════════════════════════════ */

const BOOTSTRAP_LTR = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
const BOOTSTRAP_RTL = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css';

let currentLang = localStorage.getItem('alkLang') || 'en';

function setLang(lang) {
  currentLang = lang;
  localStorage.setItem('alkLang', lang);

  const html = document.getElementById('htmlRoot');
  const bsCSS = document.getElementById('bootstrapCSS');

  // 1. Update <html> attributes
  html.setAttribute('lang', lang);
  html.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');

  // 2. Swap Bootstrap CSS for RTL/LTR
  bsCSS.href = lang === 'ar' ? BOOTSTRAP_RTL : BOOTSTRAP_LTR;

  // 3. Update all translatable text elements
  document.querySelectorAll('[data-en], [data-ar]').forEach(el => {
    const val = el.getAttribute('data-' + lang);
    if (val !== null) {
      // Use innerHTML for elements that may contain HTML tags
      el.innerHTML = val;
    }
  });

  // 4. Swap input placeholders
  document.querySelectorAll('[data-en-placeholder]').forEach(el => {
    el.placeholder = el.getAttribute('data-' + lang + '-placeholder') || '';
  });

  // 5. Update lang switcher button states
  document.getElementById('btnEN').classList.toggle('active', lang === 'en');
  document.getElementById('btnAR').classList.toggle('active', lang === 'ar');

  // 6. Update footer chevron direction for links
  document.querySelectorAll('.footer-links a i').forEach(icon => {
    icon.className = lang === 'ar'
      ? 'fa-solid fa-chevron-left'
      : 'fa-solid fa-chevron-right';
  });
}

// ════════════════════════════════════════════════════════════
// NAVBAR: Scroll behaviour
// ════════════════════════════════════════════════════════════
const nav = document.getElementById('mainNav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 55);
  document.getElementById('backToTop').classList.toggle('visible', window.scrollY > 420);
}, { passive: true });

// ════════════════════════════════════════════════════════════
// SCROLL ANIMATIONS: IntersectionObserver for .fade-up
// ════════════════════════════════════════════════════════════
const fadeObserver = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
}, { threshold: 0.10 });

document.querySelectorAll('.fade-up').forEach(el => fadeObserver.observe(el));

// ════════════════════════════════════════════════════════════
// DONATION BUTTON: feedback on click
// ════════════════════════════════════════════════════════════
document.querySelectorAll('.btn-case').forEach(btn => {
  btn.addEventListener('click', function () {
    const input = this.closest('.case-input-group').querySelector('input');
    const val = parseFloat(input.value);
    if (!val || val < 10) {
      input.style.borderColor = '#e53e3e';
      const ph = currentLang === 'ar' ? 'الحد الأدنى ١٠' : 'Min. 10 SAR';
      input.placeholder = ph;
      setTimeout(() => {
        input.style.borderColor = '';
        input.placeholder = input.getAttribute('data-' + currentLang + '-placeholder');
      }, 2200);
    } else {
      const orig = this.innerHTML;
      const ok = currentLang === 'ar' ? '✓ شكراً!' : '✓ Thank you!';
      this.innerHTML = ok;
      this.style.background = '#2ecc71';
      setTimeout(() => {
        this.innerHTML = orig;
        this.style.background = '';
        input.value = '';
      }, 2600);
    }
  });
});

// ════════════════════════════════════════════════════════════
// INIT: Apply persisted language on page load
// ════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  // Small delay so Bootstrap CSS can parse first
  setTimeout(() => setLang(currentLang), 60);
});
</script>

</body>
</html>