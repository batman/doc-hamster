<?php

declare(strict_types=1);

use App\Infrastructure\Environment\SystemProbe;
use Tests\Support\FakeSystemProbe;

it('reports success in German when all requirements are met', function (): void {
    $this->app->instance(SystemProbe::class, new FakeSystemProbe);

    $this->artisan('dochamster:doctor')
        ->expectsOutputToContain('Alle Mindestanforderungen sind erfüllt.')
        ->assertExitCode(0);
});

it('exits with an error when a minimum requirement is not met', function (): void {
    $probe = new FakeSystemProbe;
    $probe->phpVersion = '8.2.29';
    $this->app->instance(SystemProbe::class, $probe);

    $this->artisan('dochamster:doctor')
        ->expectsOutputToContain('8.2.29 ist zu alt')
        ->assertExitCode(1);
});

it('stops setup before touching the database when requirements fail', function (): void {
    $probe = new FakeSystemProbe;
    $probe->phpVersion = '8.2.29';
    $this->app->instance(SystemProbe::class, $probe);

    $this->artisan('dochamster:setup')
        ->expectsOutputToContain('Einrichtung abgebrochen')
        ->doesntExpectOutputToContain('db.example.com')
        ->assertExitCode(1);
});

it('passes with --local-only when only the database is unreachable', function (): void {
    $probe = new FakeSystemProbe;
    $probe->databaseVersion = null;
    $this->app->instance(SystemProbe::class, $probe);

    $this->artisan('dochamster:doctor', ['--local-only' => true])
        ->expectsOutputToContain('Alle Mindestanforderungen sind erfüllt.')
        ->doesntExpectOutputToContain('db.example.com')
        ->assertExitCode(0);
});

it('fails without --local-only when the database is unreachable', function (): void {
    $probe = new FakeSystemProbe;
    $probe->databaseVersion = null;
    $this->app->instance(SystemProbe::class, $probe);

    $this->artisan('dochamster:doctor')
        ->doesntExpectOutputToContain('db.example.com')
        ->assertExitCode(1);
});
