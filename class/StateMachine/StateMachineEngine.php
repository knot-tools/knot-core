<?php

declare(strict_types=1);

namespace Knot\StateMachine;

use Knot\Dolibarr\ObjectFactory;
use Knot\Dolibarr\VerbDiscoverer;
use Knot\Errors\StateMachineExtractionError;

final class StateMachineEngine implements StateMachineEngineInterface
{
    public function __construct(
        private readonly ?ObjectFactory $objectFactory = null,
        private readonly StateExtractor $extractor = new StateExtractor(),
        private readonly TransitionDetector $transitionDetector = new TransitionDetector(),
        private readonly StateMachineCache $cache = new StateMachineCache(),
        private readonly RuntimeValidator $runtime = new RuntimeValidator(),
    ) {
    }

    private function factory(): ObjectFactory
    {
        return $this->objectFactory ?? new ObjectFactory();
    }

    public function getStates(string $slugOrFqcn, \DoliDB $db): array
    {
        $fqcn = $this->resolveFqcn($slugOrFqcn, $db);
        $key = $this->cacheKey($slugOrFqcn, $fqcn);
        $cached = $this->cache->read($key);
        if (isset($cached['states']) && is_array($cached['states'])) {
            /** @var array<string, int> */
            return $cached['states'];
        }

        $states = $this->extractor->extractStatusConstants($fqcn);
        $transitions = $this->transitionDetector->discoverTransitions($fqcn);
        $this->cache->write($key, ['states' => $states, 'transitions' => $transitions]);

        return $states;
    }

    public function getTransitions(string $slugOrFqcn, \DoliDB $db): array
    {
        $fqcn = $this->resolveFqcn($slugOrFqcn, $db);
        $key = $this->cacheKey($slugOrFqcn, $fqcn);
        $cached = $this->cache->read($key);
        if (isset($cached['transitions']) && is_array($cached['transitions'])) {
            return $cached['transitions'];
        }

        $states = $this->extractor->extractStatusConstants($fqcn);
        $transitions = $this->transitionDetector->discoverTransitions($fqcn);
        $this->cache->write($key, ['states' => $states, 'transitions' => $transitions]);

        return $transitions;
    }

    public function getCurrentState(object $instance, array $statesMap): ?string
    {
        return $this->extractor->resolveLogicalState($instance, $statesMap);
    }

    public function transition(object $instance, string $methodName, object $user, array $namedArguments = []): array
    {
        $code = $this->runtime->invokeTransition($instance, $methodName, $user, $namedArguments);

        return ['changed' => true, 'method' => $methodName, 'return_code' => $code];
    }

    public function getProbableTransitions(object $instance, string $slugOrFqcn, \DoliDB $db): array
    {
        $states = $this->getStates($slugOrFqcn, $db);
        $logical = $this->getCurrentState($instance, $states);
        $verbs = $this->getTransitions($slugOrFqcn, $db);

        $rankOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
        $out = [];
        foreach ($verbs as $verb) {
            $name = (string) ($verb['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $probability = TransitionProbability::rank($logical, $name);
            $out[] = [
                'method' => $name,
                'maturity' => (string) ($verb['maturity'] ?? VerbDiscoverer::MATURITY_VERIFIED),
                'probability' => $probability,
                'pattern' => (string) ($verb['pattern'] ?? ''),
            ];
        }

        usort(
            $out,
            static function (array $a, array $b) use ($rankOrder): int {
                $da = $rankOrder[$a['probability']] ?? 0;
                $db = $rankOrder[$b['probability']] ?? 0;
                if ($da !== $db) {
                    return $db <=> $da;
                }

                return strcmp($a['method'], $b['method']);
            }
        );

        return $out;
    }

    private function resolveFqcn(string $slugOrFqcn, \DoliDB $db): string
    {
        $trim = trim($slugOrFqcn);
        if ($trim === '') {
            throw new StateMachineExtractionError(
                'KNOT_SM_EXTRACTION_FAILED',
                'Missing Dolibarr class or object slug for the state machine.',
                'Empty slug or class name.',
                null,
                [],
                'Provide a mapped object slug such as facture, commande, or propal.',
                'warning'
            );
        }

        if (str_contains($trim, '\\')) {
            return $trim[0] === '\\' ? $trim : '\\' . $trim;
        }

        return $this->factory()->fqcnForSlug($trim, $db);
    }

    private function cacheKey(string $slugOrFqcn, string $fqcn): string
    {
        $trim = strtolower(trim($slugOrFqcn));
        if (!str_contains($slugOrFqcn, '\\')) {
            return 'slug_' . $trim;
        }

        return 'fqcn_' . substr(sha1($fqcn), 0, 16);
    }
}
