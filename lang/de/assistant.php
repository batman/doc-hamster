<?php

declare(strict_types=1);

// D-040: Einstellungen → Assistenten, the consent page and the way back to the assistant.
return [
    'title' => 'Assistenten',
    'intro' => 'Ein KI-Assistent wie Claude kann beim Einrichten helfen, zum Beispiel Dokumente mit Buchungen verknüpfen. docHamster selbst nutzt keine KI; hier öffnen Sie nur die Tür dafür.',
    'door' => 'Zugang für Assistenten',
    'state' => 'Zugang',
    'state_off' => 'Aus',
    'state_on' => 'An',
    'off_hint' => 'Zu: Kein Assistent und kein Zugangsschlüssel kommt hinein.',
    'on_hint' => 'Offen: Jede Person kann ihren Assistenten verbinden – mit ihren eigenen Rechten. Schließen trennt alle Assistenten.',
    'close_title' => 'Zugang schließen?',
    'close_text' => 'Alle verbundenen Assistenten werden getrennt. Zugangsschlüssel funktionieren nicht mehr, bis Sie wieder öffnen.',
    'close_ok' => 'Schließen',
    'saved_on' => 'Zugang für Assistenten ist offen.',
    'saved_off' => 'Zugang für Assistenten ist zu.',
    'connect_title' => 'So verbinden Sie Claude',
    'url' => 'Adresse für den Assistenten',
    'steps' => [
        'In Claude: Einstellungen → Konnektoren → „Benutzerdefinierten Konnektor hinzufügen“.',
        'Als Namen „docHamster“ eintragen, die Adresse oben einfügen und hinzufügen.',
        'Bei docHamster auf „Verbinden“ klicken: docHamster fragt, was Claude darf – „Erlauben“. Fertig.',
    ],
    'connections' => 'Verbundene Assistenten',
    'connected_at' => 'verbunden :date',
    'none' => 'Noch kein Assistent verbunden.',
    'disconnect' => 'Trennen',
    'disconnect_title' => '„:name“ trennen?',
    'disconnect_text' => 'Der Assistent kommt ab sofort nicht mehr hinein. Neu verbinden geht jederzeit wieder in Claude.',
    'disconnected' => '„:name“ getrennt.',
    'log' => ['on' => 'Zugang für Assistenten geöffnet', 'off' => 'Zugang für Assistenten geschlossen, alle Assistenten getrennt'],
    'consent' => [
        'title' => 'Assistent verbinden',
        'heading' => '„:name“ möchte auf docHamster zugreifen',
        'text' => '„:name“ (:host) möchte als :person in „:org“ zugreifen – mit Ihren Rechten.',
        'scope' => 'Darf',
        'never' => 'Sieht Buchungen und Dokumente mit ihrem erkannten Text, nie die Scans. Löscht nie etwas. Trennen geht jederzeit unter Mein Konto.',
        'allow' => 'Erlauben',
        'deny' => 'Ablehnen',
    ],
    'back' => [
        'allowed' => '„:name“ ist verbunden',
        'denied' => '„:name“ wurde abgelehnt',
        'text' => 'Es geht gleich zurück zu „:name“.',
        'go' => 'Jetzt zurück',
    ],
    'problem' => 'Verbinden geht so nicht',
    'closed_page' => 'Der Zugang für Assistenten ist zu. Der Inhaber kann ihn unter Einstellungen → Assistenten öffnen.',
    'unknown_client' => 'Dieser Assistent ist docHamster nicht bekannt. Bitte im Assistenten neu verbinden.',
    'bad_redirect' => 'Die Rücksprung-Adresse des Assistenten passt nicht. Bitte im Assistenten neu verbinden.',
];
