<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * A global speaker in a stitched transcript. `label` is anonymous
 * ("Speaker 2"); `name` is empty until the editor names the speaker.
 */
final class Speaker
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $name = '',
    ) {
    }

    public function display(): string
    {
        return $this->name !== '' ? $this->name : $this->label;
    }

    public function withName(string $name): self
    {
        return new self($this->id, $this->label, $name);
    }

    /** @return array{id: string, label: string, name: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'name' => $this->name];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = is_string($data['id'] ?? null) ? $data['id'] : '';
        $label = is_string($data['label'] ?? null) ? $data['label'] : $id;

        return new self($id, $label, is_string($data['name'] ?? null) ? $data['name'] : '');
    }
}
