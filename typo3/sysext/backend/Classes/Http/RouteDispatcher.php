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

namespace TYPO3\CMS\Backend\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\FormProtection\AbstractFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\Dispatcher;
use TYPO3\CMS\Core\Http\Security\ReferrerEnforcer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Dispatcher which resolves a route to call a controller and method (but also a callable)
 */
class RouteDispatcher extends Dispatcher
{
    /**
     * Main method checks the target of the route, and tries to call it.
     *
     * @param ServerRequestInterface $request the current server request
     * @return ResponseInterface the filled response by the callable / controller/action
     * @throws InvalidRequestTokenException if the route requested a token, but this token did not match
     * @throws MissingRequestTokenException if the route requested a token, but there was none
     * @throws \InvalidArgumentException if the defined target for the route is invalid
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Route $route */
        $route = $request->getAttribute('route');

        $enforceReferrerResponse = $this->enforceReferrer($request, $route);
        if ($enforceReferrerResponse instanceof ResponseInterface) {
            return $enforceReferrerResponse;
        }
        // Ensure that a token exists, and the token is requested, if the route requires a valid token
        $this->assertRequestToken($request, $route);

        $targetIdentifier = $route->getOption('target');
        $target = $this->getCallableFromTarget($targetIdentifier);
        $arguments = [$request];
        return $target(...$arguments);
    }

    /**
     * Wrapper method for static form protection utility
     *
     * @return AbstractFormProtection
     */
    protected function getFormProtection(): AbstractFormProtection
    {
        return FormProtectionFactory::get();
    }

    /**
     * Evaluates HTTP `Referer` header (which is denied by client to be a custom
     * value) - attempts to ensure the value is given using a HTML client refresh.
     * see: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Referer
     *
     * @param ServerRequestInterface $request
     * @param Route $route
     * @return ResponseInterface|null
     */
    protected function enforceReferrer(ServerRequestInterface $request, Route $route): ?ResponseInterface
    {
        $features = GeneralUtility::makeInstance(Features::class);
        if (!$features->isFeatureEnabled('security.backend.enforceReferrer')) {
            return null;
        }
        $referrerFlags = GeneralUtility::trimExplode(',', $route->getOption('referrer') ?? '', true);
        if (!in_array('required', $referrerFlags, true)) {
            return null;
        }
        $referrerEnforcer = GeneralUtility::makeInstance(ReferrerEnforcer::class, $request);
        return $referrerEnforcer->handle([
            'flags' => $referrerFlags,
            'subject' => $route->getPath(),
        ]);
    }

    /**
     * Checks if the request token is valid. This is checked to see if the route is really
     * created by the same instance. Should be called for all routes in the backend except
     * for the ones that don't require a login.
     *
     * @param ServerRequestInterface $request
     * @param Route $route
     * @see UriBuilder where the token is generated.
     */
    protected function assertRequestToken(ServerRequestInterface $request, Route $route): void
    {
        if ($route->getOption('access') === 'public') {
            return;
        }
        $token = (string)($request->getParsedBody()['token'] ?? $request->getQueryParams()['token'] ?? '');
        if (empty($token)) {
            throw new MissingRequestTokenException(
                sprintf('Invalid request for route "%s"', $route->getPath()),
                1627905246
            );
        }
        if (!$this->getFormProtection()->validateToken($token, 'route', $route->getOption('_identifier'))) {
            throw new InvalidRequestTokenException(
                sprintf('Invalid request for route "%s"', $route->getPath()),
                1425389455
            );
        }
    }
}
