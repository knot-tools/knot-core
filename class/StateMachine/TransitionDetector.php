<?php

declare(strict_types=1);

namespace Knot\StateMachine;

use Knot\Dolibarr\VerbDiscoverer;

final class TransitionDetector
{
    /** @var list<string> */
    private const DENY_SUBSTRINGS = [
        'link',
        'categor',
        'description',
        'title',
        'note',
        'setref',
        'setdate',
    ];

    public function __construct(private readonly VerbDiscoverer $verbs = new VerbDiscoverer())
    {
    }

    /**
     * @param object|class-string $target
     *
     * @return list<array<string, mixed>>
     */
    public function discoverTransitions($target): array
    {
        $raw = $this->verbs->discover($target);
        $out = [];
        foreach ($raw as $row) {
            $name = (string) $row['name'];
            if ($name === '' || $this->shouldExclude($name)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function shouldExclude(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::DENY_SUBSTRINGS as $frag) {
            if (str_contains($lower, $frag)) {
                return true;
            }
        }

        return false;
    }
}
