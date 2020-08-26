<?php
/**
 * Copyright (c) UNA, Inc - https://una.io
 * MIT License - https://opensource.org/licenses/MIT
 */

$aConfig = array(
    /**
     * Main Section.
     */
    'title' => 'Plyr',
    'version_from' => '11.0.0',
    'version_to' => '11.0.1',
    'vendor' => 'BoonEx',

    'compatible_with' => array(
        '11.0.3'
    ),

    /**
     * 'home_dir' and 'home_uri' - should be unique. Don't use spaces in 'home_uri' and the other special chars.
     */
    'home_dir' => 'boonex/plyr/updates/update_11.0.0_11.0.1/',
    'home_uri' => 'plyr_update_1100_1101',

    'module_dir' => 'boonex/plyr/',
    'module_uri' => 'plyr',

    'db_prefix' => 'bx_plyr_',
    'class_prefix' => 'BxPlyr',

    /**
     * Installation/Uninstallation Section.
     */
    'install' => array(
        'execute_sql' => 0,
        'update_files' => 1,
        'update_languages' => 0,
        'clear_db_cache' => 0,
    ),

    /**
     * Category for language keys.
     */
    'language_category' => 'Plyr',

    /**
     * Files Section
     */
    'delete_files' => array(),
);
