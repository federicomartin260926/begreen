<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class UserManualProvider
{
    private const ADMIN_MANUAL_FILENAME = 'manual-administrador.pdf';
    private const SUPER_ADMIN_MANUAL_FILENAME = 'manual-super-administrador.pdf';

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly string $manualDirectory,
    ) {
    }

    public function isAvailableForCurrentUser(): bool
    {
        return null !== $this->resolveForCurrentUser();
    }

    public function resolveForCurrentUser(): ?UserManualDocument
    {
        if ($this->authorizationChecker->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->document(
                self::SUPER_ADMIN_MANUAL_FILENAME,
                'manual-de-usuario-super-administrador.pdf',
                'Super Administrador',
            );
        }

        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return $this->document(
                self::ADMIN_MANUAL_FILENAME,
                'manual-de-usuario-administrador.pdf',
                'Administrador / Consultora',
            );
        }

        return null;
    }

    private function document(string $filename, string $downloadName, string $profile): ?UserManualDocument
    {
        $path = $this->manualDirectory.'/'.$filename;

        return is_file($path) && is_readable($path)
            ? new UserManualDocument($path, $downloadName, $profile)
            : null;
    }
}
