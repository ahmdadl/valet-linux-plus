<?php

/**
 * Valet-managed Adminer bootstrap (do not edit — regenerated on install).
 * Customize plugins via plugins/enabled.php and files in plugins/.
 */
function adminer_object()
{
    include_once __DIR__ . '/plugins/plugin.php';

    foreach (glob(__DIR__ . '/plugins/*.php') ?: [] as $filename) {
        $base = basename($filename);
        if ($base === 'plugin.php' || $base === 'enabled.php') {
            continue;
        }
        include_once $filename;
    }

    $plugins = [];
    $enabled = __DIR__ . '/plugins/enabled.php';
    if (is_file($enabled)) {
        $list = include $enabled;
        if (is_array($list)) {
            $plugins = $list;
        }
    }

    return new AdminerPlugin($plugins);
}

include __DIR__ . '/adminer.php';
