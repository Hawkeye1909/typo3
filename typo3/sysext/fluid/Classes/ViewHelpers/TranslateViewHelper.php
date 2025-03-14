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

namespace TYPO3\CMS\Fluid\ViewHelpers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\RequestInterface as ExtbaseRequestInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Translate a key from locallang. The files are loaded from the folder
 * :file:`Resources/Private/Language/`.
 *
 * Examples
 * ========
 *
 * Translate key
 * -------------
 *
 * ::
 *
 *    <f:translate key="key1" />
 *
 * Value of key ``key1`` in the current website language. Alternatively id can
 * be used instead of key::
 *
 *    <f:translate id="key1" />
 *
 * This will output the same as above. If both id and key are set, id will take precedence.
 *
 * Keep HTML tags
 * --------------
 *
 * ::
 *
 *    <f:format.raw><f:translate key="htmlKey" /></f:format.raw>
 *
 * Value of key ``htmlKey`` in the current website language, no :php:`htmlspecialchars()` applied.
 *
 * Translate key from custom locallang file
 * ----------------------------------------
 *
 * ::
 *
 *    <f:translate key="key1" extensionName="MyExt"/>
 *
 * or
 *
 * ::
 *
 *    <f:translate key="LLL:EXT:myext/Resources/Private/Language/locallang.xlf:key1" />
 *
 * Value of key ``key1`` in the current website language.
 *
 * Inline notation with arguments and default value
 * ------------------------------------------------
 *
 * ::
 *
 *    {f:translate(key: 'someKey', arguments: {0: 'dog', 1: 'fox'}, default: 'default value')}
 *
 * Value of key ``someKey`` in the current website language
 * with the given arguments (“dog” and “fox”) assigned for the specified
 * ``%s`` conversions (:php:`sprintf()`) in the language file::
 *
 *    <trans-unit id="someKey" resname="someKey">
 *        <source>Some text about a %s and a %s.</source>
 *    </trans-unit>
 *
 * The output will be "Some text about a dog and a fox".
 *
 * If the key ``someKey`` is not found in the language file, the output is “default value”.
 *
 * Inline notation with extension name
 * -----------------------------------
 *
 * ::
 *
 *    {f:translate(key: 'someKey', extensionName: 'SomeExtensionName')}
 *
 * Value of key ``someKey`` in the current website language.
 * The locallang file of extension "some_extension_name" will be used.
 */
final class TranslateViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /**
     * Output is escaped already. We must not escape children, to avoid double encoding.
     *
     * @var bool
     */
    protected $escapeChildren = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('key', 'string', 'Translation Key');
        $this->registerArgument('id', 'string', 'Translation ID. Same as key.');
        $this->registerArgument('default', 'string', 'If the given locallang key could not be found, this value is used. If this argument is not set, child nodes will be used to render the default');
        $this->registerArgument('arguments', 'array', 'Arguments to be replaced in the resulting string', false, []);
        $this->registerArgument('extensionName', 'string', 'UpperCamelCased extension key (for example BlogExample)');
        $this->registerArgument('languageKey', 'string', 'Language key ("dk" for example) or "default" to use. If empty, use current language. Ignored in non-extbase context.');
        $this->registerArgument('alternativeLanguageKeys', 'array', 'Alternative language keys if no translation does exist. Ignored in non-extbase context.');
    }

    /**
     * Return array element by key.
     *
     * @throws Exception
     * @throws \RuntimeException
     */
    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext): string
    {
        $key = $arguments['key'];
        $id = $arguments['id'];
        $default = (string)($arguments['default'] ?? $renderChildrenClosure() ?? '');
        $extensionName = $arguments['extensionName'];
        $translateArguments = $arguments['arguments'];

        // Use key if id is empty.
        if ($id === null) {
            $id = $key;
        }

        if ((string)$id === '') {
            throw new Exception('An argument "key" or "id" has to be provided', 1351584844);
        }

        $request = null;
        if ($renderingContext instanceof RenderingContext) {
            $request = $renderingContext->getRequest();
        }

        if (!$request instanceof ExtbaseRequestInterface) {
            // Straight resolving via core LanguageService in non-extbase context
            if (!str_starts_with($id, 'LLL:EXT:') && empty($default)) {
                // Resolve "short key" without LLL:EXT: syntax given, if an extension name is given.
                // @todo: We could consider to deprecate this case. It is mostly implemented for a more
                //        smooth transition when (backend) controllers no longer feed an extbase request.
                if (empty($extensionName)) {
                    throw new \RuntimeException(
                        'ViewHelper f:translate in non-extbase context needs attribute "extensionName" to resolve'
                        . ' key="' . $id . '" without path. Either set attribute "extensionName" together with the short'
                        . ' key "yourKey" to result in a lookup "LLL:EXT:your_extension/Resources/Private/Language/locallang.xlf:yourKey",'
                        . ' or (better) use a full LLL reference like key="LLL:EXT:your_extension/Resources/Private/Language/yourFile.xlf:yourKey"',
                        1639828178
                    );
                }
                $id = 'LLL:EXT:' . GeneralUtility::camelCaseToLowerCaseUnderscored($extensionName) . '/Resources/Private/Language/locallang.xlf:' . $id;
            }
            $value = self::getLanguageService($request)->sL($id);
            if (empty($value) || (!str_starts_with($id, 'LLL:EXT:') && $value === $id)) {
                // In case $value is empty (LLL: could not be resolved) or $value
                // is the same as $id and is no "LLL:", fall back to the default.
                $value = $default;
            }
            if (!empty($translateArguments)) {
                $value = vsprintf($value, $translateArguments);
            }
            return $value;
        }

        /** @var ExtbaseRequestInterface $request */
        $extensionName = $extensionName ?? $request->getControllerExtensionName();
        try {
            // Trigger full extbase magic: "<f:translate key="key1" />" will look up
            // "LLL:EXT:current_extension/Resources/Private/Language/locallang.xlf:key1" AND
            // overloads from _LOCAL_LANG extbase TypoScript settings if specified.
            // Not this triggers TypoScript parsing via extbase ConfigurationManager
            // and should be avoided in backend context!
            $value = LocalizationUtility::translate($id, $extensionName, $translateArguments, $arguments['languageKey'], $arguments['alternativeLanguageKeys']);
        } catch (\InvalidArgumentException $e) {
            // @todo: Switch to more specific Exceptions here - for instance those thrown when a package was not found, see #95957
            $value = null;
        }
        if ($value === null) {
            $value = $default;
            if (!empty($translateArguments)) {
                $value = vsprintf($value, $translateArguments);
            }
        }
        return $value;
    }

    protected static function getLanguageService(ServerRequestInterface $request = null): LanguageService
    {
        if (isset($GLOBALS['LANG'])) {
            return $GLOBALS['LANG'];
        }
        $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
        if ($request !== null && ApplicationType::fromRequest($request)->isFrontend()) {
            $GLOBALS['LANG'] = $languageServiceFactory->createFromSiteLanguage($request->getAttribute('language')
                ?? $request->getAttribute('site')->getDefaultLanguage());
            return $GLOBALS['LANG'];
        }
        $GLOBALS['LANG'] = $languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        return $GLOBALS['LANG'];
    }
}
