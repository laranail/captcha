{{-- Every value is escaped by Blade. This file is a real view, not a string compiled at runtime:
     a component returning a string is written to disk and compiled as a template, which is how the
     package this replaces turned an unescaped locale into template injection. --}}
@if ($scriptUrl)
    <script src="{{ $lang ? $scriptUrl . (str_contains($scriptUrl, '?') ? '&' : '?') . 'hl=' . urlencode($lang) : $scriptUrl }}"
        @if ($nonce) nonce="{{ $nonce }}" @endif
        async defer></script>
@endif
