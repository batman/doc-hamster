<?php

declare(strict_types=1);

/*
| D-020: only jobs on the `net` queue (classes in App\Jobs\Net) may talk to
| the outside world. Web requests, `local` jobs and domain code never make
| outbound calls themselves – they dispatch a net job instead.
|
| The arch tests below see class and function *names*. Calls whose argument
| decides (file_get_contents('https://…')) are caught by the PHPStan rules in
| tests/Support/PhpStan instead – see tests/Unit/PhpStan/EgressRulesTest.php.
*/
arch('HTTP clients, mailers and notifications are used only in net jobs')
    ->expect([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client',
        'GuzzleHttp',
        'Symfony\Component\HttpClient',
        'Illuminate\Support\Facades\Mail',
        'Illuminate\Contracts\Mail\Mailer',
        'Illuminate\Mail\Mailer',
        'Illuminate\Mail\MailManager',
        'Illuminate\Mail\Mailable',
        'Symfony\Component\Mailer',
        'Illuminate\Support\Facades\Notification',
        'Illuminate\Notifications',
    ])
    ->toOnlyBeUsedIn('App\Jobs\Net');

// Global facade aliases (\Http, \Mail, \Notification) are covered by the
// DisallowNetworkFacadeAliasesRule PHPStan rule instead: the arch layer
// cannot see classes that only exist via Laravel's runtime alias loader.
arch('raw sockets and curl are used only in net jobs')
    ->expect(['curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_create'])
    ->not->toBeUsed()
    ->ignoring('App\Jobs\Net');

arch('local jobs never touch the network (D-020)')
    ->expect('App\Jobs')
    ->not->toUse([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client',
        'GuzzleHttp',
        'Symfony\Component\HttpClient',
        'Illuminate\Support\Facades\Mail',
        'Illuminate\Contracts\Mail\Mailer',
        'Illuminate\Mail\Mailer',
        'Illuminate\Mail\MailManager',
        'Illuminate\Mail\Mailable',
        'Symfony\Component\Mailer',
        'Illuminate\Support\Facades\Notification',
        'Illuminate\Notifications',
    ])
    ->ignoring('App\Jobs\Net');

arch('domain code does not depend on the web or console layer (PLAN §5.3)')
    ->expect('App\Domain')
    ->not->toUse(['App\Http', 'App\Console']);

arch('domain models are final')
    ->expect('App\Domain')
    ->classes()
    ->toBeFinal();

arch('application code uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging leftovers')
    ->preset()->php();
