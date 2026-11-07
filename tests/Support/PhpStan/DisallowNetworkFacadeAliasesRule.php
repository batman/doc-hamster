<?php

declare(strict_types=1);

namespace Tests\Support\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * D-020: Laravel's global facade aliases (`\Http`, `\Mail`, `\Notification`)
 * resolve to the network clients, so they may only be used in the net jobs –
 * same as the imported facades the arch test covers. Any other class named
 * e.g. `Mail` inside a namespace is untouched: only single-segment global
 * names are flagged.
 *
 * @implements Rule<StaticCall>
 */
final class DisallowNetworkFacadeAliasesRule implements Rule
{
    /** @var list<string> */
    private const array ALIASES = ['http', 'mail', 'notification'];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->class instanceof Name) {
            return [];
        }

        // After name resolution a global alias is a lone segment (`Http`);
        // anything qualified (`App\Foo\Http`, `Illuminate\…`) is not an alias.
        $name = ltrim($node->class->toString(), '\\');

        if (str_contains($name, '\\') || ! in_array(strtolower($name), self::ALIASES, true)) {
            return [];
        }

        if (self::isNetJob($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Global network facade alias \\%s may only be used in net jobs (D-020).',
                $name
            ))->identifier('dochamster.egressFacadeAlias')->build(),
        ];
    }

    private static function isNetJob(Scope $scope): bool
    {
        $class = $scope->getClassReflection()?->getName();

        return $class !== null && str_starts_with($class, 'App\\Jobs\\Net\\');
    }
}
