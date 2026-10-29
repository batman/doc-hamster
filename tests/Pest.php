<?php

declare(strict_types=1);

use Tests\TestCase;

/*
| Feature tests boot the Laravel application; unit and arch tests don't.
*/
pest()->extend(TestCase::class)->in('Feature');
