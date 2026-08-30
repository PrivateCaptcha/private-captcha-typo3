<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Http\Response;

/**
 * @internal
 */
final readonly class SettingsTraceRedactionMiddleware implements MiddlewareInterface
{
    private const SETTINGS_ROUTES = [
        'private_captcha_settings.site',
        'private_captcha_settings.backend',
    ];

    public function process(
        #[\SensitiveParameter]
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $route = $request->getAttributes()['route'] ?? null;
        if (strtoupper($request->getMethod()) !== 'POST'
            || !$route instanceof Route
            || !in_array($route->getOption('_identifier'), self::SETTINGS_ROUTES, true)
        ) {
            return $handler->handle($request);
        }

        $previousValue = ini_get('zend.exception_ignore_args');
        if ($previousValue === '1') {
            return $handler->handle($request);
        }
        if (ini_set('zend.exception_ignore_args', '1') === false) {
            return new Response(statusCode: 500);
        }

        try {
            return $handler->handle($request);
        } finally {
            if (is_string($previousValue)) {
                ini_set('zend.exception_ignore_args', $previousValue);
            }
        }
    }
}
