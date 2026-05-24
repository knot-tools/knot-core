<?php

declare(strict_types=1);

namespace Knot\StateMachine;

use Knot\Errors\DolibarrErrorTranslator;
use Knot\Errors\InvalidTransitionError;

/**
 * L3 runtime bridge — single invocation path for Dolibarr transition methods.
 */
final class RuntimeValidator
{
    public function __construct(private readonly DolibarrErrorTranslator $translator = new DolibarrErrorTranslator())
    {
    }

    /**
     * Validates the transition target and builds Reflection invoke arguments **without**
     * calling Dolibarr — shared by dry-run previews and {@see invokeTransition()}.
     *
     * @param array<string, mixed> $namedArguments
     *
     * @return array{rm:\ReflectionMethod, args:list<mixed>}
     *
     * @throws InvalidTransitionError
     * @throws \Knot\Errors\KnotError
     */
    public function resolveInvocation(object $object, string $method, object $user, array $namedArguments = []): array
    {
        $method = trim($method);
        if ($method === '' || !method_exists($object, $method)) {
            throw new InvalidTransitionError(
                'KNOT_SM_METHOD_NOT_FOUND',
                'This Dolibarr object does not expose the requested transition method.',
                'Method not found: ' . $method,
                null,
                ['method' => $method, 'class' => $object::class],
                'Pick a transition returned by the state machine API for this object type.',
                'warning'
            );
        }

        try {
            $rm = new \ReflectionMethod($object, $method);
            $args = $this->buildArguments($rm, $user, $namedArguments);

            return ['rm' => $rm, 'args' => $args];
        } catch (InvalidTransitionError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->translator->translate($e, ['method' => $method, 'class' => $object::class]);
        }
    }

    /**
     * @param array<string, mixed> $namedArguments
     *
     * @throws \Knot\Errors\KnotError
     */
    public function invokeTransition(object $object, string $method, object $user, array $namedArguments = []): int
    {
        $trimmed = trim($method);
        ['rm' => $rm, 'args' => $args] = $this->resolveInvocation($object, $trimmed, $user, $namedArguments);

        try {
            $res = $rm->invokeArgs($object, $args);
            $code = is_int($res) ? $res : (int) $res;
        } catch (InvalidTransitionError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->translator->translate($e, ['method' => $trimmed, 'class' => $object::class]);
        }

        if ($code < 0) {
            $technical = (string) ($object->error ?? '');
            $technical = $technical !== '' ? $technical : ('Dolibarr transition returned ' . $code);
            throw $this->translator->translate(
                new \RuntimeException($technical),
                ['method' => $trimmed, 'class' => $object::class, 'return_code' => $code]
            );
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $namedArguments
     *
     * @return list<mixed>
     */
    private function buildArguments(\ReflectionMethod $rm, object $user, array $namedArguments): array
    {
        $args = [];
        foreach ($rm->getParameters() as $param) {
            $name = $param->getName();
            if ($param->hasType()) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $tn = strtolower($type->getName());
                    if (str_ends_with($tn, 'user') || strtolower($name) === 'user') {
                        $args[] = $user;
                        continue;
                    }
                }
            }
            if (strtolower($name) === 'user') {
                $args[] = $user;
                continue;
            }
            if (array_key_exists($name, $namedArguments)) {
                $args[] = $namedArguments[$name];
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }
            $args[] = 0;
        }

        return $args;
    }
}
