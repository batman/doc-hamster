<?php

declare(strict_types=1);

return [
    'title' => 'Tags',
    'defaults' => ['invoice' => 'Rechnung', 'receipt' => 'Quittung', 'contract' => 'Vertrag'],
    'name' => 'Name des Tags',
    'used' => '{0} An keinem Dokument|{1} An :count Dokument|[2,*] An :count Dokumenten',
    'save' => 'Speichern',
    'delete' => 'Tag löschen',
    'add' => 'Neuer Tag',
    'create' => 'Tag anlegen',
    'saved' => 'Der Tag wurde gespeichert.',
    'deleted' => 'Der Tag wurde gelöscht. Die Dokumente bleiben erhalten.',
    'confirm_delete' => '{1} „:name“ hängt an :count Dokument. Das Dokument bleibt erhalten, nur der Tag wird entfernt.|[2,*] „:name“ hängt an :count Dokumenten. Die Dokumente bleiben erhalten, nur der Tag wird entfernt.',
    'name_taken' => 'Dieser Name ist schon vergeben.',
    'choose' => 'Tags (freiwillig)',
    'filter' => 'Nach Tags filtern',
    'apply_filter' => 'Filtern',
    'reset_filter' => 'Filter zurücksetzen',
    'change' => 'Tags ändern',
    'changed' => 'Die Tags wurden geändert.',
    'search' => 'Tags suchen',
    'new' => 'Neuen Tag anlegen',
    'color' => 'Farbe',
    'own_color' => 'Eigene Farbe',
    'hex' => 'Farbwert',
    'delete_title' => 'Tag löschen?',
    'delete_text' => '„#:name“ wird von allen Dokumenten entfernt. Die Dokumente bleiben erhalten.',
    'cancel' => 'Abbrechen',
    'no_match' => 'Kein Dokument hat alle gewählten Tags.',
];
