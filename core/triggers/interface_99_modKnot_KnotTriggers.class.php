<?php
/* Copyright (C) 2026 Knot */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

/**
 * Dolibarr trigger bridge for Knot.
 */
class InterfaceKnotTriggers extends DolibarrTriggers
{
    /**
     * @var DoliDB
     */
    public $db;

    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = 'knot';
        $this->description = 'Knot trigger bridge';
        $this->version = class_exists('Knot\\Version') ? \Knot\Version::current() : '2.0.0';
        $this->picto = 'generic';
    }

    /**
     * Trigger action.
     *
     * @param string     $action Trigger code
     * @param CommonObject $object Trigger object
     * @param User       $user User
     * @param Translate  $langs Langs
     * @param Conf       $conf Conf
     * @return int
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (!isModEnabled('knot') || getDolGlobalString('KNOT_ENGINE_ENABLED') !== '1') {
            return 0;
        }

        dol_include_once('/knot/class/autoload.php');

        $workflows = new \Knot\Repository\WorkflowRepository($this->db);
        $executions = new \Knot\Repository\ExecutionRepository($this->db);
        $payload = [
            'action' => (string) $action,
            'objectType' => is_object($object) ? get_class($object) : 'unknown',
            'objectId' => isset($object->id) ? (int) $object->id : null,
            'objectRef' => isset($object->ref) ? (string) $object->ref : null,
            'userId' => (int) $user->id,
            'triggeredAt' => date(DATE_ATOM),
        ];

        foreach ($workflows->list((int) $conf->entity, ['active'], 500) as $workflow) {
            $full = $workflows->fetch((int) $workflow['id'], (int) $conf->entity);
            $definition = is_array($full['definition'] ?? null) ? $full['definition'] : [];
            if ($this->workflowListensToEvent($definition, (string) $action)) {
                $executions->enqueue((int) $workflow['id'], 'dolibarr_event', $payload, (int) $conf->entity);
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function workflowListensToEvent(array $definition, string $action): bool
    {
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        foreach ($nodes as $node) {
            if (!is_array($node) || (string) ($node['type'] ?? '') !== 'trigger.dolibarr_event') {
                continue;
            }
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $events = is_array($config['events'] ?? null) ? $config['events'] : [];
            if ($events === [] || in_array($action, $events, true)) {
                return true;
            }
        }
        return false;
    }
}
