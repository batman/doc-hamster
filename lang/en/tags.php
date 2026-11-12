<?php

declare(strict_types=1);

return [
    'title' => 'Tags',
    'defaults' => ['invoice' => 'Invoice', 'document' => 'Document', 'contract' => 'Contract'],
    'name' => 'Name of the tag',
    'used' => '{0} On no document|{1} On :count document|[2,*] On :count documents',
    'save' => 'Save',
    'delete' => 'Delete tag',
    'add' => 'New tag',
    'create' => 'Create tag',
    'saved' => 'The tag was saved.',
    'deleted' => 'The tag was deleted. The documents are kept.',
    'confirm_delete' => '{1} ":name" is on :count document. The document is kept, only the tag is removed.|[2,*] ":name" is on :count documents. The documents are kept, only the tag is removed.',
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
    'delete_text' => '"#:name" will be removed from all documents. The documents stay.',
    'cancel' => 'Cancel',
    'no_match' => 'No document has all the chosen tags.',
];
