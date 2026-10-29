<?php

declare(strict_types=1);

/*
| D-020: only jobs on the `net` queue (classes in App\Jobs\Net) may talk to
| the outside world. Web requests, `local` jobs and domain code never make
| outbound calls themselves – they dispatch a net job instead.
*/
arch('HTTP clients and mailers are used only in net jobs')
    ->expect([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client',
        'GuzzleHttp',
        'Symfony\Component\HttpClient',
        'Illuminate\Support\Facades\Mail',
        'Illuminate\Contracts\Mail\Mailer',
        'Illuminate\Mail\Mailer',
        'Symfony\Component\Mailer',
    ])
    ->toOnlyBeUsedIn('App\Jobs\Net');

arch('raw sockets and curl are used only in net jobs')
    ->expect(['curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_create'])
    ->not->toBeUsed()
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
