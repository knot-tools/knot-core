<?php
/* Copyright (C) 2026 Knot */

declare(strict_types=1);

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Dolibarr module descriptor for Knot.
 */
class modKnot extends DolibarrModules
{
    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;
        $this->numero = 610000;
        $this->rights_class = 'knot';
        $this->family = 'crm';
        $this->module_position = 500;
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'KnotDesc';
        $this->descriptionlong = 'KnotDescLong';
        $this->version = '2.13.9';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        // Dolibarr resolves picto "basename@knot" to custom/knot/img/basename.png (see dev docs).
        $this->picto = 'knot@knot';

        $this->module_parts = [
            'triggers' => 1,
            'css' => [
                '/knot/css/knot.css',
            ],
            'js' => [
                '/knot/js/knot-app.js',
            ],
        ];

        $this->dirs = ['/knot/temp'];
        $this->config_page_url = ['setup.php@knot'];
        $this->langfiles = ['knot@knot'];
        $this->phpmin = [8, 1];
        $this->need_dolibarr_version = [20, 0, -3];
        $this->depends = [];
        $this->requiredby = [];
        $this->conflictwith = [];
        $this->hidden = false;
        $this->always_enabled = false;

        $this->const = [
            [
                'KNOT_SETUP_COMPLETED',
                'chaine',
                '0',
                'Setup wizard completion flag',
                0,
                'current',
                1,
            ],
            [
                'KNOT_ENGINE_ENABLED',
                'chaine',
                '0',
                'Global engine kill switch',
                0,
                'current',
                1,
            ],
            [
                'KNOT_FIRSTRUN_COMPLETED',
                'chaine',
                '0',
                'First-run onboarding wizard completion flag (0 = wizard auto-shows on dashboard)',
                0,
                'current',
                1,
            ],
        ];

        $this->tabs = [];
        $this->dictionaries = [];
        $this->boxes = [];
        $this->cronjobs = [
            [
                'label' => 'KnotCronWorker',
                'jobtype' => 'method',
                'class' => '/knot/class/Engine/CronWorker.php',
                'objectname' => 'Knot\\Engine\\CronWorker',
                'method' => 'run',
                'parameters' => '',
                'comment' => 'Process queued Knot workflow executions',
                'frequency' => 1,
                'unitfrequency' => 60,
                'status' => 0,
                'test' => 'isModEnabled("knot") && getDolGlobalString("KNOT_ENGINE_ENABLED")',
            ],
            [
                'label' => 'KnotRetentionWorker',
                'jobtype' => 'method',
                'class' => '/knot/class/Engine/RetentionWorker.php',
                'objectname' => 'Knot\\Engine\\RetentionWorker',
                'method' => 'run',
                'parameters' => '',
                'comment' => 'Purge old execution / log / audit rows (RGPD retention)',
                'frequency' => 6,
                'unitfrequency' => 3600,
                'status' => 0,
                'test' => 'isModEnabled("knot")',
            ],
            [
                'label' => 'KnotHealthWorker',
                'jobtype' => 'method',
                'class' => '/knot/class/Engine/HealthWorker.php',
                'objectname' => 'Knot\\Engine\\HealthWorker',
                'method' => 'run',
                'parameters' => '',
                'comment' => 'Detect stale running executions and recompute health metrics',
                'frequency' => 5,
                'unitfrequency' => 60,
                'status' => 0,
                'test' => 'isModEnabled("knot")',
            ],
        ];

        $this->rights = [];
        $r = 0;
        $this->rights[$r][0] = 610001;
        $this->rights[$r][1] = 'KnotWorkflowRead';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'workflow';
        $this->rights[$r][5] = 'read';
        $r++;
        $this->rights[$r][0] = 610002;
        $this->rights[$r][1] = 'KnotWorkflowWrite';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'workflow';
        $this->rights[$r][5] = 'write';
        $r++;
        $this->rights[$r][0] = 610003;
        $this->rights[$r][1] = 'KnotWorkflowExecute';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'workflow';
        $this->rights[$r][5] = 'execute';
        $r++;
        $this->rights[$r][0] = 610004;
        $this->rights[$r][1] = 'KnotCredentialManage';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'credential';
        $this->rights[$r][5] = 'manage';
        $r++;
        $this->rights[$r][0] = 610005;
        $this->rights[$r][1] = 'KnotAdminConfigure';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'admin';
        $this->rights[$r][5] = 'configure';

        // Keep MENU_ENTRY_COUNT in Knot\Module\ModuleExpectations in sync with this array length.
        $this->menu = [];
        $r = 0;
        $this->menu[$r++] = [
            'fk_menu' => '',
            'type' => 'top',
            'titre' => 'KnotBrandName',
            'mainmenu' => 'knot',
            'leftmenu' => '',
            'url' => '/knot/workflows/preview.php?mode=dashboard',
            'langs' => 'knot@knot',
            'position' => 100,
            'enabled' => 'isModEnabled("knot")',
            'perms' => '$user->hasRight("knot", "workflow", "read") || $user->hasRight("knot", "admin", "configure")',
            'target' => '',
            'user' => 2,
        ];
        $this->menu[$r++] = [
            'fk_menu' => 'fk_mainmenu=knot',
            'type' => 'left',
            'titre' => 'KnotDashboard',
            'mainmenu' => 'knot',
            'leftmenu' => 'knot_dashboard',
            'url' => '/knot/workflows/preview.php?mode=dashboard',
            'langs' => 'knot@knot',
            'position' => 100,
            'enabled' => 'isModEnabled("knot")',
            'perms' => '$user->hasRight("knot", "workflow", "read")',
            'target' => '',
            'user' => 2,
        ];
        $this->menu[$r++] = [
            'fk_menu' => 'fk_mainmenu=knot',
            'type' => 'left',
            'titre' => 'KnotObservability',
            'mainmenu' => 'knot',
            'leftmenu' => 'knot_observability',
            'url' => '/knot/workflows/preview.php?mode=observability',
            'langs' => 'knot@knot',
            'position' => 105,
            'enabled' => 'isModEnabled("knot")',
            'perms' => '$user->hasRight("knot", "workflow", "read")',
            'target' => '',
            'user' => 2,
        ];
        $this->menu[$r++] = [
            'fk_menu' => 'fk_mainmenu=knot',
            'type' => 'left',
            'titre' => 'KnotWorkflows',
            'mainmenu' => 'knot',
            'leftmenu' => 'knot_workflows',
            'url' => '/knot/workflows/preview.php?mode=workflows',
            'langs' => 'knot@knot',
            'position' => 110,
            'enabled' => 'isModEnabled("knot")',
            'perms' => '$user->hasRight("knot", "workflow", "read")',
            'target' => '',
            'user' => 2,
        ];
        $this->menu[$r++] = [
            'fk_menu' => 'fk_mainmenu=knot,fk_leftmenu=knot_workflows',
            'type' => 'left',
            'titre' => 'KnotNewWorkflow',
            'mainmenu' => 'knot',
            'leftmenu' => 'knot_workflow_new',
            'url' => '/knot/workflows/preview.php?mode=editor',
            'langs' => 'knot@knot',
            'position' => 111,
            'enabled' => 'isModEnabled("knot")',
            'perms' => '$user->hasRight("knot", "workflow", "write")',
            'target' => '',
            'user' => 2,
        ];
        $this->menu[$r++] = [
            'fk_menu' => 'fk_mainmenu=knot',
            'type' => 'left',
            'titre' => 'KnotExecutions',
            'mainmenu' => 'knot',
            'leftmenu' => 'knot_executions',
            'url' => '/knot/workflows/preview.php?mode=executions',
            'langs' => 'knot@knot',
            'position' => 120,
            'enabled' => 'isModEnabled("knot")',
            'perms' => '$user->hasRight("knot", "workflow", "read")',
            'target' => '',
            'user' => 2,
        ];
        $this->menu[$r++] = [
            'fk_menu' => 'fk_mainmenu=knot',
            'type' => 'left',
            'titre' => 'KnotSetup',
            'mainmenu' => 'knot',
            'leftmenu' => 'knot_setup',
            'url' => '/knot/admin/setup.php?admin=1',
            'langs' => 'knot@knot',
            'position' => 200,
            'enabled' => 'isModEnabled("knot")',
            'perms' => '$user->hasRight("knot", "admin", "configure")',
            'target' => '',
            'user' => 2,
        ];
    }

    /**
     * Initialize module.
     *
     * @param string $options Options
     * @return int
     */
    public function init($options = '')
    {
        $sql = [];
        $result = $this->_load_tables('/knot/sql/');

        return $this->_init($sql, $options) && $result >= 0 ? 1 : -1;
    }

    /**
     * Remove module.
     *
     * @param string $options Options
     * @return int
     */
    public function remove($options = '')
    {
        $sql = [];

        return $this->_remove($sql, $options);
    }
}
