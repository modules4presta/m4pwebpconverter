<?php

declare(strict_types=1);

/**
 * LICENCE
 *
 * ALL RIGHTS RESERVED.
 * YOU ARE NOT ALLOWED TO COPY/EDIT/SHARE/WHATEVER.
 *
 * IN CASE OF ANY PROBLEM CONTACT AUTHOR.
 *
 *  @author    Jan Kołodziej (contact@modules4presta.io)
 *  @copyright modules4presta.io
 *  @license   ALL RIGHTS RESERVED
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
