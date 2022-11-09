<?php

/*
 * This file is part of the package ku_widget_content_info.
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 * Sep 2022 Nanna Ellegaard, University of Copenhagen.
 */

 /**
  * Icon registry
  */

defined('TYPO3_MODE') || die();

return [
    // icon identifier
    'ku-recent-content-widget-icon' => [
        'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        'source' => 'EXT:recent_content_widget/Resources/Public/Icons/Extension.svg',
    ],
];