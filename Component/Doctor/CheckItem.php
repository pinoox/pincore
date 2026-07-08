<?php

declare(strict_types=1);

namespace Pinoox\Component\Doctor;

final class CheckItem
{
    public function __construct(
        public readonly string $group,
        public readonly string $id,
        public readonly string $label,
        public readonly CheckStatus $status,
        public readonly string $detail = '',
        public readonly ?string $hint = null,
        public readonly bool $scored = true,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'hint' => $this->hint,
        ];
    }
}
