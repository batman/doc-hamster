<?php

declare(strict_types=1);

return [
    'title' => 'Settings',
    'fold' => 'Fold',
    'expand' => 'Open :name',
    'groups' => [
        'people' => 'People & roles',
        'bank' => 'Bank',
        'tags' => 'Tags',
        'export' => 'Export',
        'update' => 'Update',
    ],
    'links' => [
        'members' => ['title' => 'People', 'text' => 'Who has access'],
        'roles' => ['title' => 'Roles', 'text' => 'What a role may do'],
        'tags' => ['title' => 'Tags', 'text' => 'Manage labels for receipts'],
        'bank' => ['title' => 'Accounts and import', 'text' => 'Import statements, manage accounts'],
        'export' => ['title' => 'Export', 'text' => 'Receipts as ZIP, for example for the tax advisor'],
        'shares' => ['title' => 'Links', 'text' => 'Validity and valid links for sending'],
        'update' => ['title' => 'Update', 'text' => 'Look for a new version and install it'],
        'phone' => ['title' => 'Phone test', 'text' => 'Check whether your phone can do everything'],
    ],
];
