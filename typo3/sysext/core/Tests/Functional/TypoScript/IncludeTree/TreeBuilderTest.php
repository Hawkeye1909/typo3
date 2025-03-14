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

namespace TYPO3\CMS\Core\Tests\Functional\TypoScript\IncludeTree;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\IncludeTree\IncludeNode\RootInclude;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Traverser\IncludeTreeTraverser;
use TYPO3\CMS\Core\TypoScript\IncludeTree\TreeBuilder;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Visitor\IncludeTreeAstBuilderVisitor;
use TYPO3\CMS\Core\TypoScript\Tokenizer\LossyTokenizer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class TreeBuilderTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    /**
     * @var array
     */
    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->get(CacheManager::class)->getCache('typoscript')->flush();
        $this->writeSiteConfiguration(
            'website-local',
            [
                'rootPageId' => 1,
                'base' => 'http://localhost/',
                'settings' => [
                    'testConstantFromSite' => 'testValueFromSite',
                    'nestedConfiguration' => [
                        'foo' => 'bar',
                    ],
                ],
            ],
            [
                $this->buildDefaultLanguageConfiguration('EN', '/en/'),
            ]
        );
    }

    /**
     * @test
     */
    public function singleRootTemplate(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/singleRootTemplate.csv');
        $rootline = [
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRepository->getSysTemplateRowsByRootline($rootline), new LossyTokenizer());
        $ast = $this->getAst($includeTree);
        self::assertSame('fooValue', $ast->getChildByName('foo')->getValue());
    }

    /**
     * @test
     */
    public function singleRootTemplateLoadsFromGlobals(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/singleRootTemplate.csv');
        $GLOBALS['TYPO3_CONF_VARS']['FE']['defaultTypoScript_constants'] = 'bar = barValue';
        $rootline = [
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRepository->getSysTemplateRowsByRootline($rootline), new LossyTokenizer());
        $ast = $this->getAst($includeTree);
        self::assertSame('fooValue', $ast->getChildByName('foo')->getValue());
        self::assertSame('barValue', $ast->getChildByName('bar')->getValue());
    }

    /**
     * @test
     */
    public function singleRootTemplateLoadConstantFromSite(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/singleRootTemplate.csv');
        $rootline = [
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var SiteFinder $siteFinder */
        $siteFinder = $this->get(SiteFinder::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite(
            'constants',
            $sysTemplateRepository->getSysTemplateRowsByRootline($rootline),
            new LossyTokenizer(),
            $siteFinder->getSiteByPageId(1)
        );
        $ast = $this->getAst($includeTree);
        self::assertSame('testValueFromSite', $ast->getChildByName('testConstantFromSite')->getValue());
    }

    /**
     * @test
     */
    public function twoPagesTwoTemplates(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/twoPagesTwoTemplates.csv');
        $rootline = [
            [
                'uid' => 2,
                'pid' => 1,
                'is_siteroot' => 0,
            ],
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRepository->getSysTemplateRowsByRootline($rootline), new LossyTokenizer());
        $ast = $this->getAst($includeTree);
        self::assertSame('fooValue', $ast->getChildByName('foo')->getValue());
        self::assertSame('barValue', $ast->getChildByName('bar')->getValue());
    }

    /**
     * @test
     */
    public function twoPagesTwoTemplatesBothClear(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/twoPagesTwoTemplatesBothClear.csv');
        $rootline = [
            [
                'uid' => 2,
                'pid' => 1,
                'is_siteroot' => 0,
            ],
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRepository->getSysTemplateRowsByRootline($rootline), new LossyTokenizer());
        $ast = $this->getAst($includeTree);
        self::assertNull($ast->getChildByName('foo'));
        self::assertSame('barValue', $ast->getChildByName('bar')->getValue());
    }

    /**
     * @test
     */
    public function twoTemplatesOnPagePrefersTheOneWithLowerSorting(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/twoTemplatesOnPage.csv');
        $rootline = [
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRepository->getSysTemplateRowsByRootline($rootline), new LossyTokenizer());
        $ast = $this->getAst($includeTree);
        self::assertSame('fooValue', $ast->getChildByName('foo')->getValue());
        self::assertNull($ast->getChildByName('bar'));
    }

    /**
     * @test
     */
    public function basedOnSimple(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/basedOnSimple.csv');
        $rootline = [
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRepository->getSysTemplateRowsByRootline($rootline), new LossyTokenizer());
        $ast = $this->getAst($includeTree);
        self::assertSame('fooValue', $ast->getChildByName('foo')->getValue());
        self::assertSame('loadedByBasedOn', $ast->getChildByName('bar')->getValue());
    }

    /**
     * @test
     */
    public function basedOnAfterIncludeStatic(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/basedOnAfterIncludeStatic.csv');
        $rootline = [
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRepository->getSysTemplateRowsByRootline($rootline), new LossyTokenizer());
        $ast = $this->getAst($includeTree);
        self::assertSame('fooValue', $ast->getChildByName('foo')->getValue());
        self::assertSame('loadedByBasedOn', $ast->getChildByName('bar')->getValue());
    }

    /**
     * @test
     */
    public function basedOnBeforeIncludeStatic(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysTemplate/basedOnBeforeIncludeStatic.csv');
        $rootline = [
            [
                'uid' => 1,
                'pid' => 0,
                'is_siteroot' => 0,
            ],
        ];
        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = $this->get(SysTemplateRepository::class);
        /** @var TreeBuilder $treeBuilder */
        $treeBuilder = $this->get(TreeBuilder::class);
        $includeTree = $treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRepository->getSysTemplateRowsByRootline($rootline), new LossyTokenizer());
        $ast = $this->getAst($includeTree);
        self::assertSame('fooValue', $ast->getChildByName('foo')->getValue());
        self::assertSame('includeStaticTarget', $ast->getChildByName('bar')->getValue());
    }

    /**
     * Helper to calculate AST from given includeTree. Asserting AST
     * details is easier than looking up streams on the IncludeTree,
     * and we can test the Traverser and AstVisitor along the way.
     */
    private function getAst(RootInclude $rootInclude): RootNode
    {
        /** @var IncludeTreeAstBuilderVisitor $astBuilderVisitor */
        $astBuilderVisitor = $this->get(IncludeTreeAstBuilderVisitor::class);
        $traverser = new IncludeTreeTraverser();
        $traverser->addVisitor($astBuilderVisitor);
        $traverser->traverse($rootInclude);
        return $astBuilderVisitor->getAst();
    }
}
