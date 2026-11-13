<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Build project-scoped Stripe Checkout returns using trusted per-environment
 * callback origins, never the request Host header or a browser supplied URL.
 */
final class ProjectCommercialReturnUrlBuilder
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%env(STRIPE_SUCCESS_URL)%')]
        private readonly string $configuredSuccessUrl,
        #[Autowire('%env(STRIPE_CANCEL_URL)%')]
        private readonly string $configuredCancelUrl,
    ) {
    }

    /** @return array{success_url: string, cancel_url: string} */
    public function build(int $projectId, string $returnPhase = 'elaboration'): array
    {
        if ($projectId < 1 || !in_array($returnPhase, ['elaboration', 'implementation'], true)) {
            throw new \InvalidArgumentException('Invalid project or return phase.');
        }

        $successOrigin = self::trustedOrigin($this->configuredSuccessUrl);
        $cancelOrigin = self::trustedOrigin($this->configuredCancelUrl);
        if ($successOrigin !== $cancelOrigin) {
            throw new \DomainException('Stripe return URL origins must match.');
        }

        $successPath = $this->router->generate('backend_project_commercial_checkout_success', [
            'id' => $projectId,
        ], UrlGeneratorInterface::ABSOLUTE_PATH);
        $cancelPath = $this->router->generate('backend_project_commercial_checkout_cancel', [
            'id' => $projectId,
        ], UrlGeneratorInterface::ABSOLUTE_PATH);
        if (!str_starts_with($successPath, '/') || !str_starts_with($cancelPath, '/')) {
            throw new \DomainException('Invalid project checkout return route.');
        }

        $phase = rawurlencode($returnPhase);

        return [
            'success_url' => $successOrigin . $successPath . '?phase=' . $phase . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelOrigin . $cancelPath . '?phase=' . $phase,
        ];
    }

    private static function trustedOrigin(string $configuredUrl): string
    {
        $parts = parse_url(trim($configuredUrl));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'])) {
            throw new \DomainException('Stripe callback URL is not configured safely.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \DomainException('Stripe callback URL must not contain credentials or fragments.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true))) {
            throw new \DomainException('Stripe callback URL must use HTTPS except for localhost.');
        }
        if ($host === '' || preg_match('/[^a-z0-9.:-]/', $host)) {
            throw new \DomainException('Invalid Stripe callback host.');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
