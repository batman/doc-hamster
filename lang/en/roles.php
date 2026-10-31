<?php

declare(strict_types=1);

return [
    'title' => 'Roles',
    'admin_hint' => 'Admins may always do everything. Only Admins manage people, roles and updates.',
    'name' => 'Name of the role',
    'rights_title' => 'This role may',
    'rights' => [
        'upload' => 'Upload receipts',
        'view_all' => 'See all receipts (otherwise only their own)',
        'edit' => 'Edit receipts',
        'delete' => 'Delete receipts',
        'banking' => 'Import and match bank data',
        'export' => 'Export',
        'tags' => 'Edit tags',
    ],
    'people' => '{0} Nobody has this role|{1} :count person has this role|[2,*] :count people have this role',
    'preinstalled' => 'pre-installed, can not be deleted',
    'save' => 'Save',
    'delete' => 'Delete role',
    'add' => 'New role',
    'create' => 'Create role',
    'saved' => 'The role was saved.',
    'deleted' => 'The role was deleted.',
    'in_use' => 'This role is still in use. Give those people another role first.',
    'name_taken' => 'This name is already taken.',
];
