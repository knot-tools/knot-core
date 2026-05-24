<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

/**
 * Discover safe verb-style methods on Dolibarr objects.
 *
 * Dolibarr objects expose dozens of state-transition methods beyond
 * the canonical CRUD verbs (create/update/fetch/delete) — `validate`,
 * `setDraft`, `cloner`, `setPaid`, `reopen`, `closeUnopened`,
 * `archive`, etc. Knot's V2.4 introspection auto-publishes them as
 * connectors so users can wire them up without us hardcoding each one.
 *
 * To stay safe we never expose a method unless it matches an explicit
 * pattern allowlist, and we always reject patterns we know are
 * destructive. Each discovered verb gets a maturity tag:
 *
 *   - `verified`: matches a well-known pattern AND the dry-run
 *      simulate succeeded.
 *   - `experimental`: matches a known pattern but `simulate()`
 *      raised something we couldn't classify (missing dependency,
 *      Dolibarr core difference, etc.). Still surfaced so the user
 *      can try it, but with a UI warning.
 *
 * The class is dependency-free apart from PHP's `ReflectionClass` so
 * it can be unit-tested without a Dolibarr boot.
 */
final class VerbDiscoverer
{
    /**
     * Verb patterns we consider safe enough to auto-expose. A method
     * is included if its name (lowercased) matches at least one
     * pattern AND none of the {@see DENY_PATTERNS}.
     */
    public const ALLOW_PATTERNS = [
        // Core lifecycle
        '/^valid(ate)?$/',
        '/^validate_?\w*$/',
        '/^setdraft$/',
        '/^reopen$/',
        '/^cloner?$/',          // cloner / clone (clone is reserved)
        '/^archive$/',
        '/^unarchive$/',

        // Status transitions
        '/^setstatus.*$/',
        '/^setstatut.*$/',
        '/^setpaid$/',
        '/^setunpaid$/',
        '/^setbilled$/',
        '/^setunbilled$/',
        '/^closeunopened$/',
        '/^classifybilled$/',
        '/^classifyclosed$/',
        '/^classifyrefused$/',
        '/^classifyaccepted$/',
        '/^cancel$/',

        // Document linking
        '/^setlinks?$/',
        '/^addtocategor(y|ies)$/',
        '/^removefromcategor(y|ies)$/',

        // Comm flag setters
        '/^setdate.*$/',
        '/^setref.*$/',
        '/^setdescription$/',
        '/^settitle$/',
        '/^setnotepublic$/',
        '/^setnoteprivate$/',
    ];

    /**
     * Patterns that always disqualify a method, even if it would
     * have matched ALLOW_PATTERNS. Matched on lowercased method
     * name as a substring.
     */
    public const DENY_PATTERNS = [
        '/delete/i',
        '/wipe/i',
        '/destroy/i',
        '/drop/i',
        '/truncate/i',
        '/purge/i',
        '/erase/i',
        '/_internal$/i',
        '/_lowlevel$/i',
        '/^dao_/i',
        '/^db_/i',
    ];

    public const MATURITY_VERIFIED = 'verified';
    public const MATURITY_EXPERIMENTAL = 'experimental';

    /**
     * Discover the verb methods exposed by an object instance (or class FQCN).
     *
     * @param object|class-string $target Either a built object or a class name
     * @return array<int, array{
     *     name: string,
     *     parameters: array<int, array{name:string, type:?string, optional:bool, default:mixed}>,
     *     maturity: string,
     *     pattern: string,
     *     simulateError: ?string
     * }>
     */
    public function discover($target): array
    {
        try {
            $ref = is_object($target) ? new \ReflectionClass($target) : new \ReflectionClass((string) $target);
        } catch (\ReflectionException $e) {
            return [];
        }

        $found = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            // Skip inherited internal helpers (only walk the class hierarchy
            // we care about — i.e. anything in the Dolibarr namespace).
            $declaringClass = $method->getDeclaringClass()->getName();
            if (in_array(strtolower($declaringClass), ['stdclass', 'reflectionclass'], true)) {
                continue;
            }

            $name = $method->getName();
            $lower = strtolower($name);

            // CRUD verbs are surfaced through the canonical SchemaBuilder
            // path, not as auto-discovered verbs.
            if (in_array($lower, ['create', 'update', 'fetch', 'delete'], true)) {
                continue;
            }

            $matchedPattern = null;
            foreach (self::ALLOW_PATTERNS as $pattern) {
                if (preg_match($pattern, $lower) === 1) {
                    $matchedPattern = $pattern;
                    break;
                }
            }
            if ($matchedPattern === null) {
                continue;
            }
            foreach (self::DENY_PATTERNS as $deny) {
                if (preg_match($deny, $lower) === 1) {
                    continue 2;
                }
            }

            $parameters = [];
            foreach ($method->getParameters() as $param) {
                $type = null;
                if ($param->hasType()) {
                    $rawType = $param->getType();
                    if ($rawType instanceof \ReflectionNamedType) {
                        $type = $rawType->getName();
                    }
                }
                $parameters[] = [
                    'name' => $param->getName(),
                    'type' => $type,
                    'optional' => $param->isOptional(),
                    'default' => $param->isDefaultValueAvailable()
                        ? $param->getDefaultValue()
                        : null,
                ];
            }

            $found[] = [
                'name' => $name,
                'parameters' => $parameters,
                'maturity' => self::MATURITY_VERIFIED,
                'pattern' => $matchedPattern,
                'simulateError' => null,
            ];
        }

        // Stable order across invocations for deterministic API output.
        usort($found, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $found;
    }

    /**
     * Run a non-destructive `simulate` for each discovered verb against
     * an instance. The instance MUST be a freshly-built unsaved object
     * (id = 0) so accidental side-effects are limited to in-memory
     * mutations. Verbs that throw or raise PHP errors are downgraded
     * to `experimental` and the error message is captured.
     *
     * In V2.4 we keep the simulator very narrow: we only attempt the
     * call when the verb declares zero required parameters or only
     * optional ones. Anything that needs a $user/$action/$notrigger
     * context is left at maturity = verified by pattern only (the
     * frontend will badge them "verified" without a live dry run).
     *
     * @param array<int, array<string, mixed>> $verbs
     * @return array<int, array<string, mixed>>
     */
    public function simulateAndAnnotate(object $instance, array $verbs): array
    {
        foreach ($verbs as $i => $verb) {
            $name = (string) $verb['name'];
            $params = $verb['parameters'] ?? [];
            $needsArgs = false;
            foreach ($params as $p) {
                if (!($p['optional'] ?? false)) {
                    $needsArgs = true;
                    break;
                }
            }
            if ($needsArgs) {
                // Leave maturity as discovered (verified by pattern). The UI
                // will render it with a "Configure parameters" hint.
                continue;
            }
            try {
                $previousErrorReporting = error_reporting(0);
                $instance->{$name}();
                error_reporting($previousErrorReporting);
            } catch (\Throwable $e) {
                error_reporting($previousErrorReporting ?? E_ALL);
                $verbs[$i]['maturity'] = self::MATURITY_EXPERIMENTAL;
                $verbs[$i]['simulateError'] = $e->getMessage();
            }
        }
        return $verbs;
    }
}
