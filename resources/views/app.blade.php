<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'CSC RO VIII IMIS') }}</title>

        {{-- The legacy IMIS icons (old-imis assets/img). --}}
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.png" type="image/png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead

        {{-- The preloader's look lives in app.css (.imis-preloader), which is
             render-blocking above. This only adds the fade-out. --}}
        <style>
            #boot-preloader { transition: opacity 0.6s ease, visibility 0.6s ease; }
            #boot-preloader.is-hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        </style>
    </head>
    <body class="font-sans antialiased">
        {{-- Boot preloader: the legacy IMIS loading screen. Removed by app.js
             once the first page renders. --}}
        <div id="boot-preloader" class="imis-preloader" role="status" aria-live="polite" aria-busy="true" aria-label="IMIS is loading. Please wait.">
            <div class="imis-preloader__accent" aria-hidden="true"></div>
            <div class="imis-preloader__logo-wrap" aria-hidden="true">
                <img src="/images/brand/CSC-IMIS.png" class="imis-preloader__logo" alt="" draggable="false">
            </div>
            <div aria-hidden="true">
                <span class="imis-preloader__agency-main">Civil Service Commission</span>
                <span class="imis-preloader__agency-sub">Regional Office VIII</span>
            </div>
            <div class="imis-preloader__divider" aria-hidden="true"></div>
            <p class="imis-preloader__label" aria-hidden="true">
                <span>Loading</span>
                <span class="imis-preloader__dots"><span></span><span></span><span></span></span>
            </p>
            <div class="imis-preloader__bar-wrap" aria-hidden="true">
                <div class="imis-preloader__bar"></div>
            </div>
        </div>
        @inertia
    </body>
</html>
