<?php

declare(strict_types=1);

return [
    'title' => 'Druckerablage',
    'setup' => 'Drucker einrichten',
    'empty' => 'Noch nichts gedruckt. Drucken Sie aus einem beliebigen Programm auf den Drucker „Hamster“, dann erscheint das Dokument hier.',
    'printed_at' => 'gedruckt am :date',
    'accept' => 'Als Dokument übernehmen',
    'accepted' => '„:title“ ist jetzt ein Dokument.',
    'default_title' => 'Druck vom :date',
    'new_printer' => 'Windows-PC',
    'printers' => 'Ihre Computer mit dem Drucker „Hamster“',
    'no_printers' => 'Noch kein Computer eingerichtet.',
    'last_used' => 'zuletzt: :date',
    'paired_at' => 'gekoppelt am :date',
    'delete' => 'Löschen',
    'delete_title' => 'Eintrag löschen?',
    'delete_text' => 'Nur dieser Eintrag wird gelöscht. Drucke von diesem Rechner mit dieser Kopplung kommen dann nicht mehr an.',
    'cancel' => 'Abbrechen',
    'deleted' => '„:name“ ist gelöscht.',
    'settings_title' => 'Drucker',
    'no_printer_hint' => 'Noch kein Drucker eingerichtet –',
    'no_printer_link' => 'Einstellungen → Drucker',
    'howto_title' => 'So richten Sie den Drucker ein (Windows)',
    'howto' => [
        'Auf „Drucker einrichten“ klicken. Das lädt eine kleine Datei für Windows herunter, die schon mit Ihrem Konto verknüpft ist. Sie gilt 24 Stunden und nur für einen Computer.',
        'Die Datei doppelklicken und die Windows-Frage nach Administratorrechten bestätigen.',
        'Fertig: In jedem Programm gibt es jetzt den Drucker „Hamster“. Was Sie darauf drucken, landet in der Druckerablage – auch wenn der Computer gerade offline war, dann etwas später.',
        'Entfernen: unter Windows bei „Apps“ den Eintrag „DocHamster Drucker“ deinstallieren. Hier den Eintrag löschen, dann kommt von dem Computer nichts mehr an.',
    ],
];
