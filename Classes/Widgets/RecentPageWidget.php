<?php

declare(strict_types=1);

/*
 * This file is part of the recent_content_widget extension for TYPO3 CMS.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Epixskill\RecentContentWidget\Widgets;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;

class RecentPageWidget implements WidgetInterface, AdditionalCssInterface
{
    /**
     * @var WidgetConfigurationInterface
     */
    private $configuration;

    /**
     * @var StandaloneView
     */
    private $view;

    /**
     * @var PageRepository
     */
    private $pageRepository;

    /**
     * @var array
     */
    private $options;

    public function __construct(
        WidgetConfigurationInterface $configuration,
        StandaloneView $view,
        PageRepository $pageRepository,
        array $options = []
    ) {
        $this->configuration = $configuration;
        $this->view = $view;
        $this->pageRepository = $pageRepository;
        $this->options = $options;
    }

    public function renderWidgetContent(): string
    {
        $this->view->setTemplate('RecentPageWidget');
        $this->view->assignMultiple([
            'options' => $this->options,
            'configuration' => $this->configuration,
            'pages' => $this->getRecentPages($this->options['limit']),
        ]);
        return $this->view->render();
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getCssFiles(): array
    {
        if ($this->getTypo3MainVersion() === 11) {
            return [
                'EXT:recent_content_widget/Resources/Public/Css/recent-content-widget.css',
                'EXT:recent_content_widget/Resources/Public/Css/recent-content-widget-11.css',
            ];
        }
        return [
            'EXT:recent_content_widget/Resources/Public/Css/recent-content-widget.css',
        ];
    }

    protected function getRecentPagesBatch(int $limit = 1000, int $offset = 0): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->createQueryBuilder();
        $queryBuilder
            ->getRestrictions()
            ->removeByType(HiddenRestriction::class)
            ->removeByType(StartTimeRestriction::class)
            ->removeByType(EndTimeRestriction::class);
        $result = $queryBuilder
            ->select('*')
            ->from('pages')
            ->orderBy('ku_lastpageupdates_timestamp', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->execute()
            ->fetchAll();
        return $result;
    }

    protected function getRecentPages(int $limit): array
    {
        $elements = [];
        $batchLimit = 1000;
        $offset = 0;
        do {
            $results = $this->getRecentPagesBatch($batchLimit, $offset);
            for ($i = 0; $i < count($results); $i++) {
                if ($GLOBALS['BE_USER']->doesUserHaveAccess($this->pageRepository->getPage($results[$i]['uid']), 16)) {
                    if ($GLOBALS['BE_USER']->recordEditAccessInternals('pages', $results[$i]['uid'])) {
                        $results[$i]['isEditable'] = 1;
                    }
                    if (time() - $results[$i]['crdate'] <= 60 * 60 * 24 * 2) {
                        $results[$i]['badges']['new'] = 1;
                    }
                    if (time() < $results[$i]['starttime'] && $results[$i]['hidden'] === 0) {
                        $results[$i]['badges']['visibleInFuture'] = 1;
                    }
                    if (time() > $results[$i]['endtime'] && $results[$i]['endtime'] > 0 && $results[$i]['hidden'] === 0) {
                        $results[$i]['badges']['visibleInPast'] = 1;
                    }
                    if (empty($results[$i]['ku_lastpageupdates_timestamp']) || $results[$i]['ku_lastpageupdates_timestamp'] === 0) {
                        $results[$i]['ku_lastpageupdates_timestamp'] = $results[$i]['tstamp'];
                    } else {
                        $results[$i]['ku_lastpageupdates_timestamp'];
                    }

                    $results[$i]['doktypeLabel'] = $this->getDoktypeTranslationString((int)$results[$i]['doktype']);
                    if (substr($results[$i]['doktypeLabel'], 0, 4) === 'LLL:') {
                        $results[$i]['doktypeLabelIsKey'] = true;
                    }

                    if ($results[$i]['cruser_id']) {
                        $results[$i]['ku_creator'] = $this->getAuthorRealName($results[$i]['cruser_id']);
                    }

                    if (count($elements) < $limit) {
                        $elements[] = $results[$i];
                    }
                }
            }
            $offset += $batchLimit;
        } while (count($elements) < $limit && count($results) === $batchLimit);
        
        return $elements;
    }

    protected function getDoktypeTranslationString(int $key): ?string
    {
        foreach ($GLOBALS['TCA']['pages']['columns']['doktype']['config']['items'] as $item) {
            if ((int)$item[1] === $key) {
                return $item[0];
            }
        }

        return null;
    }

    protected function getAuthorRealName(int $authorUid): ?string
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');

        $result = $queryBuilder
        ->select('realName')
        ->from('be_users')
        ->where($queryBuilder->expr()->eq(
            'uid',
            $queryBuilder->createNamedParameter($authorUid, \PDO::PARAM_STR)
        ))
        ->execute() // Change to executeQuery() in TYPO3 v.12
        ->fetch();

        $name = $result['realName'];

        if ($result) {
            return $name;
        }
    }

    protected function getTypo3MainVersion(): int
    {
        $versionNumberUtility = GeneralUtility::makeInstance(VersionNumberUtility::class);
        $versionArray = $versionNumberUtility->convertVersionStringToArray($versionNumberUtility->getCurrentTypo3Version());
        return $versionArray['version_main'];
    }
}
