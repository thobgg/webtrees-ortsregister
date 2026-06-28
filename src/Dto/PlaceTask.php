<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Eine strukturierte Aufgabe pro Ort.
 *
 * Storage: `media/<root>/<ortsname>/_tasks.json` — JSON-Array von Tasks,
 * keine Datenbank-Tabelle. Datei mit `_` prefix wird von PlaceFolderScanner
 * ignoriert (taucht nicht in „Eigene Digitalisate" auf).
 */
final class PlaceTask
{
    public const STATUS_OPEN = 'open';
    public const STATUS_DONE = 'done';

    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly string $status = self::STATUS_OPEN,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function toggled(): self
    {
        return new self($this->id, $this->text, $this->isOpen() ? self::STATUS_DONE : self::STATUS_OPEN);
    }

    public function withText(string $newText): self
    {
        return new self($this->id, $newText, $this->status);
    }

    /** @return array{id:string, text:string, status:string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'text' => $this->text, 'status' => $this->status];
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            id:     (string) ($raw['id']     ?? ''),
            text:   (string) ($raw['text']   ?? ''),
            status: ((string) ($raw['status'] ?? self::STATUS_OPEN)) === self::STATUS_DONE
                ? self::STATUS_DONE
                : self::STATUS_OPEN,
        );
    }
}
