<?php

declare(strict_types=1);

/**
 * Minimal Dolibarr stubs for unit tests.
 *
 * Only the constants and helpers referenced from inside `class/` are stubbed
 * here. Anything pulling in `main.inc.php`, CMailFile, CommonObject, etc.
 * stays out of the unit suite (those are integration concerns).
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', __DIR__);
}

if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', 'llx_');
}

if (!function_exists('http_get_last_response_headers')) {
    /**
     * PHP 8.4+ runtime API; stubbed for PHPStan on PHP ≤8.3 CI runners.
     *
     * @return list<string>|false
     */
    function http_get_last_response_headers(): array|false
    {
        return false;
    }
}

if (!function_exists('getDolGlobalString')) {
    function getDolGlobalString(string $key, string $default = ''): string
    {
        $overrides = $GLOBALS['knot_test_globals_string'] ?? [];
        if (is_array($overrides) && array_key_exists($key, $overrides)) {
            return (string) $overrides[$key];
        }

        return $default;
    }
}

if (!function_exists('getDolGlobalInt')) {
    function getDolGlobalInt(string $key, int $default = 0): int
    {
        $overrides = $GLOBALS['knot_test_globals_int'] ?? [];
        if (is_array($overrides) && array_key_exists($key, $overrides)) {
            return (int) $overrides[$key];
        }
        return $default;
    }
}

if (!function_exists('dolibarr_set_const')) {
    function dolibarr_set_const(
        $db,
        string $name,
        string $value,
        string $type = 'chaine',
        int $visible = 0,
        string $note = '',
        int $entity = 1
    ): int {
        $GLOBALS['knot_test_const_writes'][] = compact('name', 'value', 'type', 'entity');
        return 1;
    }
}

if (!function_exists('dol_now')) {
    function dol_now(): int
    {
        return time();
    }
}

if (!function_exists('dol_print_date')) {
    function dol_print_date(int $timestamp, string $format = 'standard'): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('dol_include_once')) {
    function dol_include_once(string $relpath): void
    {
    }
}

if (!function_exists('dol_mkdir')) {
    function dol_mkdir(string $dir): int
    {
        if (is_dir($dir)) {
            return 1;
        }

        return mkdir($dir, 0755, true) ? 1 : 0;
    }
}

if (!class_exists('modKnot', false)) {
    class modKnot
    {
        public string $version = '2.13.7';
    }
}

if (!class_exists('Conf', false)) {
    class Conf
    {
        /** @var array<string, int|bool> */
        public array $modules = [];
    }
}

if (!class_exists('DoliDB', false)) {
    /**
     * Minimal DoliDB stub for unit tests.
     * Only declares the methods touched by the Knot codebase.
     */
    abstract class DoliDB
    {
        public function query(string $sql)
        {
            return false;
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public function fetch_object($resource): ?object
        {
            return null;
        }

        public function num_rows($resource): int
        {
            return 0;
        }

        public function idate(int $timestamp): string
        {
            return date('Y-m-d H:i:s', $timestamp);
        }

        public function last_insert_id(string $tableName): int
        {
            return 0;
        }

        public function lasterror(): string
        {
            return '';
        }

        public function affected_rows(mixed $resultset = null): int
        {
            return 0;
        }

        public function plimit(int $limit, int $offset = 0): string
        {
            if ($offset > 0) {
                return 'LIMIT ' . $offset . ', ' . $limit;
            }

            return 'LIMIT ' . $limit;
        }
    }
}

if (!class_exists('Translate', false)) {
    /**
     * Minimal Translate stub. Returns a sentinel `TR[<key>]` string so tests
     * can assert that translation went through (vs raw label fallback).
     */
    class Translate
    {
        public function trans(string $key): string
        {
            return 'TR[' . $key . ']';
        }
    }
}

/**
 * Fake Dolibarr business object exposing a representative `$fields` map for
 * SchemaBuilder tests. Mirrors the most common shapes seen across real
 * Dolibarr classes (FK, enum, html, datetime, conditional enabled).
 */
if (!class_exists('FakeFacture', false)) {
    class FakeFacture
    {
        public string $element = 'facture';

        /** @var array<string, mixed> */
        public array $fields = [
            'rowid' => ['type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -2, 'noteditable' => 1, 'notnull' => 1, 'position' => 1],
            'entity' => ['type' => 'integer', 'label' => 'Entity', 'default' => '1', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 5],
            'ref' => ['type' => 'varchar(30)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'notnull' => 1],
            'fk_soc' => ['type' => 'integer:Societe:societe/class/societe.class.php', 'label' => 'ThirdParty', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 20],
            'date_lim_reglement' => ['type' => 'date', 'label' => 'DateMaxPayment', 'enabled' => 1, 'visible' => 1, 'position' => 50],
            'datec' => ['type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'position' => 30],
            'note_public' => ['type' => 'html', 'label' => 'NotePublic', 'enabled' => 1, 'visible' => 1, 'position' => 100],
            'note_private' => ['type' => 'html', 'label' => 'NotePrivate', 'enabled' => 1, 'visible' => 1, 'position' => 105],
            'paye' => ['type' => 'tinyint(4)', 'label' => 'Paid', 'enabled' => 1, 'visible' => 1, 'position' => 200],
            'multicurrency_code' => ['type' => 'varchar(255)', 'label' => 'CurrencyCode', 'enabled' => "isModEnabled('multicurrency')", 'visible' => 1, 'position' => 60],
            'amount' => ['type' => 'price', 'label' => 'Amount', 'enabled' => 1, 'visible' => 1, 'position' => 70],
            'status' => ['type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 500, 'arrayofkeyval' => [0 => 'Draft', 1 => 'Validated', 2 => 'Paid']],
            'fk_user_creat' => ['type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -2, 'position' => 510],
            'description' => ['type' => 'text', 'label' => 'Description', 'enabled' => 1, 'visible' => 3, 'position' => 110],
            'updateonly_field' => ['type' => 'varchar(50)', 'label' => 'UpdateOnly', 'enabled' => 1, 'visible' => 3, 'position' => 600],
        ];

        public int $id = 0;

        public ?int $rowid = null;

        public string $ref = '';

        public int $socid = 0;

        public string $note_private = '';

        public string $error = '';

        /** @var mixed */
        public $statut = null;

        /** @var mixed */
        public $status = null;

        /** @var array<int, FakeFactureLigne>|array<int, object>|null */
        public ?array $lines = null;

        /** @var array<string, mixed>|null */
        public ?array $array_options = null;

        public function __construct(?\DoliDB $db = null) {}

        public function fetch(int $id, ?string $ref = '', ?object $dol_cache = null): int
        {
            $this->id = $id;
            $this->rowid = $id;
            $this->ref = 'F-' . $id;
            $this->socid = 771;
            $this->statut = 1;
            $this->status = null;

            $line = new FakeFactureLigne();
            $line->rowid = 101;
            $line->fk_facture = $id;
            $line->fk_product = 0;
            $line->desc = 'Line A';
            $line->qty = 2.5;
            $line->subprice = 10.0;
            $line->tva_tx = 20.0;
            $this->lines = [$line];

            return 1;
        }

        public function create(object $user): int
        {
            $this->id = 9001;
            $this->rowid = 9001;
            $this->ref = $this->ref !== '' ? $this->ref : 'F-NEW';

            return $this->id;
        }

        public function update(object $user): int
        {
            return 1;
        }

        public function delete(object $user): int
        {
            return 1;
        }

        public function validate(object $user): int
        {
            return 1;
        }

        public function setDraft(object $user): int
        {
            $this->statut = 0;

            return 1;
        }

        public function addline(
            string $desc = '',
            float $pu_ht = 0.0,
            float $qty = 1.0,
            float $txtva = 0.0,
            float $txlocaltax1 = 0.0,
            float $txlocaltax2 = 0.0,
            int $fk_product = 0,
            float $remise_percent = 0.0
        ): int {
            return 1;
        }

        public function update_note(string $note, string $suffix = ''): int
        {
            $this->note_private = $note;

            return 1;
        }

        public function generateDocument(
            string $model,
            string $lang,
            int $hidedetails = 0,
            int $hidedesc = 0,
            int $hideref = 0,
            ?object $user = null
        ): int {
            return 1;
        }
    }
}

if (!class_exists('FakeSociete', false)) {
    class FakeSociete
    {
        public string $element = 'societe';

        /** @var array<string, mixed> */
        public array $fields = [
            'rowid' => ['type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 1],
            'nom' => ['type' => 'varchar(128)', 'label' => 'Name', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 10],
            'email' => ['type' => 'varchar(128)', 'label' => 'Email', 'enabled' => 1, 'visible' => 1, 'position' => 20],
        ];

        public function __construct(?\DoliDB $db = null) {}
    }
}

if (!class_exists('FakePropal', false)) {
    class FakePropal
    {
        public int $id = 0;

        public function __construct(?\DoliDB $db = null) {}

        public function fetch(int $id): int
        {
            $this->id = $id;

            return 1;
        }

        public function createFromProposal(object $user): int
        {
            return 1;
        }
    }
}

if (!class_exists('FakeCommande', false)) {
    class FakeCommande
    {
        public int $id = 0;

        public function __construct(?\DoliDB $db = null) {}

        public function fetch(int $id): int
        {
            $this->id = $id;

            return 1;
        }

        public function createFromOrder(object $user): int
        {
            return 1;
        }

        public function classifyShipped(object $user): int
        {
            return 1;
        }
    }
}

if (!class_exists('FakeFactureLigne', false)) {
    class FakeFactureLigne
    {
        public string $element = 'factureligne';

        /** @var array<string, mixed> */
        public array $fields = [
            'rowid' => ['type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 1],
            'fk_facture' => ['type' => 'integer:Facture:compta/facture/class/facture.class.php', 'label' => 'Facture', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 5],
            'fk_product' => ['type' => 'integer:Product:product/class/product.class.php', 'label' => 'Product', 'enabled' => 1, 'visible' => 1, 'position' => 20],
            'desc' => ['type' => 'text', 'label' => 'Description', 'enabled' => 1, 'visible' => 1, 'position' => 30],
            'qty' => ['type' => 'double', 'label' => 'Qty', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 40],
            'subprice' => ['type' => 'price', 'label' => 'UnitPrice', 'enabled' => 1, 'visible' => 1, 'position' => 50],
            'tva_tx' => ['type' => 'double(6,3)', 'label' => 'VATRate', 'enabled' => 1, 'visible' => 1, 'position' => 60],
        ];

        public int $rowid = 0;

        public int $fk_facture = 0;

        public int $fk_product = 0;

        public string $desc = '';

        public float $qty = 0.0;

        public float $subprice = 0.0;

        public float $tva_tx = 0.0;

        public function __construct(?\DoliDB $db = null) {}
    }
}

/**
 * Fake user with a configurable rights map for ObjectAction permission tests.
 */
if (!class_exists('FakeUser', false)) {
    class FakeUser
    {
        /** @var array<string, array<string, bool>> */
        public array $rightsMap = [];

        /** @var array<string, array<string, array<string, bool>>> */
        public array $rightsTriple = [];

        public int $admin = 0;

        public int $id = 1;

        /** @see \User::$all_permissions_are_loaded — avoid repeated loadRights in tests */
        public bool $all_permissions_are_loaded = true;

        public function hasRight(string $module, string $perm1, string $perm2 = ''): bool
        {
            if ($perm2 !== '') {
                return $this->rightsTriple[$module][$perm1][$perm2] ?? false;
            }

            return $this->rightsMap[$module][$perm1] ?? false;
        }

        public function loadRights(): void
        {
            // No-op: unit tests pre-populate rights maps.
        }
    }
}
