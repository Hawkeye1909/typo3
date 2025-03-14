<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
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

namespace TYPO3\CMS\Extbase\Configuration;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Utility\FrontendSimulatorUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * A general purpose configuration manager used in backend mode.
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
class BackendConfigurationManager implements SingletonInterface
{
    /**
     * Default backend storage PID
     */
    public const DEFAULT_BACKEND_STORAGE_PID = 0;

    /**
     * Storage of the raw TypoScript configuration
     */
    protected array $configuration = [];

    protected ?ContentObjectRenderer $contentObject = null;

    /**
     * Name of the extension this Configuration Manager instance belongs to
     */
    protected ?string $extensionName = null;

    /**
     * Name of the plugin this Configuration Manager instance belongs to
     */
    protected ?string $pluginName = null;

    /**
     * 1st level configuration cache
     */
    protected array $configurationCache = [];

    protected array $typoScriptSetupCache = [];

    /**
     * Stores the current page ID
     */
    protected ?int $currentPageId = null;

    public function __construct(
        private readonly TypoScriptService $typoScriptService
    ) {
    }

    /**
     * @param ContentObjectRenderer $contentObject
     * @todo: See note on getContentObject() below.
     */
    public function setContentObject(ContentObjectRenderer $contentObject): void
    {
        $this->contentObject = $contentObject;
    }

    /**
     * @return ContentObjectRenderer
     * @todo: This dependency to ContentObjectRenderer on a singleton object is unfortunate:
     *      The current instance is set through USER cObj and extbase Bootstrap, its null in Backend.
     *      This getter is misused to retrieve current ContentObjectRenderer state by some extensions (eg. ext:form).
     *      This dependency should be removed altogether.
     *      Although the current implementation *always* returns an instance of ContentObjectRenderer, we do not want to
     *      hard-expect consuming classes on that, since this methods needs to be dropped anyways, so potential null return is kept.
     */
    public function getContentObject(): ?ContentObjectRenderer
    {
        if ($this->contentObject instanceof ContentObjectRenderer) {
            return $this->contentObject;
        }
        $this->contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        return $this->contentObject;
    }

    /**
     * Sets the specified raw configuration coming from the outside.
     * Note that this is a low level method and only makes sense to be used by Extbase internally.
     *
     * @param array $configuration The new configuration
     */
    public function setConfiguration(array $configuration = []): void
    {
        // reset 1st level cache
        $this->configurationCache = [];
        $this->extensionName = $configuration['extensionName'] ?? null;
        $this->pluginName = $configuration['pluginName'] ?? null;
        $this->configuration = $this->typoScriptService->convertTypoScriptArrayToPlainArray($configuration);
    }

    /**
     * Loads the Extbase Framework configuration.
     *
     * The Extbase framework configuration HAS TO be retrieved using this method, as they are come from different places than the normal settings.
     * Framework configuration is, in contrast to normal settings, needed for the Extbase framework to operate correctly.
     *
     * @param string|null $extensionName if specified, the configuration for the given extension will be returned (plugin.tx_extensionname)
     * @param string|null $pluginName if specified, the configuration for the given plugin will be returned (plugin.tx_extensionname_pluginname)
     * @return array the Extbase framework configuration
     */
    public function getConfiguration(?string $extensionName = null, ?string $pluginName = null): array
    {
        // 1st level cache
        $configurationCacheKey = strtolower(($extensionName ?: $this->extensionName) . '_' . ($pluginName ?: $this->pluginName));
        if (isset($this->configurationCache[$configurationCacheKey])) {
            return $this->configurationCache[$configurationCacheKey];
        }
        $frameworkConfiguration = $this->getExtbaseConfiguration();
        if (!isset($frameworkConfiguration['persistence']['storagePid'])) {
            $frameworkConfiguration['persistence']['storagePid'] = $this->getDefaultBackendStoragePid();
        }
        // only merge $this->configuration and override controller configuration when retrieving configuration of the current plugin
        if ($extensionName === null || $extensionName === $this->extensionName && $pluginName === $this->pluginName) {
            $pluginConfiguration = $this->getPluginConfiguration((string)$this->extensionName, (string)$this->pluginName);
            ArrayUtility::mergeRecursiveWithOverrule($pluginConfiguration, $this->configuration);
            $pluginConfiguration['controllerConfiguration'] = [];
        } else {
            $pluginConfiguration = $this->getPluginConfiguration((string)$extensionName, (string)$pluginName);
            $pluginConfiguration['controllerConfiguration'] = [];
        }
        ArrayUtility::mergeRecursiveWithOverrule($frameworkConfiguration, $pluginConfiguration);

        if (!empty($frameworkConfiguration['persistence']['storagePid'])) {
            if (is_array($frameworkConfiguration['persistence']['storagePid'])) {
                // We simulate the frontend to enable the use of cObjects in
                // stdWrap. We then convert the configuration to normal TypoScript
                // and apply the stdWrap to the storagePid
                // Use makeInstance here since extbase Bootstrap always setContentObject(null) in Backend, no need to call getContentObject().
                FrontendSimulatorUtility::simulateFrontendEnvironment(GeneralUtility::makeInstance(ContentObjectRenderer::class));
                $conf = $this->typoScriptService->convertPlainArrayToTypoScriptArray($frameworkConfiguration['persistence']);
                $frameworkConfiguration['persistence']['storagePid'] = $GLOBALS['TSFE']->cObj->stdWrapValue('storagePid', $conf);
                FrontendSimulatorUtility::resetFrontendEnvironment();
            }

            if (!empty($frameworkConfiguration['persistence']['recursive'])) {
                $storagePids = $this->getRecursiveStoragePids(
                    GeneralUtility::intExplode(',', (string)($frameworkConfiguration['persistence']['storagePid'] ?? '')),
                    (int)$frameworkConfiguration['persistence']['recursive']
                );
                $frameworkConfiguration['persistence']['storagePid'] = implode(',', $storagePids);
            }
        }
        // 1st level cache
        $this->configurationCache[$configurationCacheKey] = $frameworkConfiguration;
        return $frameworkConfiguration;
    }

    /**
     * Returns the TypoScript configuration found in config.tx_extbase
     */
    protected function getExtbaseConfiguration(): array
    {
        $setup = $this->getTypoScriptSetup();
        $extbaseConfiguration = [];
        if (isset($setup['config.']['tx_extbase.'])) {
            $extbaseConfiguration = $this->typoScriptService->convertTypoScriptArrayToPlainArray($setup['config.']['tx_extbase.']);
        }
        return $extbaseConfiguration;
    }

    /**
     * Returns TypoScript Setup array from current Environment.
     *
     * @return array the raw TypoScript setup
     */
    public function getTypoScriptSetup(): array
    {
        $pageId = $this->getCurrentPageId();

        if (!array_key_exists($pageId, $this->typoScriptSetupCache)) {
            $template = GeneralUtility::makeInstance(TemplateService::class);
            // do not log time-performance information
            $template->tt_track = false;
            // Explicitly trigger processing of extension static files
            $template->setProcessExtensionStatics(true);
            // Get the root line
            $rootline = [];
            if ($pageId > 0) {
                try {
                    $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
                } catch (\RuntimeException $e) {
                    $rootline = [];
                }
            }
            // This generates the constants/config + hierarchy info for the template.
            $template->runThroughTemplates($rootline, 0);
            $template->generateConfig();
            $this->typoScriptSetupCache[$pageId] = $template->setup;
        }
        return $this->typoScriptSetupCache[$pageId];
    }

    /**
     * Returns the TypoScript configuration found in module.tx_yourextension_yourmodule
     * merged with the global configuration of your extension from module.tx_yourextension
     *
     * @param string $extensionName
     * @param string $pluginName in BE mode this is actually the module signature. But we're using it just like the plugin name in FE
     * @return array
     */
    protected function getPluginConfiguration(string $extensionName, string $pluginName = null): array
    {
        $setup = $this->getTypoScriptSetup();
        $pluginConfiguration = [];
        if (is_array($setup['module.']['tx_' . strtolower($extensionName) . '.'] ?? false)) {
            $pluginConfiguration = $this->typoScriptService->convertTypoScriptArrayToPlainArray($setup['module.']['tx_' . strtolower($extensionName) . '.']);
        }
        if ($pluginName !== null) {
            $pluginSignature = strtolower($extensionName . '_' . $pluginName);
            if (is_array($setup['module.']['tx_' . $pluginSignature . '.'] ?? false)) {
                $overruleConfiguration = $this->typoScriptService->convertTypoScriptArrayToPlainArray($setup['module.']['tx_' . $pluginSignature . '.']);
                ArrayUtility::mergeRecursiveWithOverrule($pluginConfiguration, $overruleConfiguration);
            }
        }
        return $pluginConfiguration;
    }

    /**
     * Returns the page uid of the current page.
     * If no page is selected, we'll return the uid of the first root page.
     *
     * @return int current page id. If no page is selected current root page id is returned
     */
    protected function getCurrentPageId(): int
    {
        if ($this->currentPageId !== null) {
            return $this->currentPageId;
        }

        $this->currentPageId = $this->getCurrentPageIdFromGetPostData() ?: $this->getCurrentPageIdFromCurrentSiteRoot();
        $this->currentPageId = $this->currentPageId ?: $this->getCurrentPageIdFromRootTemplate();
        $this->currentPageId = $this->currentPageId ?: self::DEFAULT_BACKEND_STORAGE_PID;

        return $this->currentPageId;
    }

    /**
     * Gets the current page ID from the GET/POST data.
     *
     * @return int the page UID, will be 0 if none has been set
     */
    protected function getCurrentPageIdFromGetPostData(): int
    {
        return (int)GeneralUtility::_GP('id');
    }

    /**
     * Gets the current page ID from the first site root in tree.
     *
     * @return int the page UID, will be 0 if none has been set
     */
    protected function getCurrentPageIdFromCurrentSiteRoot(): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));

        $rootPage = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('is_siteroot', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // Only consider live root page IDs, never return a versioned root page ID
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAssociative();

        if (empty($rootPage)) {
            return 0;
        }

        return (int)$rootPage['uid'];
    }

    /**
     * Gets the current page ID from the first created root template.
     *
     * @return int the page UID, will be 0 if none has been set
     */
    protected function getCurrentPageIdFromRootTemplate(): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_template');

        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));

        $rootTemplate = $queryBuilder
            ->select('pid')
            ->from('sys_template')
            ->where(
                $queryBuilder->expr()->eq('root', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->orderBy('crdate')
            ->executeQuery()
            ->fetchAssociative();

        if (empty($rootTemplate)) {
            return 0;
        }

        return (int)$rootTemplate['pid'];
    }

    /**
     * Returns the default backend storage pid
     *
     * @return int
     */
    public function getDefaultBackendStoragePid(): int
    {
        // todo: fallback to parent::getDefaultBackendStoragePid() would make sense here.
        return $this->getCurrentPageId();
    }

    /**
     * Returns an array of storagePIDs that are below a list of storage pids.
     *
     * @param int[] $storagePids Storage PIDs to start at; multiple PIDs possible as comma-separated list
     * @param int $recursionDepth Maximum number of levels to search, 0 to disable recursive lookup
     * @return int[] Uid list including the start $storagePids
     */
    protected function getRecursiveStoragePids(array $storagePids, int $recursionDepth = 0): array
    {
        if ($recursionDepth <= 0) {
            return $storagePids;
        }
        $permsClause = QueryHelper::stripLogicalOperatorPrefix(
            $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW)
        );
        $recursiveStoragePids = [];
        foreach ($storagePids as $startPid) {
            $startPid = abs($startPid);
            $recursiveStoragePids = array_merge(
                $recursiveStoragePids,
                [ $startPid ],
                $this->getPageChildrenRecursive($startPid, $recursionDepth, 0, $permsClause)
            );
        }
        return array_unique($recursiveStoragePids);
    }

    /**
     * Recursively fetch all children of a given page
     *
     * @param int $pid uid of the page
     * @param int $depth
     * @param int $begin
     * @param string $permsClause
     * @return int[] List of child row $uid's
     */
    protected function getPageChildrenRecursive(int $pid, int $depth, int $begin, string $permsClause): array
    {
        $children = [];
        if ($pid && $depth > 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $statement = $queryBuilder->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', 0),
                    $permsClause
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $statement->fetchAssociative()) {
                if ($begin <= 0) {
                    $children[] = (int)$row['uid'];
                }
                if ($depth > 1) {
                    $theSubList = $this->getPageChildrenRecursive((int)$row['uid'], $depth - 1, $begin - 1, $permsClause);
                    $children = array_merge($children, $theSubList);
                }
            }
        }
        return $children;
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
