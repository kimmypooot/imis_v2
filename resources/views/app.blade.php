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
        @php($launching = ($page['component'] ?? null) === 'Portal/Applications/Launching')

        @if ($launching)
            {{-- Opening a system from IMIS: no navy boot screen. The same light
                 "Opening <system>" splash the page renders (Launching.vue) is
                 drawn here already, so there is one screen from the first paint
                 and the page takes over without a flash. app.js removes this at
                 once (data-instant). --}}
            <div id="boot-preloader" data-instant class="imis-tr" role="status" aria-live="polite" aria-busy="true">
                <div class="imis-tr__card">
                    <div class="imis-tr__rings" aria-hidden="true">
                        <svg class="imis-tr__ring-outer" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" class="imis-tr__track" stroke-width="2.5" />
                            <circle cx="12" cy="12" r="10" class="imis-tr__arc--navy" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="62.832" stroke-dashoffset="20" />
                        </svg>
                        <svg class="imis-tr__ring-inner" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="8" class="imis-tr__track" stroke-width="1.5" />
                            <circle cx="12" cy="12" r="8" class="imis-tr__arc--red" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="50.265" stroke-dashoffset="15" />
                        </svg>
                        <img src="{{ \App\Support\Branding\Branding::url('app_logo') }}" class="imis-tr__logo" alt="" draggable="false">
                    </div>
                    <div class="imis-tr__msg">Opening {{ $page['props']['application']['name'] ?? 'system' }}</div>
                    <div class="imis-tr__sub">Signing you in from IMIS</div>
                    <div class="imis-tr__note">
                        Not moving?
                        @if (preg_match('#^https?://#i', (string) ($page['props']['url'] ?? '')))
                            <a href="{{ $page['props']['url'] }}" class="font-medium text-csc-blue underline">Continue</a>
                        @endif
                    </div>
                    <div class="imis-tr__dots" aria-hidden="true"><span class="is-active"></span><span></span><span></span></div>
                </div>
            </div>
        @else
        {{-- Boot preloader: the legacy IMIS loading screen. Removed by app.js
             once the first page renders. --}}
        <div id="boot-preloader" class="imis-preloader" role="status" aria-live="polite" aria-busy="true" aria-label="IMIS is loading. Please wait.">
            <div class="imis-preloader__accent" aria-hidden="true"></div>
            <div class="imis-preloader__logo-wrap" aria-hidden="true">
                <img src="{{ \App\Support\Branding\Branding::url('app_logo') }}" class="imis-preloader__logo" alt="" draggable="false">
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
        @endif
        @inertia
    </body>
</html>
