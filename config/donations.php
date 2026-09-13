<?php

$driver = env('DONATION_DRIVER', 'disabled');

return [
    'driver' => in_array($driver, ['disabled', 'sandbox'], true) ? $driver : 'disabled',
    'checkout_minutes' => 30,
];
