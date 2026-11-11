@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table align="center" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
<td style="padding-right: 12px; vertical-align: middle;">
<img src="{{ url(\App\Support\Branding\Branding::url('logo')) }}" class="logo" alt="" height="52">
</td>
<td style="text-align: left; vertical-align: middle;">
<span class="brand-name" style="display: block;">{!! $slot !!}</span>
<span class="brand-tagline" style="display: block;">Integrated Management Information System</span>
</td>
</tr>
</table>
</a>
</td>
</tr>
