<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/onboarding.php',
    summary: 'First-run wizard precondition snapshot',
    tags: ['operations'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 401, 403],
)]
final class KnotApiDoc_OnboardingGet {}

#[Operation(
    method: 'POST',
    path: '/api/onboarding.php',
    summary: 'Mark wizard completed / reset / import starter templates',
    tags: ['operations'],
    responseSchema: 'Envelope',
    permission: 'admin',
    responseStatuses: [200, 201, 400, 401, 403, 405],
)]
final class KnotApiDoc_OnboardingPost {}
