<?php

declare(strict_types=1);

namespace Knot\Api\Doc;

use ReflectionClass;
use RuntimeException;

/**
 * Generates an OpenAPI 3.1 document by scanning the `api/` folder for
 * PHP files that declare an {@see Operation} attribute on a marker class.
 *
 * Trade-offs:
 *  - The endpoints are flat PHP scripts, so we can't reflect a class
 *    "of the endpoint" directly; instead each endpoint declares a
 *    marker class at the top of the file (`class XEndpoint {}`).
 *  - The generator only reads PHP attributes — it does not parse
 *    `JsonResponse::success(...)` calls. Endpoint authors must keep
 *    `requestSchema` / `responseSchema` references in sync manually.
 *  - Schemas are loaded from `class/Api/Doc/Schemas/*.json`, one file
 *    per schema name, so authors can add new ones without editing PHP.
 */
final class OpenApiGenerator
{
    private string $apiDir;
    private string $schemasDir;

    public function __construct(?string $apiDir = null, ?string $schemasDir = null)
    {
        $this->apiDir = $apiDir ?? dirname(__DIR__, 3) . '/api';
        $this->schemasDir = $schemasDir ?? __DIR__ . '/Schemas';
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $version = '2.5.0'): array
    {
        $paths = [];
        foreach ($this->discoverOperations() as $op) {
            $pathItem = $paths[$op->path] ?? [];
            $pathItem[strtolower($op->method)] = $this->buildOperation($op);
            $paths[$op->path] = $pathItem;
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Knot internal API',
                'version' => $version,
                'description' => "Internal REST API consumed by the Knot frontend (Vue 3).\n"
                    . "All endpoints require an authenticated Dolibarr session and a CSRF token "
                    . "on mutating verbs unless explicitly stated.\n",
                'license' => ['name' => 'GPL-3.0-or-later'],
            ],
            'servers' => [
                ['url' => 'https://{instance}/custom/knot', 'variables' => [
                    'instance' => ['default' => 'crm.example.com'],
                ]],
            ],
            'paths' => $paths,
            'components' => [
                'schemas' => $this->loadSchemas(),
                'securitySchemes' => [
                    'dolibarrSession' => [
                        'type' => 'apiKey',
                        'name' => 'DOLSESSID',
                        'in' => 'cookie',
                    ],
                    'csrfToken' => [
                        'type' => 'apiKey',
                        'name' => 'X-Knot-Csrf',
                        'in' => 'header',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOperation(Operation $op): array
    {
        $out = [
            'summary' => $op->summary,
            'tags' => $op->tags,
            'responses' => [],
        ];
        if ($op->description !== null) {
            $out['description'] = $op->description;
        }
        if ($op->requestSchema !== null) {
            $out['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $op->requestSchema],
                    ],
                ],
            ];
        }
        foreach ($op->responseStatuses as $code) {
            $resp = ['description' => $this->statusLabel($code)];
            if ($code === 200 && $op->responseSchema !== null) {
                $resp['content'] = [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $op->responseSchema],
                    ],
                ];
            }
            $out['responses'][(string) $code] = $resp;
        }
        if ($op->authRequired) {
            $out['security'] = [['dolibarrSession' => [], 'csrfToken' => []]];
        }
        if ($op->permission !== null) {
            $out['x-knot-permission'] = $op->permission;
        }
        return $out;
    }

    /**
     * @return array<int, Operation>
     */
    private function discoverOperations(): array
    {
        $ops = [];
        if (!is_dir($this->apiDir)) {
            return $ops;
        }
        $knownClasses = get_declared_classes();
        foreach (glob($this->apiDir . '/*.php') ?: [] as $file) {
            // Marker classes live in a separate `<endpoint>.doc.php` file
            // so the production endpoint is not loaded just to read its
            // attributes (which would boot Dolibarr at generator time).
            $docFile = preg_replace('/\.php$/', '.doc.php', $file);
            if (!is_file($docFile)) {
                continue;
            }
            require_once $docFile;
        }
        // After requiring every doc file, scan only the classes those files
        // introduced for the Operation attribute.
        foreach (array_diff(get_declared_classes(), $knownClasses) as $cls) {
            $refl = new ReflectionClass($cls);
            foreach ($refl->getAttributes(Operation::class) as $attr) {
                $ops[] = $attr->newInstance();
            }
        }
        return $ops;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchemas(): array
    {
        $out = [];
        if (!is_dir($this->schemasDir)) {
            return $out;
        }
        foreach (glob($this->schemasDir . '/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            $raw = file_get_contents($file);
            if ($raw === false) {
                throw new RuntimeException("Cannot read schema file: $file");
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException("Schema file is not valid JSON: $file");
            }
            $out[$name] = $decoded;
        }
        return $out;
    }

    private function statusLabel(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No content',
            400 => 'Bad request — payload invalid or schema mismatch',
            401 => 'Unauthorised — Dolibarr session missing or expired',
            403 => 'Forbidden — current user lacks required permission',
            404 => 'Not found',
            409 => 'Conflict',
            422 => 'Unprocessable entity',
            500 => 'Internal server error',
            502 => 'Bad gateway — upstream dependency failed',
            default => 'HTTP ' . $code,
        };
    }
}
