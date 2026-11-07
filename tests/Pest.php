<?php

declare(strict_types=1);

use Tests\TestCase;

/*
| Feature tests boot the Laravel application; unit and arch tests don't.
*/
pest()->extend(TestCase::class)->in('Feature');

/*
| D-020 / AK-4.7.2: the `no-network` group must pass with networking disabled
| (see .github/workflows/no-network.yml). Arch and unit tests qualify because
| they touch neither the database nor the network; local-queue job tests join
| this group once those jobs exist. Never add a test here that needs the
| database, the cache, mail, or any outbound connection.
*/
uses()->group('no-network')->in('Arch', 'Unit');
