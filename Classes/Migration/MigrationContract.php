<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Migration;

use RuntimeException;

final class MigrationContract
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 2) . '/Resources/Private/Migration/contract-v1.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read the forum migration contract.');
        }
        $this->data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (($this->data['format_version'] ?? null) !== '2.0' || ($this->data['rule_version'] ?? '') === '') {
            throw new RuntimeException('Unsupported forum migration contract.');
        }
    }

    public function version(): string
    {
        return (string)$this->data['rule_version'];
    }

    /** @return array<string, string> */
    public function standardPlugins(): array
    {
        return $this->data['standard_plugins'];
    }

    /** @return array<string, array<string, mixed>> */
    public function legacyPi1Rules(): array
    {
        return $this->data['legacy_pi1'];
    }

    /** @return list<string> */
    public function contentFields(): array
    {
        return $this->data['content_fields'];
    }

    /** @return list<string> */
    public function contentIntegerFields(): array
    {
        return $this->data['content_integer_fields'];
    }

    /** @return list<string> */
    public function integrityTables(): array
    {
        return $this->data['integrity_tables'];
    }

    /** @return array<string, array{fields:list<string>, integer_fields:list<string>}> */
    public function integrityProjections(): array
    {
        return $this->data['integrity_projections'];
    }

    /** @return list<array{string, string, string, string, bool}> */
    public function relations(): array
    {
        return $this->data['relations'];
    }

    /** @return list<string> */
    public function legacySignatures(): array
    {
        return $this->data['legacy_signatures'];
    }
}
