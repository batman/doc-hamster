<?php

declare(strict_types=1);

return [
    'setup' => 'Drucker einrichten',
    'default_title' => 'Druck vom :date',
    'new_printer' => 'Windows-PC',
    'printers' => 'Ihre Computer mit dem Drucker „docHamster“',
    'no_printers' => 'Noch kein Computer eingerichtet.',
    'last_used' => 'zuletzt: :date',
    'last_seen' => 'verbunden: :date',
    'silent_pill' => 'Keine Verbindung',
    'silent' => 'Seit über :hours Stunden keine Verbindung. Ist der Computer an, läuft das Hintergrundprogramm nicht – den Drucker hier neu einrichten.',
    'paired_at' => 'gekoppelt am :date',
    'delete' => 'Löschen',
    'delete_title' => 'Eintrag löschen?',
    'delete_text' => 'Nur dieser Eintrag wird gelöscht. Drucke von diesem Rechner mit dieser Kopplung kommen dann nicht mehr an.',
    'cancel' => 'Abbrechen',
    'deleted' => '„:name“ ist gelöscht.',
    'settings_title' => 'Drucker',
    'no_printer_hint' => 'Noch kein Drucker eingerichtet –',
    'no_printer_link' => 'Einstellungen → Eingang → Drucker',
    'howto_title' => 'So richten Sie den Drucker ein (Windows)',
    'howto' => [
        'Auf „Drucker einrichten“ klicken. Das lädt eine kleine Datei für Windows herunter, die schon mit Ihrem Konto verknüpft ist. Sie gilt 24 Stunden und nur für einen Computer.',
        'Die Datei doppelklicken und die Windows-Frage nach Administratorrechten bestätigen.',
        'Fertig: In jedem Programm gibt es jetzt den Drucker „docHamster“. Was Sie darauf drucken, landet im Posteingang – auch wenn der Computer gerade offline war, dann etwas später. Nach einem Neustart läuft alles von selbst weiter.',
        'Entfernen: unter Windows bei „Apps“ den Eintrag „docHamster Drucker“ deinstallieren. Hier den Eintrag löschen, dann kommt von dem Computer nichts mehr an.',
    ],
];
