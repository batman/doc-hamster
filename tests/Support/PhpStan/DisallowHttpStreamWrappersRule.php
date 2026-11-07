<?php

declare(strict_types=1);

namespace Tests\Support\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * D-020: filesystem functions that accept URLs must never be called with a
 * remote URL outside the net jobs. `file_get_contents('https://…')` is an
 * outbound call the arch test cannot see – it only sees the function name,
 * not the argument. Calls with a variable or a local path stay allowed.
 *
 * @implements Rule<FuncCall>
 */
final class DisallowHttpStreamWrappersRule implements Rule
{
    /** @var list<string> */
    private const array FUNCTIONS = ['copy', 'file', 'file_get_contents', 'fopen', 'get_headers', 'readfile'];

    /** @var list<string> */
    private const array REMOTE_WRAPPERS = ['http://', 'https://', 'ftp://', 'ftps://'];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $function = strtolower($node->name->toString());

        if (! in_array($function, self::FUNCTIONS, true)) {
            return [];
        }

        $first = $node->getArgs()[0] ?? null;

        if (! $first instanceof Node\Arg || ! $first->value instanceof String_) {
            return [];
        }

        $url = strtolower($first->value->value);
        $remote = false;

        foreach (self::REMOTE_WRAPPERS as $wrapper) {
            if (str_starts_with($url, $wrapper)) {
                $remote = true;
                break;
            }
        }

        if (! $remote || self::isNetJob($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Outbound stream wrapper call %s() with a remote URL is only allowed in net jobs (D-020).',
                $function
            ))->identifier('dochamster.egressStreamWrapper')->build(),
        ];
    }

    private static function isNetJob(Scope $scope): bool
    {
        $class = $scope->getClassReflection()?->getName();

        return $class !== null && str_starts_with($class, 'App\\Jobs\\Net\\');
    }
}
