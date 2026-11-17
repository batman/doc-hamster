<?php

declare(strict_types=1);

namespace App\Modules;

use RuntimeException;

/** A module used something the owner did not allow (or an unknown event) – see ModuleContext. */
final class ModuleRightMissing extends RuntimeException {}
