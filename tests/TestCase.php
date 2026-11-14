<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Auth\RecentConfirmation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fresh random key per test run – no key, not even a test key, lives in the repo.
        if (blank(config('app.key'))) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
    }

    /** Point 212: as if the person had just confirmed ("Bitte bestätigen"), for tests of sensitive actions. */
    public function confirmed(): static
    {
        return $this->withSession([RecentConfirmation::KEY => now()->getTimestamp()]);
    }
}
