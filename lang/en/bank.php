<?php

declare(strict_types=1);

return [
    'title' => 'Bank',
    'import' => 'Import statement',
    'views' => [
        'open' => 'Transactions without receipt',
        'matched' => 'Matched transactions',
        'receipts' => 'Receipts without transaction',
    ],
    'empty' => [
        'open' => 'No open transactions.',
        'matched' => 'No transactions matched yet.',
        'receipts' => 'All receipts are matched.',
    ],
    'receipts_count' => '{1} 1 receipt|[2,*] :count receipts',

    'import_hint' => 'Upload the statement as a CSV file, as your bank provides it. Transactions that were already imported are not created twice.',
    'file' => 'CSV file',
    'account' => 'Account',
    'new_account' => 'New account',
    'account_name' => 'Name of the new account',
    'currency' => 'Currency of the new account',
    'next' => 'Next',
    'wrong_type' => 'This file cannot be read as a statement. Please choose a CSV file.',
    'expired' => 'The uploaded file is gone. Please upload it again.',

    'columns_title' => 'Map columns',
    'columns_hint' => 'Account: :account. Check what each column holds.',
    'columns' => [
        'date' => 'Booking date',
        'amount' => 'Amount',
        'counterparty' => 'Recipient or sender',
        'purpose' => 'Reference',
    ],
    'no_column' => 'No column',
    'preview' => 'The first rows of the file',
    'run' => 'Import',
    'none_readable' => 'No row could be read with this mapping. Please check date and amount.',
    'imported' => 'New: :new. Already there: :known. Unreadable: :unreadable.',

    'accounts' => 'Accounts',
    'transactions_count' => '{0} no transactions|{1} 1 transaction|[2,*] :count transactions',
    'delete_account' => 'Delete account',
    'confirm_delete' => 'Delete account ":name" with :count transactions? The links between receipts and transactions are lost too; the receipts themselves stay.',
    'account_deleted' => 'Account deleted.',

    'linked' => 'Matched receipts',
    'hidden' => '{1} This includes 1 receipt you may not see.|[2,*] This includes :count receipts you may not see.',
    'candidates' => 'Match a receipt',
    'candidates_hint' => 'The receipts are sorted by closeness to the booking date.',
    'no_candidates' => 'No free receipts available.',
    'link' => 'Match',
    'unlink' => 'Unmatch',
    'matched' => 'Receipt matched.',
    'unmatched' => 'Match removed.',
    'receipt_gone' => 'This receipt no longer exists.',
    'back' => 'Back to the list',
    'pages' => 'Pages',
    'previous' => 'Back',
];
