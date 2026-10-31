<?php

declare(strict_types=1);

return [
    'title' => 'Tags',
    'defaults' => ['invoice' => 'Rechnung', 'receipt' => 'Quittung', 'contract' => 'Vertrag'],
    'name' => 'Name des Tags',
    'used' => '{0} An keinem Beleg|{1} An :count Beleg|[2,*] An :count Belegen',
    'save' => 'Speichern',
    'delete' => 'Tag löschen',
    'add' => 'Neuer Tag',
    'create' => 'Tag anlegen',
    'saved' => 'Der Tag wurde gespeichert.',
    'deleted' => 'Der Tag wurde gelöscht. Die Belege bleiben erhalten.',
    'confirm_delete' => '{1} „:name“ hängt an :count Beleg. Der Beleg bleibt erhalten, nur der Tag wird entfernt.|[2,*] „:name“ hängt an :count Belegen. Die Belege bleiben erhalten, nur der Tag wird entfernt.',
    'name_taken' => 'Dieser Name ist schon vergeben.',
    'choose' => 'Tags (freiwillig)',
    'filter' => 'Nach Tags filtern',
    'apply_filter' => 'Filtern',
    'reset_filter' => 'Filter zurücksetzen',
    'change' => 'Tags ändern',
    'changed' => 'Die Tags wurden geändert.',
    'no_match' => 'Kein Beleg hat alle gewählten Tags.',
];
