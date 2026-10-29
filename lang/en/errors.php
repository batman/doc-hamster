<?php

declare(strict_types=1);

return [
    'home' => 'Go to the start page',
    403 => ['title' => 'No access', 'text' => 'This page is not shared with you.'],
    404 => ['title' => 'Page not found', 'text' => 'This page doesn’t exist (any more).'],
    419 => ['title' => 'Session expired', 'text' => 'Please reload the page and try again.'],
    429 => ['title' => 'Too many attempts', 'text' => 'Please wait a minute and then try again.'],
    500 => ['title' => 'Something went wrong', 'text' => 'An error on the server. Please try again later.'],
    503 => ['title' => 'Not available right now', 'text' => 'DocHamster is under maintenance and will be back shortly.'],
];
