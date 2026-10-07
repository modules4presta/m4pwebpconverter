<?php

declare(strict_types=1);

/**
 * m4pwebpconverter
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.1.0 adds thumbnail conversion and front office WebP delivery.
 *
 * Existing installs keep front office rewriting DISABLED so an upgrade never
 * changes the rendered HTML without the merchant opting in; thumbnails are
 * enabled because they only add files and are required for WebP to be useful.
 */
function upgrade_module_1_1_0(M4pWebpConverter $module): bool
{
    $module->registerHook('actionOutputHTMLBefore');

    return Configuration::updateValue(M4pWebpConverter::CONFIG_THUMBS, 1)
        && Configuration::updateValue(M4pWebpConverter::CONFIG_SERVE_FRONT, 0);
}
