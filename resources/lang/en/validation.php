<?php

declare(strict_types=1);

/**
 * One message per failure reason.
 *
 * Replaces the single mutable static the old package used, which every rule instance shared: a
 * test that customised it changed the message for every later test in the run, and an application
 * that customised it in a service provider changed it globally.
 *
 * The wording is deliberately uninformative about *why*. "Solved on the wrong host" and "replayed"
 * are precise, useful in a log, and a free oracle for someone probing the protection — so the
 * distinctions are kept in the result object and the event, not on the page.
 */
return [
    'missing-response'     => 'Please complete the captcha.',
    'invalid-response'     => 'The captcha verification failed. Please try again.',
    'expired-or-duplicate' => 'The captcha expired. Please try again.',
    'replayed'             => 'The captcha expired. Please try again.',
    'stale'                => 'The captcha expired. Please try again.',
    'hostname-mismatch'    => 'The captcha verification failed. Please try again.',
    'action-mismatch'      => 'The captcha verification failed. Please try again.',
    'low-score'            => 'The captcha verification failed. Please try again.',
    'not-configured'       => 'The captcha is unavailable. Please try again later.',
    'invalid-secret'       => 'The captcha is unavailable. Please try again later.',
    'transport-error'      => 'The captcha is unavailable. Please try again later.',
    'malformed-response'   => 'The captcha is unavailable. Please try again later.',
    'provider-error'       => 'The captcha verification failed. Please try again.',
];
