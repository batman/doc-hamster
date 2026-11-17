<?php

declare(strict_types=1);

// D-040: Settings → Assistants, the consent page and the way back to the assistant.
return [
    'title' => 'Assistants',
    'intro' => 'An AI assistant such as Claude can help with the setup, e.g. link documents to bookings. docHamster itself uses no AI; here you only open the door for it.',
    'door' => 'Access for assistants',
    'state' => 'Access',
    'state_off' => 'Off',
    'state_on' => 'On',
    'off_hint' => 'Closed: no assistant and no access key gets in.',
    'on_hint' => 'Open: everybody can connect their assistant – with their own rights. Closing disconnects every assistant.',
    'close_title' => 'Close the access?',
    'close_text' => 'Every connected assistant is disconnected. Access keys stop working until you open it again.',
    'close_ok' => 'Close',
    'saved_on' => 'Access for assistants is open.',
    'saved_off' => 'Access for assistants is closed.',
    'connect_title' => 'How to connect Claude',
    'url' => 'Address for the assistant',
    'steps' => [
        'In Claude: Settings → Connectors → “Add custom connector”.',
        'Enter “docHamster” as the name, paste the address above and add it.',
        'At docHamster click “Connect”: docHamster asks what Claude may do – “Allow”. Done.',
    ],
    'connections' => 'Connected assistants',
    'connected_at' => 'connected :date',
    'none' => 'No assistant connected yet.',
    'disconnect' => 'Disconnect',
    'disconnect_title' => 'Disconnect “:name”?',
    'disconnect_text' => 'The assistant can no longer get in. You can connect again in Claude at any time.',
    'disconnected' => '“:name” disconnected.',
    'log' => ['on' => 'Access for assistants opened', 'off' => 'Access for assistants closed, every assistant disconnected'],
    'consent' => [
        'title' => 'Connect an assistant',
        'heading' => '“:name” wants to access docHamster',
        'text' => '“:name” (:host) wants access as :person in “:org” – with your rights.',
        'scope' => 'May',
        'never' => 'Sees bookings and documents with their recognised text, never the scans. Never deletes anything. Disconnect any time under My account.',
        'allow' => 'Allow',
        'deny' => 'Deny',
    ],
    'back' => [
        'allowed' => '“:name” is connected',
        'denied' => '“:name” was denied',
        'text' => 'Going back to “:name” in a moment.',
        'go' => 'Back now',
    ],
    'problem' => 'Connecting does not work like this',
    'closed_page' => 'Access for assistants is closed. The owner can open it under Settings → Assistants.',
    'unknown_client' => 'docHamster does not know this assistant. Please connect again in the assistant.',
    'bad_redirect' => 'The assistant’s return address does not match. Please connect again in the assistant.',
];
