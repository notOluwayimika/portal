<x-mail::message>
# {{ $title }}

@if ($body)
{{ $body }}
@endif

@if ($deepLink)
<x-mail::button :url="$deepLink">
Open in the portal
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
