<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Org\CurrentOrg;
use App\Http\Badges;
use Illuminate\Http\JsonResponse;

/** The menu badge numbers, fetched by app.js about once a minute. */
final class BadgeController
{
    public function __invoke(CurrentOrg $org): JsonResponse
    {
        return response()->json(Badges::counts($org), 200, ['Cache-Control' => 'no-store']);
    }
}
