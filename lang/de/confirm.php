<?php

declare(strict_types=1);

// Point 212: "Bitte bestätigen" before sensitive actions (sudo mode).
return [
    'title' => 'Bitte bestätigen',
    'text' => 'Diese Aktion ist geschützt. Bitte bestätigen Sie mit Ihrem Passwort. Danach fragt docHamster fünf Minuten lang nicht noch einmal.',
    'password' => 'Ihr Passwort',
    'ok' => 'Bestätigen',
    'wrong' => 'Das Passwort stimmt nicht.',
    'failed' => 'Bestätigen hat nicht geklappt. Bitte noch einmal versuchen.',
    'needed' => 'Bitte bestätigen Sie diese Aktion mit Ihrem Passwort.',
    'again' => 'Bestätigt. Bitte führen Sie die Aktion noch einmal aus.',
];
