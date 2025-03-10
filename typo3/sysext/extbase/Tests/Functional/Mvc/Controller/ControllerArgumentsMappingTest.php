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

namespace TYPO3\CMS\Extbase\Tests\Functional\Mvc\Controller;

use ExtbaseTeam\BlogExample\Controller\BlogController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ControllerArgumentsMappingTest extends FunctionalTestCase
{
    /**
     * @var \TYPO3\CMS\Extbase\Mvc\Request
     */
    protected $request;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * @var BlogController
     */
    protected $controller;

    protected array $testExtensionsToLoad = ['typo3/sysext/extbase/Tests/Functional/Fixtures/Extensions/blog_example'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Persistence/Fixtures/translatedBlogExampleData.csv');

        $configuration = [
            'persistence' => [
                'storagePid' => 20,
            ],
        ];
        $configurationManager = $this->get(ConfigurationManager::class);
        $configurationManager->setConfiguration($configuration);
        $serverRequest = (new ServerRequest())->withAttribute('extbase', new ExtbaseRequestParameters());
        $this->request = new Request($serverRequest);
        $this->request = $this->request->withPluginName('Pi1');
        $this->request = $this->request->withControllerExtensionName(BlogController::class);
        $this->request = $this->request->withControllerName('Blog');
        $this->request = $this->request->withFormat('html');

        $this->controller = $this->get(BlogController::class);
    }

    public function actionGetsBlogFromUidArgumentDataProvider(): array
    {
        return [
            [
                'language' => 0,
                'blogUid' => 1,
                'blogTitle' => 'Blog 1',
            ],
            [
                'language' => 1,
                'blogUid' => 1,
                'blogTitle' => 'Blog 1 DK',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider actionGetsBlogFromUidArgumentDataProvider
     */
    public function actionGetsBlogFromUidArgument(int $language, int $blogUid, string $expectedTitle): void
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('language', new LanguageAspect($language, $language, LanguageAspect::OVERLAYS_ON));
        $this->request = $this->request->withControllerActionName('details');
        $this->request = $this->request->withArgument('blog', $blogUid);

        $response = $this->controller->processRequest($this->request);

        $response->getBody()->rewind();
        self::assertEquals($expectedTitle, $response->getBody()->getContents());
    }
}
