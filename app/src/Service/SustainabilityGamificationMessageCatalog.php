<?php

namespace App\Service;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SustainabilityGamificationMessageCatalog
{
    public const EVENT_WELCOME = 'welcome';
    public const EVENT_LEVEL_UP = 'level_up';
    public const EVENT_COMPLETED_100 = 'completed_100';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly TranslatorBagInterface $translatorBag,
        private readonly GamificationMessageSelector $selector,
    ) {
    }

    /**
     * @return array{key:string,type:string,text:string}
     */
    public function choose(string $event, ?string $level = null, ?string $excludedKey = null): array
    {
        $prefix = $level === null ? $event . '.' : $event . '.' . $level . '.';
        $messages = $this->translatorBag->getCatalogue()->all('gamification');
        $keys = array_values(array_filter(
            array_keys($messages),
            static fn (string $key): bool => str_starts_with($key, $prefix)
        ));
        sort($keys);

        if ($excludedKey !== null && count($keys) > 1) {
            $keys = array_values(array_filter(
                $keys,
                static fn (string $key): bool => $key !== $excludedKey
            ));
        }

        if ($keys === []) {
            throw new \LogicException(sprintf('Gamification catalogue "%s" is empty.', rtrim($prefix, '.')));
        }

        $key = $this->selector->select($keys);

        return $this->translate($key, $event);
    }

    /**
     * @return array{key:string,type:string,text:string}
     */
    public function translate(string $key, string $event): array
    {
        return [
            'key' => $key,
            'type' => $event,
            'text' => $this->translator->trans($key, [], 'gamification'),
        ];
    }
}
