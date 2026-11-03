<?php

declare(strict_types=1);

return [
    'title' => 'Tags',
    'defaults' => ['invoice' => 'Invoice', 'receipt' => 'Receipt', 'contract' => 'Contract'],
    'name' => 'Name of the tag',
    'used' => '{0} On no receipt|{1} On :count receipt|[2,*] On :count receipts',
    'save' => 'Save',
    'delete' => 'Delete tag',
    'add' => 'New tag',
    'create' => 'Create tag',
    'saved' => 'The tag was saved.',
    'deleted' => 'The tag was deleted. The receipts are kept.',
    'confirm_delete' => '{1} ":name" is on :count receipt. The receipt is kept, only the tag is removed.|[2,*] ":name" is on :count receipts. The receipts are kept, only the tag is removed.',
    'name_taken' => 'This name is already taken.',
    'choose' => 'Tags (optional)',
    'filter' => 'Filter by tags',
    'apply_filter' => 'Filter',
    'reset_filter' => 'Reset filter',
    'change' => 'Change tags',
    'changed' => 'The tags were changed.',
    'search' => 'Search tags',
    'new' => 'Create a new tag',
    'color' => 'Colour',
    'own_color' => 'Own colour',
    'hex' => 'Colour value',
    'delete_title' => 'Delete tag?',
    'delete_text' => '"#:name" will be removed from all receipts. The receipts stay.',
    'cancel' => 'Cancel',
    'no_match' => 'No receipt has all the chosen tags.',
];
