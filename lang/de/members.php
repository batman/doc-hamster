<?php

declare(strict_types=1);

return [
    'title' => 'Personen',
    'add' => 'Person hinzufügen',
    'name' => 'Name',
    'role' => 'Rolle',
    'create' => 'Konto anlegen',
    'roles' => ['owner' => 'Admin', 'member' => 'Mitarbeiter', 'accountant' => 'Steuerberater'],
    'change_role' => 'Rolle ändern',
    'role_changed' => 'Die Rolle wurde geändert.',
    'last_admin' => 'Es muss immer mindestens einen Admin geben.',
    'password_once' => 'Zugang für :name: Bitte jetzt notieren und weitergeben. Dieses Passwort wird nur dieses eine Mal angezeigt.',
    'reset' => 'Neues Passwort',
    'revoke' => 'Zugang entfernen',
    'revoked' => 'Der Zugang wurde entfernt.',
    'removed' => 'Zugang entfernt',
    // A378: restored without any way to sign in.
    'no_access' => 'Zugang neu einrichten',
    // Point 212: the new password in a dialog.
    'password_title' => 'Neues Passwort für „:name“',
    'done' => 'Fertig',
];
