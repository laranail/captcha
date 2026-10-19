<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Testing\AssertsNoLiteralCredentials;

uses(AssertsNoLiteralCredentials::class);

it('keeps literal credentials out of the tests', function (): void {
    $this->assertNoLiteralCredentialsIn([dirname(__DIR__)], minFiles: 24);
    expect(true)->toBeTrue();
});
