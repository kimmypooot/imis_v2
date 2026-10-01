<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'CSC RO VIII IMIS') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead

        {{-- Boot preloader (the legacy IMIS loading screen). app.css is
             render-blocking above, so the brand tokens are available here.
             Removed by app.js as soon as the first page renders. --}}
        <style>
            #boot-preloader {
                position: fixed;
                inset: 0;
                z-index: 100;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 1.5rem;
                background: var(--color-csc-blue);
                color: #fff;
                font-family: var(--font-sans);
                transition: opacity 0.4s ease;
            }
            #boot-preloader.is-hidden { opacity: 0; pointer-events: none; }
            #boot-preloader::before {
                content: '';
                position: absolute;
                inset: 0 0 auto;
                height: 4px;
                background: var(--color-csc-red);
            }
            #boot-preloader .disc {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 5rem;
                height: 5rem;
                border-radius: 9999px;
                background: #fff;
                box-shadow: 0 0 0 6px color-mix(in srgb, var(--color-csc-red) 35%, transparent);
            }
            #boot-preloader .disc img { height: 3.25rem; width: auto; }
            #boot-preloader .label { font-size: 0.75rem; letter-spacing: 0.15em; text-transform: uppercase; opacity: 0.75; }
            @media (prefers-reduced-motion: no-preference) {
                #boot-preloader .disc { animation: boot-breathe 1.6s ease-in-out infinite; }
            }
            @keyframes boot-breathe { 50% { transform: scale(1.06); } }
        </style>
    </head>
    <body class="font-sans antialiased">
        <div id="boot-preloader" role="status" aria-live="polite">
            <div class="disc" aria-hidden="true">
                <img src="/images/brand/csc-logo.png" alt="">
            </div>
            <span class="label">Civil Service Commission &middot; Regional Office VIII</span>
            <span class="label">Loading&hellip;</span>
        </div>
        @inertia
    </body>
</html>
