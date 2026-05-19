<?php

namespace App\Service;

final class SustainabilityPlanCustomMeasureParser
{
    /**
     * @return array<int, array{
     *     title: string,
     *     description: string,
     *     score: int|null,
     *     state: string,
     *     raw: string
     * }>
     */
    public function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $items = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $title = (string) ($parts[0] ?? '');
            if ($title === '') {
                continue;
            }

            $description = (string) ($parts[1] ?? '');
            $score = $this->parseScore($parts[2] ?? null);
            $state = $this->normalizeState($parts[3] ?? null);

            $items[] = [
                'title' => $title,
                'description' => $description,
                'score' => $score,
                'state' => $state,
                'raw' => $line,
            ];
        }

        return $items;
    }

    private function parseScore(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $score = (int) $value;
        if ($score < 1 || $score > 5) {
            return null;
        }

        return $score;
    }

    private function normalizeState(mixed $value): string
    {
        $state = strtolower(trim((string) $value));
        return match ($state) {
            'a_implementar', 'a-implementar', 'to_implement', 'pending' => 'to_implement',
            'implementada', 'implemented', 'done' => 'implemented',
            'verificada', 'verified' => 'verified',
            default => 'planned',
        };
    }
}
