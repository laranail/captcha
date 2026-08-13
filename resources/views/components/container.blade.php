{{-- The id is generated, never caller-supplied verbatim, because it ends up in a CSS selector and
     a JavaScript identifier downstream. --}}
<div
    id="{{ $widget->instanceId }}"
    {{ $attributes->merge(['class' => $widget->containerClass]) }}
    @foreach ($widgetAttributes as $name => $value)
        {{ $name }}="{{ $value }}"
    @endforeach
></div>
