<?php

declare(strict_types=1);

return [
    'title' => 'Bank',
    'import' => 'Import bank statement',
    'views' => [
        'open' => 'Transactions without a document',
        'matched' => 'Matched transactions',
        'documents' => 'Documents without a transaction',
    ],
    'empty' => [
        'open' => 'No open transactions.',
        'matched' => 'No transaction matched yet.',
        'documents' => 'All documents are matched.',
    ],
    'receipts_count' => '{1} 1 document|[2,*] :count documents',

    'import_hint' => 'Upload the bank statement as a CSV file, exactly as your bank provides it. Transactions that were already imported are not added twice.',
    'file' => 'CSV file',
    'account' => 'Account',
    'new_account' => 'New account',
    'account_name' => 'Name of the new account',
    'currency' => 'Currency of the new account',
    'next' => 'Next',
    'wrong_type' => 'This file cannot be read as a bank statement. Please choose a CSV file.',
    'expired' => 'The uploaded file is gone. Please upload it again.',

    'columns_title' => 'Map columns',
    'columns_hint' => 'Account: :account. Check which column contains what.',
    'columns' => [
        'date' => 'Booking date',
        'amount' => 'Amount',
        'counterparty' => 'Payee or payer',
        'purpose' => 'Reference',
    ],
    'no_column' => 'No column',
    'preview' => 'The first rows of the file',
    'run' => 'Import',
    'none_readable' => 'No row could be read with this mapping. Please check date and amount.',
    'imported' => 'New: :new. Already present: :known. Unreadable: :unreadable.',

    'accounts' => 'Accounts',
    'transactions_count' => '{0} no transactions|{1} 1 transaction|[2,*] :count transactions',
    'delete_account' => 'Delete account',
    'confirm_delete' => 'Delete account “:name” with :count transactions? The links to documents are lost too, but the documents themselves stay.',
    'account_deleted' => 'Account deleted.',

    'linked' => 'Matched documents',
    'hidden' => '{1} This includes 1 document you are not allowed to see.|[2,*] This includes :count documents you are not allowed to see.',
    'candidates' => 'Match a document',
    'candidates_hint' => 'Documents are sorted by how close they are to the booking date.',
    'no_candidates' => 'No free documents available.',
    'link' => 'Match',
    'unlink' => 'Unmatch',
    'matched' => 'Document matched.',
    'unmatched' => 'Match removed.',
    'receipt_gone' => 'This document no longer exists.',
    'back' => 'Back to list',
    'pages' => 'Pages',
    'previous' => 'Back',
];
