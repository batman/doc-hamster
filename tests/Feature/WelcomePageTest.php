<?php

declare(strict_types=1);

it('shows the placeholder page in German', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('DocHamster wird gerade gebaut.');
});
