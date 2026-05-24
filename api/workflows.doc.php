<?php

declare(strict_types=1);

/**
 * OpenAPI marker classes for `api/workflows.php`. Loaded only by the
 * OpenAPI generator (`composer run docs:openapi`); never required by
 * the runtime endpoint itself.
 */

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/workflows.php',
    summary: 'List workflows for the current entity',
    tags: ['workflows'],
    responseSchema: 'WorkflowList',
    permission: 'knot->workflow->read',
)]
final class KnotApiDoc_WorkflowsList
{
}

#[Operation(
    method: 'POST',
    path: '/api/workflows.php',
    summary: 'Create a new workflow',
    tags: ['workflows'],
    requestSchema: 'Workflow',
    responseSchema: 'Envelope',
    permission: 'knot->workflow->write',
    responseStatuses: [201, 400, 401, 403, 422, 500],
)]
final class KnotApiDoc_WorkflowsCreate
{
}

#[Operation(
    method: 'PATCH',
    path: '/api/workflows.php',
    summary: 'Update an existing workflow',
    tags: ['workflows'],
    requestSchema: 'Workflow',
    responseSchema: 'Envelope',
    permission: 'knot->workflow->write',
    responseStatuses: [200, 400, 401, 403, 404, 409, 500],
)]
final class KnotApiDoc_WorkflowsUpdate
{
}

#[Operation(
    method: 'DELETE',
    path: '/api/workflows.php',
    summary: 'Delete a workflow (soft delete via `is_active = 0`)',
    tags: ['workflows'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->write',
    responseStatuses: [200, 401, 403, 404, 500],
)]
final class KnotApiDoc_WorkflowsDelete
{
}
