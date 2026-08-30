<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Form;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Extbase\Configuration\Exception\NoServerRequestGivenException;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Extbase\Service\ExtensionService;

/**
 * @internal
 */
final readonly class FormFieldNameResolver
{
    public function __construct(
        private ExtensionService $extensionService,
    ) {}

    public function fieldName(
        RequestInterface $request,
        string $formIdentifier,
        string $elementIdentifier,
    ): string {
        $name = $formIdentifier;
        foreach (explode('.', $elementIdentifier) as $segment) {
            $name .= '[' . $segment . ']';
        }

        $prefix = $this->fieldNamePrefix($request);
        if ($prefix === '') {
            return $name;
        }

        return $prefix . '[' . $formIdentifier . ']' . substr($name, strlen($formIdentifier));
    }

    public function valueFromParsedBody(
        ServerRequestInterface $request,
        string $formIdentifier,
        string $elementIdentifier,
    ): mixed {
        $body = $request->getParsedBody();
        if (!is_array($body) || !$request instanceof RequestInterface) {
            return null;
        }

        $path = [$formIdentifier, ...explode('.', $elementIdentifier)];
        $prefix = $this->fieldNamePrefix($request);
        if ($prefix !== '') {
            array_unshift($path, $prefix);
        }

        $value = $body;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function withoutValue(
        RequestInterface $request,
        string $formIdentifier,
        string $elementIdentifier,
    ): RequestInterface {
        $elementPath = explode('.', $elementIdentifier);
        $arguments = $request->getArguments();
        foreach ([$elementPath, [$formIdentifier, ...$elementPath]] as $argumentPath) {
            $arguments = $this->withoutPath($arguments, $argumentPath);
        }
        $request = $request->withArguments($arguments);

        $body = $request->getParsedBody();
        if (is_array($body)) {
            $bodyPaths = [
                $elementPath,
                [$formIdentifier, ...$elementPath],
                [$formIdentifier, $formIdentifier, ...$elementPath],
            ];
            $prefix = $this->fieldNamePrefix($request);
            if ($prefix !== '') {
                $bodyPaths[] = [$prefix, $formIdentifier, ...$elementPath];
                $bodyPaths[] = [$prefix, $formIdentifier, $formIdentifier, ...$elementPath];
            }
            foreach ($bodyPaths as $bodyPath) {
                $body = $this->withoutPath($body, $bodyPath);
            }
            $request = $request->withParsedBody($body);
        }

        return $request
            ->withBody(new Stream('php://temp', 'rw'))
            ->withoutHeader('Content-Length');
    }

    private function fieldNamePrefix(RequestInterface $request): string
    {
        if (ApplicationType::fromRequest($request)->isBackend()) {
            return '';
        }

        $extensionName = $request->getControllerExtensionName();
        $pluginName = $request->getPluginName();
        try {
            return $this->extensionService->getPluginNamespace($extensionName, $pluginName);
        } catch (NoServerRequestGivenException) {
            return 'tx_' . strtolower($extensionName . '_' . $pluginName);
        }
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $path
     * @return array<mixed>
     */
    private function withoutPath(array $values, array $path): array
    {
        $segment = array_shift($path);
        if ($segment === null || !array_key_exists($segment, $values)) {
            return $values;
        }
        if ($path === []) {
            unset($values[$segment]);
            return $values;
        }
        if (is_array($values[$segment])) {
            $values[$segment] = $this->withoutPath($values[$segment], $path);
        }

        return $values;
    }
}
