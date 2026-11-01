<?php
namespace App\Twig;

use App\Service\UserManualProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(private readonly UserManualProvider $userManualProvider)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('json_decode', [$this, 'jsonDecode']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_manual_available', [$this->userManualProvider, 'isAvailableForCurrentUser']),
        ];
    }

    public function jsonDecode(string $json): array
    {
        return json_decode($json, true) ?? [];
    }
}
