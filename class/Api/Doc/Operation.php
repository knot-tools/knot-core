<?php

declare(strict_types=1);

namespace Knot\Api\Doc;

use Attribute;

/**
 * PHP attribute used to annotate Knot API endpoints (`api/*.php`)
 * and feed the OpenAPI 3.1 generator (`composer run docs:openapi`).
 *
 * Usage in an endpoint script:
 *
 *   #[Operation(
 *     method: 'GET',
 *     path: '/api/workflows.php',
 *     summary: 'List workflows for the current entity',
 *     tags: ['workflows'],
 *     responseSchema: 'WorkflowList',
 *     authRequired: true,
 *     permission: 'knot->workflow->read',
 *   )]
 *   class WorkflowsListEndpoint {}
 *
 * Endpoints are flat PHP files in `api/`; the convention is to declare
 * a "marker class" in the same file (e.g. `class WorkflowsListEndpoint {}`)
 * carrying the attribute. The generator reads attributes via Reflection
 * and emits OpenAPI paths.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Operation
{
    /**
     * @param string $method     HTTP verb in upper case.
     * @param string $path       URL path relative to Dolibarr custom module root.
     * @param string $summary    One-liner used as OpenAPI `summary`.
     * @param array<int, string> $tags
     * @param string|null $description Long-form description (markdown allowed).
     * @param string|null $requestSchema  Reference to a schema declared in components.
     * @param string|null $responseSchema Reference to a schema declared in components.
     * @param bool $authRequired   When true, requires Dolibarr session + CSRF.
     * @param string|null $permission Dolibarr permission slug (`module->object->verb`).
     * @param array<int, int> $responseStatuses Documented status codes.
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $summary,
        public array $tags = [],
        public ?string $description = null,
        public ?string $requestSchema = null,
        public ?string $responseSchema = null,
        public bool $authRequired = true,
        public ?string $permission = null,
        public array $responseStatuses = [200, 400, 401, 403, 500],
    ) {
    }
}
