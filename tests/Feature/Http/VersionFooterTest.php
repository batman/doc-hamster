<?php

declare(strict_types=1);

it('shows the version at the bottom of the page', function (): void {
    config(['dochamster.version' => '9.9.9']);

    $this->get('/login')->assertSee('Version 9.9.9');
});
