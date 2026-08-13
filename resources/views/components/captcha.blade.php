{{-- Every value is escaped by Blade, and this is a real view rather than a string returned from
     render() — a component returning a string has that string compiled as a template.

     Values reaching the script go through @json, which escapes for a script context. String
     interpolation there would be an injection into executable code, not into markup. --}}

@php($config = [
    'id' => $widget->instanceId,
    'field' => 'captcha',
    'provider' => $runtime['provider'],
    'reset' => $runtime['reset'],
    'skipMorph' => $runtime['skipMorph'],
    'selfHosted' => $runtime['selfHosted'],
    'challengeUrl' => $runtime['challengeUrl'],
    'execute' => $executesOnSubmit,
    'siteKey' => $siteKey,
    'action' => $action,
])

@if ($problem)
    {{-- Server-rendered challenge: no vendor script, works with JavaScript disabled. --}}
    <div {{ $attributes->merge(['class' => $widget->containerClass]) }}
        id="{{ $widget->instanceId }}"
        data-captcha-config="{{ json_encode($config) }}">
        <label for="{{ $widget->instanceId }}-answer">
            {{ $label ?? __('captcha::widget.prompt') }}
            <span class="laranail-captcha-question" id="{{ $widget->instanceId }}-question">{{ $problem->question }}</span>
        </label>

        <input
            type="text"
            id="{{ $widget->instanceId }}-answer"
            name="captcha_answer"
            inputmode="numeric"
            autocomplete="off"
            aria-describedby="{{ $widget->instanceId }}-question"
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
        data-captcha-config="{{ json_encode($config) }}"
        data-expired-callback="laranailCaptchaExpired"
        data-timeout-callback="laranailCaptchaExpired"
        data-error-callback="laranailCaptchaExpired"
        @foreach ($widgetAttributes as $name => $value)
            {{ $name }}="{{ $value }}"
        @endforeach
    ></div>

    @if ($executesOnSubmit)
        <input type="hidden" name="captcha" id="{{ $widget->instanceId }}-token">
    @endif
@endif

@once
    <script @if ($nonce) nonce="{{ $nonce }}" @endif>
        (function () {
            var READY = 'captchaReady';

            function config(el) {
                try {
                    return JSON.parse(el.getAttribute('data-captcha-config') || '{}');
                } catch (e) {
                    return {};
                }
            }

            function call(path) {
                // "turnstile.reset" resolved a segment at a time rather than eval'd. The value
                // comes from our own enum, but resolving strings into callables is the kind of
                // shortcut that stops being safe the moment someone makes it configurable.
                var parts = String(path).split('.');
                var fn = window;

                for (var i = 0; i < parts.length; i++) {
                    if (!fn) { return null; }
                    fn = fn[parts[i]];
                }

                return typeof fn === 'function' ? fn : null;
            }

            function clearToken(el, cfg) {
                var field = document.getElementById(cfg.id + '-token');
                if (field) { field.value = ''; }
            }

            // Named on window because the vendor widgets take callbacks by name, not by reference.
            window.laranailCaptchaExpired = function () {
                document.querySelectorAll('[data-captcha-config]').forEach(function (el) {
                    var cfg = config(el);
                    clearToken(el, cfg);

                    if (cfg.reset) {
                        var reset = call(cfg.reset);
                        if (reset) { reset(); }
                    } else if (cfg.selfHosted && cfg.challengeUrl) {
                        refreshChallenge(el, cfg);
                    }
                });
            };

            function refreshChallenge(el, cfg) {
                // Self-hosted providers have no vendor widget to reset; a stale question is
                // recovered by asking this application for a new one. Only ever triggered by an
                // expiry callback — never by the observer, or a morph-heavy page would mint
                // challenges in a loop against a rate-limited endpoint.
                fetch(cfg.challengeUrl, { headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data) { return; }

                        var question = document.getElementById(cfg.id + '-question');
                        if (question && data.question) { question.textContent = data.question; }

                        var hidden = el.querySelector('input[name="captcha_challenge"]');
                        if (hidden && data.id) {
                            hidden.value = btoa(JSON.stringify({
                                id: data.id,
                                expires: data.expires_at,
                                signature: data.signature
                            }));
                        }
                    })
                    .catch(function () { /* leave the stale challenge; the server rejects it */ });
            }

            function bindExecute(el, cfg) {
                var field = document.getElementById(cfg.id + '-token');
                var form = el.closest('form');

                if (!form || !field) { return; }

                var minted = false;

                form.addEventListener('submit', function (event) {
                    if (minted) { return; }

                    event.preventDefault();

                    if (typeof grecaptcha === 'undefined') {
                        // The vendor script was blocked. Submitting without a token fails
                        // server-side, which is correct — the alternative is a form that can
                        // never be submitted at all.
                        minted = true;
                        form.requestSubmit ? form.requestSubmit() : form.submit();
                        return;
                    }

                    grecaptcha.ready(function () {
                        grecaptcha.execute(cfg.siteKey, { action: cfg.action })
                            .then(function (token) {
                                field.value = token;
                                minted = true;
                                form.requestSubmit ? form.requestSubmit() : form.submit();
                            })
                            .catch(function () {
                                minted = true;
                                form.requestSubmit ? form.requestSubmit() : form.submit();
                            });
                    });
                });
            }

            function init(el) {
                // A morph can re-insert the same node, so initialisation is idempotent by flag
                // rather than by counting events.
                if (el.dataset[READY]) { return; }
                el.dataset[READY] = '1';

                var cfg = config(el);
                if (cfg.execute) { bindExecute(el, cfg); }
            }

            function scan(root) {
                var scope = root && root.querySelectorAll ? root : document;
                scope.querySelectorAll('[data-captcha-config]').forEach(init);
            }

            // One observer covers Livewire morphs, wire:navigate swaps, Alpine and plain fetch
            // with no per-framework glue — rather than a list of navigation events to keep
            // current. Teardown clears the flag so a re-inserted node initialises again.
            new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    m.addedNodes.forEach(function (node) {
                        if (node.nodeType !== 1) { return; }
                        if (node.hasAttribute && node.hasAttribute('data-captcha-config')) { init(node); }
                        scan(node);
                    });
                    m.removedNodes.forEach(function (node) {
                        if (node.nodeType === 1 && node.dataset) { delete node.dataset[READY]; }
                    });
                });
            }).observe(document.documentElement, { childList: true, subtree: true });

            // The half an observer cannot do. A vendor widget is a live iframe holding a session
            // with the provider; letting a morph replace that node discards it, including an
            // already-solved one, with nothing visible but a form that stops working.
            document.addEventListener('livewire:init', function () {
                if (!window.Livewire || !window.Livewire.hook) { return; }

                window.Livewire.hook('morph.updating', function (payload) {
                    var el = payload.el;
                    if (!el || !el.hasAttribute || !el.hasAttribute('data-captcha-config')) { return; }
                    if (config(el).skipMorph && payload.skip) { payload.skip(); }
                });
            });

            document.addEventListener('DOMContentLoaded', function () { scan(document); });
            scan(document);
        })();
    </script>
@endonce
