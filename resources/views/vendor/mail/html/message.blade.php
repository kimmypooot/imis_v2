<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ \App\Support\Settings\Settings::get('header_name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
<span class="footer-org">{{ \App\Support\Settings\Settings::get('organization_name') }}</span><br>
{{ __('This is an automated message from :system. Please do not reply to this email.', ['system' => \App\Support\Settings\Settings::get('portal_name')]) }}

© {{ date('Y') }} {{ \App\Support\Settings\Settings::get('organization_name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
