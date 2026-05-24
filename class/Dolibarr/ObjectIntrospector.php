<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

use Throwable;

/**
 * Scans a Dolibarr installation for `CommonObject` subclasses and turns
 * them into Knot-friendly descriptors (slug → file → class → element →
 * supportsLines).
 *
 * The introspector is the V2.4 generalisation of the static MAP in
 * {@see ObjectFactory}. It enables Knot to discover automatically:
 *
 *   - core objects (Societe, Propal, Facture, Mo, BOM, Don, Loan…)
 *   - Dolistore third-party modules that drop their classes under
 *     `htdocs/custom/<module>/class/<x>.class.php`
 *   - extra objects shipped by Knot extensions (`modKnot*`)
 *
 * To stay safe (loading random PHP files at boot is dangerous), the
 * scan is split in two phases:
 *
 *   1. **Statically parse** each candidate file with a regex looking
 *      for `class Foo extends CommonObject`. We never `require_once`
 *      a file that doesn't match because the class definitions Knot
 *      can do anything in their global scope (e.g. emit headers).
 *   2. Files that match are queued; the caller decides whether to
 *      `dol_include_once` them right away (V2.4 schema describe) or
 *      to lazy-load when a workflow actually targets the slug.
 *
 * The result is cacheable on disk (`documents/knot/dolibarr_descriptors.json`)
 * via {@see DescriptorCache}, so the scan only runs when the cache
 * is missing or invalidated.
 */
final class ObjectIntrospector
{
    /**
     * Heuristic: paths under these top-level folders host a class we
     * can introspect. Anything else (lib/, install/, includes/, etc.)
     * is skipped to keep the scan fast and predictable.
     *
     * @var string[]
     */
    public const ALLOWED_MODULE_DIRS = [
        // Core CRM/business modules
        'societe', 'contact', 'comm', 'commande', 'compta', 'product',
        'projet', 'ticket', 'user', 'adherents', 'agenda', 'categories',
        'expensereport', 'holiday', 'fourn', 'supplier_proposal', 'don',
        'loan', 'bom', 'mrp', 'ecm', 'ftp', 'mailing', 'partnership',
        'recruitment', 'salaries', 'societebank', 'stock', 'supplier',
        'asset', 'opensurvey', 'reception', 'shipping', 'workstation',
        // Modules standards Dolibarr ajoutés en V2.4.1 après audit E2E :
        // beaucoup d'installations utilisent ces modules et ils étaient
        // silencieusement absents de l'introspection auto (ce qui forçait
        // un fallback manuel sur la MAP curated incomplet).
        'contrat',                // Contrats (très courant)
        'fichinter',              // Fiches d'intervention
        'expedition', 'delivery', // Expéditions / livraisons
        'hrm',                    // Ressources humaines (postes, évaluations)
        'accountancy',            // Comptabilité (écritures, journaux, comptes)
        'knowledgemanagement',    // Base de connaissances
        'eventorganization',      // Événements / inscriptions
        'bookcal',                // Réservations agenda
        'bookmarks',              // Favoris
        'multicurrency',          // Devises
        'resource',               // Ressources réservables
        'variants',               // Variantes produit
        'webportal',              // Portail externe
        'website',                // Sites Web Dolibarr
        'intracommreport',        // Déclarations DEB / TVA
        'datapolicy',             // RGPD
    ];

    /**
     * Path segment names (relative to each module root, excluding the root
     * folder itself) that must not be scanned — e.g. utility trees under
     * `product/cache/class/` that are not real business objects.
     * Used by {@see pathPassesSkipDirFilter()}.
     *
     * @var string[]
     */
    public const SKIP_DIRS = [
        'install', 'includes', 'theme', 'core', 'public', 'fckeditor',
        'imports', 'exports', 'cache', 'cron', 'doctemplates',
        'modulebuilder', 'recettes',
    ];

    /**
     * Class name patterns we explicitly do NOT want to surface as
     * Knot objects. They're "Common" base classes, line companion
     * classes, helpers and utilities.
     */
    public const CLASS_DENY_PATTERNS = [
        '/^Common[A-Z]/',            // CommonObject, CommonHookActions, ...
        '/Tools$/',                  // Helper utilities
        '/Helper$/',
        '/Utils?$/',
        '/Manager$/',
        // V2.4.1 : exclure les "line companion classes" qui n'ont pas de sens
        // comme objet workflow standalone (ContratLigne, OrderLine, etc. sont
        // toujours manipulées via leur parent et la propriété `line` du MAP).
        '/Ligne$/',                  // PropaleLigne, ContratLigne, ExpeditionLigne, ...
        '/Lignes?$/',                // FactureLignes (rare mais existe)
        '/Line$/',                   // OrderLine, SupplierInvoiceLine, ...
        '/Lines$/',                  // Variant: AccountLines, ...
        '/LineBatch$/',              // ExpeditionLineBatch, ReceptionLineBatch
    ];

    /**
     * Module folder names we never auto-scan because their files
     * include side effects on global scope or break parsing.
     *
     * @var string[]
     */
    public const FORCE_SKIP_MODULES = [
        'install', 'admin', 'public', 'core',
    ];

    private string $documentRoot;

    /** @var string[] */
    private array $extraScanRoots;

    /** @var array<int, string>|null */
    private ?array $blocklist = null;

    /**
     * @param array<int, string> $extraScanRoots
     */
    public function __construct(?string $documentRoot = null, array $extraScanRoots = [])
    {
        $this->documentRoot = $documentRoot
            ?? (defined('DOL_DOCUMENT_ROOT') ? (string) constant('DOL_DOCUMENT_ROOT') : '');
        $this->extraScanRoots = $extraScanRoots;
    }

    /**
     * Scan the install and return descriptor candidates. Each entry:
     *   - slug: kebab-case stable id
     *   - file: relative file to dol_include_once
     *   - class: PHP class name
     *   - module: best-effort module folder
     *   - source: 'core' | 'custom' | 'extension'
     *   - parent: parent class detected (for compat checks)
     *
     * The scan never loads PHP files; it relies on regex parsing.
     *
     * @return array<int, array{
     *     slug: string,
     *     file: string,
     *     class: string,
     *     module: string,
     *     source: string,
     *     parent: ?string,
     *     supportsLines: bool,
     * }>
     */
    public function scan(): array
    {
        if ($this->documentRoot === '' || !is_dir($this->documentRoot)) {
            return [];
        }

        $blocked = $this->blocklist();
        $found = [];
        $seen = [];

        // Build the module roots we want to walk: htdocs/<allowed>/ +
        // htdocs/custom/<*>/ + any extra root provided by the caller.
        $moduleRoots = [];
        foreach (self::ALLOWED_MODULE_DIRS as $dir) {
            $full = $this->documentRoot . '/' . $dir;
            if (is_dir($full)) {
                $moduleRoots[] = ['path' => $full, 'source' => 'core', 'module' => $dir];
            }
        }
        $customRoot = $this->documentRoot . '/custom';
        if (is_dir($customRoot)) {
            foreach (scandir($customRoot) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (in_array($entry, self::FORCE_SKIP_MODULES, true)) {
                    continue;
                }
                $full = $customRoot . '/' . $entry;
                if (!is_dir($full)) {
                    continue;
                }
                // Knot itself + Knot extensions: skip the connector classes
                // (they're not Dolibarr CommonObject subclasses anyway).
                if (strpos($entry, 'modKnot') === 0 || $entry === 'knot') {
                    continue;
                }
                $moduleRoots[] = ['path' => $full, 'source' => 'custom', 'module' => $entry];
            }
        }
        foreach ($this->extraScanRoots as $root) {
            if (is_dir($root)) {
                $moduleRoots[] = ['path' => $root, 'source' => 'extension', 'module' => basename($root)];
            }
        }

        foreach ($moduleRoots as $root) {
            foreach ($this->findClassFiles($root['path']) as $absolute) {
                if (!$this->pathPassesSkipDirFilter($absolute, $root['path'])) {
                    continue;
                }
                $relative = substr($absolute, strlen($this->documentRoot));
                if ($relative === '' || $relative[0] !== '/') {
                    $relative = '/' . ltrim($relative, '/');
                }
                $candidates = $this->parseClasses($absolute);
                foreach ($candidates as $candidate) {
                    $class = $candidate['class'];
                    if (isset($seen[$class])) {
                        continue;
                    }
                    $seen[$class] = true;
                    if (in_array($class, $blocked, true)) {
                        continue;
                    }
                    if ($this->isClassDenied($class)) {
                        continue;
                    }
                    if (!$this->isCommonObjectSubclass($candidate['parent'])) {
                        continue;
                    }

                    $slug = $this->slugFromClass($class);
                    if ($slug === '') {
                        continue;
                    }

                    $found[] = [
                        'slug' => $slug,
                        'file' => $relative,
                        'class' => $class,
                        'module' => $root['module'],
                        'source' => $root['source'],
                        'parent' => $candidate['parent'],
                        'supportsLines' => $this->guessSupportsLines($class),
                    ];
                }
            }
        }

        usort($found, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $found;
    }

    /**
     * Return false when `$absolute`'s relative path under the module root
     * contains a segment listed in {@see SKIP_DIRS}.
     */
    private function pathPassesSkipDirFilter(string $absolute, string $moduleRootPath): bool
    {
        $abs = str_replace('\\', '/', $absolute);
        $root = str_replace('\\', '/', rtrim($moduleRootPath, '/\\'));
        if ($root === '') {
            return true;
        }
        $prefixLen = strlen($root);
        if (strlen($abs) < $prefixLen || substr($abs, 0, $prefixLen) !== $root) {
            return true;
        }
        $rel = substr($abs, $prefixLen);
        $rel = trim($rel, '/');
        if ($rel === '') {
            return true;
        }

        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || str_contains($segment, '.')) {
                continue;
            }
            foreach (self::SKIP_DIRS as $skip) {
                if (strcasecmp($segment, $skip) === 0) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Iterate `<root>/class/*.class.php` and `<root>/{class,module}/class/*.class.php`.
     *
     * @return iterable<string>
     */
    private function findClassFiles(string $rootPath): iterable
    {
        $patterns = [
            $rootPath . '/class/*.class.php',
            $rootPath . '/*/class/*.class.php',
            $rootPath . '/*/*/class/*.class.php',
        ];
        $seen = [];
        foreach ($patterns as $pattern) {
            $files = glob($pattern) ?: [];
            foreach ($files as $file) {
                if (isset($seen[$file])) {
                    continue;
                }
                $seen[$file] = true;
                yield $file;
            }
        }
    }

    /**
     * Static parse: find `class Foo extends Bar` declarations in the
     * file. Returns one entry per declaration found.
     *
     * @return array<int, array{class: string, parent: ?string}>
     */
    private function parseClasses(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return [];
        }
        // Trim large files to first 64 kB — the class declaration is
        // always near the top and we want to avoid spending CPU on the
        // body for an introspection scan.
        if (strlen($contents) > 65536) {
            $contents = substr($contents, 0, 65536);
        }
        $matches = [];
        if (
            !preg_match_all(
                '/\bclass\s+([A-Z][A-Za-z0-9_]*)\s+extends\s+([A-Z][A-Za-z0-9_\\\\]*)/m',
                $contents,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            return [];
        }
        $results = [];
        foreach ($matches as $m) {
            $results[] = ['class' => $m[1], 'parent' => $m[2]];
        }
        return $results;
    }

    /**
     * Decide whether the parsed parent class is a CommonObject (or
     * one of its known descendants used as a base in Dolibarr).
     */
    private function isCommonObjectSubclass(?string $parent): bool
    {
        if ($parent === null || $parent === '') {
            return false;
        }
        $normalised = ltrim($parent, '\\');
        return $normalised === 'CommonObject'
            || $normalised === 'CommonObjectLine'  // tolerated, surfaces line classes too
            || $normalised === 'CommonInvoice'
            || $normalised === 'CommonOrder'
            || $normalised === 'CommonProposal'
            || $normalised === 'CommonStockMovement'
            || $normalised === 'CommonObjectGenericClass';
    }

    private function isClassDenied(string $class): bool
    {
        foreach (self::CLASS_DENY_PATTERNS as $pattern) {
            if (preg_match($pattern, $class) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert a class name to a stable slug. We aim for backward
     * compatibility with the static MAP slugs whenever possible
     * (Societe → thirdparty, Facture → facture, Adherent → member).
     */
    public function slugFromClass(string $class): string
    {
        $aliases = [
            'Societe' => 'thirdparty',
            'Adherent' => 'member',
            'Mo' => 'mo',
            'BOM' => 'bom',
            'ActionComm' => 'actioncomm',
            'MouvementStock' => 'stockmove',
            'Account' => 'bankaccount',
            'Holiday' => 'holiday',
            'ExpenseReport' => 'expense',
            'FactureFournisseur' => 'facturefourn',
            'CommandeFournisseur' => 'commandefourn',
            'SupplierProposal' => 'propalfourn',
        ];
        if (isset($aliases[$class])) {
            return $aliases[$class];
        }
        $lower = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $class) ?: '');
        return $lower;
    }

    /**
     * Heuristic: any class whose corresponding line class exists in
     * the same file (parsed earlier) supports lines. To keep the API
     * simple for V2.4 we detect the common naming patterns.
     */
    private function guessSupportsLines(string $class): bool
    {
        // Known line-bearing object suffixes.
        $hints = ['Facture', 'Propal', 'Commande', 'SupplierProposal',
                  'CommandeFournisseur', 'FactureFournisseur', 'Mo', 'BOM',
                  'Reception', 'Expedition', 'Asset'];
        foreach ($hints as $h) {
            if ($class === $h) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read the user-controlled blocklist (admin can hide classes that
     * leak through the heuristics).
     *
     * @return string[]
     */
    private function blocklist(): array
    {
        if ($this->blocklist !== null) {
            return $this->blocklist;
        }
        $list = [];
        if (function_exists('getDolGlobalString')) {
            $raw = (string) getDolGlobalString('KNOT_INTROSPECTION_BLOCKLIST', '');
            if ($raw !== '') {
                $list = array_values(array_filter(array_map('trim', explode(',', $raw))));
            }
        }
        return $this->blocklist = $list;
    }
}
