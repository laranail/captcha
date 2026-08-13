<?php

declare(strict_types=1);

use Simtabi\Laranail\Captcha\Tests\TestCase;

// Feature tests need a booted application; Unit and Arch tests deliberately do not get one.
// That split is what keeps the domain layers honest — an Action or Adapter test that
// accidentally relies on the container fails rather than quietly passing, which is the exact
// failure mode this package was rewritten to remove.
uses(TestCase::class)->in('Feature');
