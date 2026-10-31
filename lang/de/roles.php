<?php

declare(strict_types=1);

return [
    'title' => 'Rollen',
    'admin_hint' => 'Admins dürfen immer alles. Nur Admins verwalten Personen, Rollen und Updates.',
    'name' => 'Name der Rolle',
    'rights_title' => 'Das darf diese Rolle',
    'rights' => [
        'upload' => 'Belege hochladen',
        'view_all' => 'Alle Belege sehen (sonst nur die eigenen)',
        'edit' => 'Belege bearbeiten',
        'delete' => 'Belege löschen',
        'banking' => 'Bankdaten importieren und abgleichen',
        'export' => 'Exportieren',
        'tags' => 'Tags bearbeiten',
    ],
    'people' => '{0} Niemand hat diese Rolle|{1} :count Person hat diese Rolle|[2,*] :count Personen haben diese Rolle',
    'preinstalled' => 'vorinstalliert, kann nicht gelöscht werden',
    'save' => 'Speichern',
    'delete' => 'Rolle löschen',
    'add' => 'Neue Rolle',
    'create' => 'Rolle anlegen',
    'saved' => 'Die Rolle wurde gespeichert.',
    'deleted' => 'Die Rolle wurde gelöscht.',
    'in_use' => 'Diese Rolle wird noch verwendet. Geben Sie den Personen zuerst eine andere Rolle.',
    'name_taken' => 'Dieser Name ist schon vergeben.',
];
