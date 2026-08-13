{{-- Every value is escaped by Blade, and this is a real view rather than a string returned from
     render() — a component returning a string has that string compiled as a template. --}}
@if ($problem)
    {{-- Server-rendered challenge: no script, no fetch, works with JavaScript disabled. --}}
    <div {{ $attributes->merge(['class' => $widget->containerClass]) }} id="{{ $widget->instanceId }}">
        <label for="{{ $widget->instanceId }}-answer">
            {{ $label ?? __('captcha::widget.prompt') }}
            <span class="laranail-captcha-question">{{ $problem->question }}</span>
        </label>

        <input
            type="text"
            id="{{ $widget->instanceId }}-answer"
            name="captcha_answer"
            inputmode="numeric"
            autocomplete="off"
            required
        >

        <input type="hidden" name="captcha_challenge" value="{{ $challengeToken }}">
    </div>
@else
    @if ($scriptUrl)
        <script src="{{ $lang ? $scriptUrl . (str_contains($scriptUrl, '?') ? '&' : '?') . 'hl=' . urlencode($lang) : $scriptUrl }}"
            @if ($nonce) nonce="{{ $nonce }}" @endif
            async defer></script>
    @endif

    <div
        id="{{ $widget->instanceId }}"
        {{ $attributes->merge(['class' => $widget->containerClass]) }}
        @foreach ($widgetAttributes as $name => $value)
            {{ $name }}="{{ $value }}"
        @endforeach
    ></div>
@endif
