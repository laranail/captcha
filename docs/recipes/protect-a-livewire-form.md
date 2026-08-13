# Protect a Livewire form

The most-asked question about the package this replaced, and the one it never answered.

```blade
<form wire:submit="register">
    <input type="email" wire:model="email">

    <x-captcha />

    <button type="submit">Create account</button>
</form>
```

```php
use Simtabi\Laranail\Captcha\Rules\Captcha;

public string $captcha = '';

protected function rules(): array
{
    return ['captcha' => [Captcha::for('register')]];
}
```

## What the component does for you

Two things, and the second is the one that bites.

**It re-initialises however the widget arrives.** A MutationObserver keyed on a ready flag, so a
Livewire morph, a `wire:navigate` page swap, an Alpine block or a plain `fetch` all initialise
through the same path. There is no navigation-event list to keep current.

**It stops a re-render throwing your widget away.** A vendor widget is a live iframe holding a
session with the provider. Livewire morphing that node discards the session — including an
*already-solved* one — and the visitor sees a form that silently stops working. The component
registers a `morph.updating` skip for the containers that hold live vendor state.

The self-hosted providers are deliberately *not* skipped: their markup is server-rendered, so a
re-render is how a fresh question arrives. Skipping them would pin an expired challenge on screen.

## Volt

```blade
<?php

use Livewire\Volt\Component;
use Simtabi\Laranail\Captcha\Rules\Captcha;

new class extends Component {
    public string $captcha = '';

    public function register(): void
    {
        $this->validate(['captcha' => [Captcha::for('register')]]);
    }
};
?>

<form wire:submit="register">
    <x-captcha />
    <button type="submit">Create account</button>
</form>
```

## Inertia and Turbo

The same component works unchanged. The observer covers page swaps; nothing here is Livewire-only.

## If the token arrives empty

The widget writes the canonical `captcha` field. If your component binds a differently-named
property, bind it to `captcha` or pass the vendor's own field name — the rule accepts both. It
also accepts the two fields a server-rendered challenge posts, so the self-hosted providers work in
a Livewire form with no extra wiring.

---

[← Docs index](../../README.md#documentation)
