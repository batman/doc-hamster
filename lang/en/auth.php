<?php

declare(strict_types=1);

return [
    'login' => 'Sign in',
    'logout' => 'Sign out',
    'username' => 'Username',
    'password' => 'Password',
    'remember' => 'Stay signed in',
    'failed' => 'Username or password is incorrect.',
    'throttle' => 'Too many attempts. Please wait a moment.',
    'locked' => 'Too many wrong attempts. Sign-in is locked for :minutes minutes.',
    'owner' => [
        'org' => 'Organisation name',
        'invalid' => 'All fields are required, and the password must be at least 12 characters.',
        'exists' => 'This username already exists.',
        'created' => 'Owner created. You can sign in now.',
    ],
    // Point 215: person access (dates, pause), one-time password and "Forgot password?" for everyone.
    'access' => [
        'revoked' => 'This access was removed.',
        'paused' => 'Your access is paused for the time being.',
        'not_yet' => 'Access from :date.',
        'expired' => 'Your access expired on :date.',
    ],
    'onetime_first' => 'Please set your own password first.',
    'forgot' => [
        'link' => 'Forgot password?',
        'title' => 'Forgot password',
        'text' => 'If an e-mail address is stored for your access, you get a link to set a new password. Otherwise please ask an Admin.',
        'email' => 'E-mail address',
        'send' => 'Send link',
        'sent' => 'If the address belongs to an access, a link is on its way. It is valid for 60 minutes.',
        'back' => 'Back to sign-in',
        'reset_title' => 'Set a new password',
        'new' => 'New password',
        'repeat' => 'Repeat new password',
        'rule' => 'At least 12 characters.',
        'save' => 'Save password',
        'invalid' => 'The link is invalid or has expired. Please request a new one.',
        'done' => 'Your new password is saved. You can sign in now.',
        'mail_subject' => 'docHamster: set a new password',
        'mail_body' => "Hello,

a new password was requested for your docHamster access. Use this link to set it (valid for :minutes minutes):

:url

If this wasn't you, just ignore this e-mail.",
    ],
];
