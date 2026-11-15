<?php

declare(strict_types=1);

return [
    'login' => 'Anmelden',
    'logout' => 'Abmelden',
    'username' => 'Benutzername',
    'password' => 'Passwort',
    'remember' => 'Angemeldet bleiben',
    'failed' => 'Benutzername oder Passwort ist falsch.',
    'throttle' => 'Zu viele Versuche. Bitte warten Sie kurz.',
    'locked' => 'Zu viele falsche Versuche. Die Anmeldung ist für :minutes Minuten gesperrt.',
    'owner' => [
        'org' => 'Name der Organisation',
        'invalid' => 'Alle Angaben sind nötig, das Passwort mindestens 12 Zeichen.',
        'exists' => 'Diesen Benutzernamen gibt es schon.',
        'created' => 'Inhaber angelegt. Sie können sich jetzt anmelden.',
    ],
    // Point 215: person access (dates, pause), one-time password and "Passwort vergessen?" for everyone.
    'access' => [
        'revoked' => 'Dieser Zugang wurde entfernt.',
        'paused' => 'Ihr Zugang ist vorübergehend pausiert.',
        'not_yet' => 'Zugang ab :date.',
        'expired' => 'Ihr Zugang ist am :date abgelaufen.',
    ],
    'onetime_first' => 'Bitte legen Sie zuerst Ihr eigenes Passwort fest.',
    'forgot' => [
        'link' => 'Passwort vergessen?',
        'title' => 'Passwort vergessen',
        'text' => 'Wenn zu Ihrem Zugang eine E-Mail-Adresse hinterlegt ist, bekommen Sie einen Link, mit dem Sie ein neues Passwort festlegen. Sonst fragen Sie bitte einen Admin.',
        'email' => 'E-Mail-Adresse',
        'send' => 'Link senden',
        'sent' => 'Wenn die Adresse zu einem Zugang gehört, ist ein Link unterwegs. Er gilt 60 Minuten.',
        'back' => 'Zurück zur Anmeldung',
        'reset_title' => 'Neues Passwort festlegen',
        'new' => 'Neues Passwort',
        'repeat' => 'Neues Passwort wiederholen',
        'rule' => 'Mindestens 12 Zeichen.',
        'save' => 'Passwort speichern',
        'invalid' => 'Der Link ist ungültig oder abgelaufen. Fordern Sie bitte einen neuen an.',
        'done' => 'Ihr neues Passwort ist gespeichert. Sie können sich jetzt anmelden.',
        'mail_subject' => 'docHamster: Passwort neu festlegen',
        'mail_body' => 'Guten Tag,

für Ihren Zugang bei docHamster wurde ein neues Passwort angefordert. Mit diesem Link legen Sie es fest (gültig :minutes Minuten):

:url

Wenn Sie das nicht waren, ignorieren Sie diese E-Mail einfach.',
    ],
];
