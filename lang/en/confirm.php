<?php

declare(strict_types=1);

// Point 212: "Please confirm" before sensitive actions (sudo mode).
return [
    'title' => 'Please confirm',
    'text' => 'This action is protected. Please confirm with your password. After that, docHamster won’t ask again for five minutes.',
    'password' => 'Your password',
    'ok' => 'Confirm',
    'wrong' => 'The password is not correct.',
    'failed' => 'Confirming didn’t work. Please try again.',
    'needed' => 'Please confirm this action with your password.',
    'again' => 'Confirmed. Please run the action again.',
];
