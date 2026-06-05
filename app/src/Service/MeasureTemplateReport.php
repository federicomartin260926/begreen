<?php

namespace App\Service;

use JsonSerializable;

final class MeasureTemplateReport implements JsonSerializable
{
    private string $status = 'OK';
    private array $warnings = [];
    private array $errors = [];
    private array $rows = [];
    private array $headers = [];
    private ?string $sheetName = null;
    private ?string $dimension = null;
    private array $importSummary = [];

    public function setSheetName(string $sheetName): void
    {
        $this->sheetName = $sheetName;
    }

    public function setDimension(?string $dimension): void
    {
        $this->dimension = $dimension;
    }

    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    public function addRow(array $row): void
    {
        $this->rows[] = $row;
    }

    public function setImportSummary(array $summary): void
    {
        $this->importSummary = $summary;
    }

    public function addWarning(string $code, string $message, array $context = []): void
    {
        $this->warnings[] = compact('code', 'message', 'context');
    }

    public function addError(string $code, string $message, array $context = []): void
    {
        $this->errors[] = compact('code', 'message', 'context');
    }

    public function finalize(): void
    {
        if ($this->errors !== []) {
            $this->status = 'FAILED';
            return;
        }

        $this->status = $this->warnings !== [] ? 'WARNING' : 'OK';
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getRows(): array
    {
        return $this->rows;
    }

    public function getImportSummary(): array
    {
        return $this->importSummary;
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'rows' => $this->rows,
            'headers' => $this->headers,
            'sheetName' => $this->sheetName,
            'dimension' => $this->dimension,
            'importSummary' => $this->importSummary,
        ];
    }
}
