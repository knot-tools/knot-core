<?php

declare(strict_types=1);

namespace Knot\Connectors\Dolibarr;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Engine\ExpressionResolver;
use Knot\Repository\AuditLogRepository;
use Knot\Security\SqlSafetyAnalyzer;
use RuntimeException;

#[Connector(id: 'dolibarr.sql_query', category: 'dolibarr')]
final class SqlQuery implements ConnectorInterface, DryRunAware
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'dolibarr.sql_query',
            'labelKey' => 'connectors.dolibarr.sql_query.label',
            'descriptionKey' => 'connectors.dolibarr.sql_query.description',
            'category' => 'dolibarr',
            'riskLevel' => 'critical',
            'reversible' => false,
            'sideEffects' => ['db'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.sql_query.fields.query.title',
                    'descriptionKey' => 'connectors.dolibarr.sql_query.fields.query.description',
                    'format' => 'textarea',
                    'x-position' => 0,
                ],
                'limit' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.dolibarr.sql_query.fields.limit.title',
                    'default' => 100,
                    'x-position' => 1,
                ],
            ],
        ];
    }

    public function getCredentialType(): ?string
    {
        return null;
    }

    public function getInputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function getOutputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function validate(array $config): array
    {
        $query = trim((string) ($config['query'] ?? ''));
        $analysis = (new SqlSafetyAnalyzer())->analyse($query);
        return [
            'valid' => $analysis['valid'],
            'errors' => $analysis['valid'] ? [] : [$analysis['reason'] ?? 'Invalid query.'],
        ];
    }

    public function execute(array $context): array
    {
        global $db, $user, $conf;

        if (empty($user->admin)) {
            throw new RuntimeException('Dolibarr SQL Query is admin-only.');
        }

        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $query = trim((string) $resolver->resolve((string) ($config['query'] ?? ''), $context));

        // Re-run the safety analysis AFTER expression resolution so an
        // attacker cannot smuggle dangerous keywords through `{{$json.x}}`.
        $analysis = (new SqlSafetyAnalyzer())->analyse($query);
        if (!$analysis['valid']) {
            throw new RuntimeException('SQL safety check failed: ' . ($analysis['reason'] ?? 'invalid query'));
        }
        if (!str_contains($query, 'llx_') && !str_contains($query, \MAIN_DB_PREFIX)) {
            throw new RuntimeException('Query must target Dolibarr tables.');
        }

        $limit = max(1, min(1000, (int) ($config['limit'] ?? 100)));
        if (!preg_match('/\blimit\b/i', $query)) {
            $query .= ' LIMIT ' . $limit;
        }

        (new AuditLogRepository($db))->record('dolibarr.sql_query', 'workflow', 0, (int) $user->id, [
            'query' => $query,
        ], (int) $conf->entity);

        $res = $db->query($query);
        if (!$res) {
            throw new RuntimeException('SQL query failed: ' . (string) $db->lasterror());
        }

        $rows = [];
        while ($obj = $db->fetch_object($res)) {
            $row = get_object_vars($obj);
            if ($row !== []) {
                $rows[] = array_change_key_case($row, CASE_LOWER);
            }
        }

        return ['rows' => $rows, 'count' => count($rows)];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    public function simulate(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $query = trim((string) $resolver->resolve((string) ($config['query'] ?? ''), $context));
        $analysis = (new SqlSafetyAnalyzer())->analyse($query);
        return [
            '_dryRun' => true,
            'query' => $query,
            'safetyValid' => $analysis['valid'],
            'safetyReason' => $analysis['valid'] ? null : ($analysis['reason'] ?? 'invalid query'),
            'note' => '[DRY-RUN] Query NOT executed. Safety analysis ran but no row was read or written.',
        ];
    }
}
