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

namespace TYPO3\CMS\Install\Tests\Unit\ExtensionScanner\Php\Matcher;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use TYPO3\CMS\Install\ExtensionScanner\Php\GeneratorClassesResolver;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallStaticMatcher;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case
 */
class MethodCallStaticMatcherTest extends UnitTestCase
{
    /**
     * @test
     */
    public function hitsFromFixtureAreFound(): void
    {
        $parser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7);
        $fixtureFile = __DIR__ . '/Fixtures/MethodCallStaticMatcherFixture.php';
        $statements = $parser->parse(file_get_contents($fixtureFile));

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new GeneratorClassesResolver());

        $configuration = [
            'TYPO3\CMS\Backend\Utility\BackendUtility::getAjaxUrl' => [
                'numberOfMandatoryArguments' => 1,
                'maximumNumberOfArguments' => 2,
                'restFiles' => [
                    'Breaking-80700-DeprecatedFunctionalityRemoved.rst',
                    'Deprecation-75340-MethodsRelatedToGeneratingTraditionalBackendAJAXURLs.rst',
                ],
            ],
        ];
        $subject = new MethodCallStaticMatcher($configuration);
        $traverser->addVisitor($subject);
        $traverser->traverse($statements);
        $expectedHitLineNumbers = [
            30,
            32,
        ];
        $actualHitLineNumbers = [];
        foreach ($subject->getMatches() as $hit) {
            $actualHitLineNumbers[] = $hit['line'];
        }
        self::assertEquals($expectedHitLineNumbers, $actualHitLineNumbers);
    }

    /**
     * @return array
     */
    public function matchesReturnsExpectedRestFilesDataProvider(): array
    {
        return [
            'two rest candidates with same number of arguments' => [
                [
                    'Foo::aMethod' => [
                        'numberOfMandatoryArguments' => 0,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'Foo-1.rst',
                            'Foo-2.rst',
                        ],
                    ],
                    'Bar::aMethod' => [
                        'numberOfMandatoryArguments' => 0,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'Bar-1.rst',
                            'Bar-2.rst',
                        ],
                    ],
                ],
                '<?php
                $someVar::aMethod();',
                [
                    0 => [
                        'restFiles' => [
                            'Foo-1.rst',
                            'Foo-2.rst',
                            'Bar-1.rst',
                            'Bar-2.rst',
                        ],
                    ],
                ],
            ],
            'two candidates, only one hits because second candidate needs one argument' => [
                [
                    'Foo::aMethod' => [
                        'numberOfMandatoryArguments' => 0,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                    'Bar::aMethod' => [
                        'numberOfMandatoryArguments' => 1,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'Bar-1.rst',
                        ],
                    ],
                ],
                '<?php
                $someVar::aMethod();',
                [
                    0 => [
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                ],
            ],
            'three candidates, first and second hits' => [
                [
                    'Foo::aMethod' => [
                        'numberOfMandatoryArguments' => 2,
                        'maximumNumberOfArguments' => 4,
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                    'Bar::aMethod' => [
                        'numberOfMandatoryArguments' => 1,
                        'maximumNumberOfArguments' => 4,
                        'restFiles' => [
                            'Bar-1.rst',
                        ],
                    ],
                    'FooBar::aMethod' => [
                        'numberOfMandatoryArguments' => 3,
                        'maximumNumberOfArguments' => 4,
                        'restFiles' => [
                            'FooBar-1.rst',
                        ],
                    ],
                ],
                '<?php
                $someVar::aMethod(\'arg1\', \'arg2\');',
                [
                    0 => [
                        'restFiles' => [
                            'Foo-1.rst',
                            'Bar-1.rst',
                        ],
                    ],
                ],
            ],
            'one candidate, does not hit, not enough arguments given' => [
                [
                    'Foo::aMethod' => [
                        'numberOfMandatoryArguments' => 1,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                ],
                '<?php
                $someVar::aMethod();',
                [], // no hit
            ],
            'too many arguments given' => [
                [
                    'Foo::aMethod' => [
                        'numberOfMandatoryArguments' => 1,
                        'maximumNumberOfArguments' => 1,
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                ],
                '<?php
                $someVar::aMethod($foo, $bar);',
                [], // no hit
            ],
            'method call using argument unpacking' => [
                [
                    'Foo::aMethod' => [
                        'numberOfMandatoryArguments' => 2,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                ],
                '<?php
                $args = [\'arg1\', \'arg2\', \'arg3\'];
                $someVar::aMethod(...$args);',
                [
                    0 => [
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                ],
            ],
            'method call using argument unpacking with more than max number of args given arguments' => [
                [
                    'Foo::aMethod' => [
                        'numberOfMandatoryArguments' => 2,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                ],
                '<?php
                $args1 = [\'arg1\', \'arg2\', \'arg3\'];
                $args2 = [\'arg4\', \'arg5\', \'arg6\'];
                $args3 = [\'arg7\', \'arg8\', \'arg9\'];
                $someVar::aMethod(...$args1, ...$args2, ...$args3);',
                [
                    0 => [
                        'restFiles' => [
                            'Foo-1.rst',
                        ],
                    ],
                ],
            ],
            // something wrong here
            'double linked .rst file is returned only once' => [
                [
                    'Foo::aMethod' => [
                        'numberOfMandatoryArguments' => 0,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'aRest.rst',
                        ],
                    ],
                    'Bar::aMethod' => [
                        'numberOfMandatoryArguments' => 0,
                        'maximumNumberOfArguments' => 2,
                        'restFiles' => [
                            'aRest.rst',
                        ],
                    ],
                ],
                '<?php
                $someVar::aMethod(\'foo\');',
                [
                    0 => [
                        'restFiles' => [
                            'aRest.rst',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider matchesReturnsExpectedRestFilesDataProvider
     */
    public function matchesReturnsExpectedRestFiles(array $configuration, string $phpCode, array $expected): void
    {
        $parser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7);
        $statements = $parser->parse($phpCode);

        $subject = new MethodCallStaticMatcher($configuration);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($subject);
        $traverser->traverse($statements);

        $result = $subject->getMatches();
        if (isset($expected[0], $result[0])) {
            self::assertEquals($expected[0]['restFiles'], $result[0]['restFiles']);
        } else {
            self::assertEquals($expected, $result);
        }
    }
}
