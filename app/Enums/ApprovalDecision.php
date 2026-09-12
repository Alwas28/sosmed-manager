<?php

namespace App\Enums;

enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Revision = 'revision';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Disetujui',
            self::Revision => 'Minta Revisi',
        };
    }

    /** @return array{0: string, 1: string} CSS custom-property pair from admin.css */
    public function pill(): array
    {
        return match ($this) {
            self::Approved => ['--status-ready-bg', '--status-ready-text'],
            self::Revision => ['--status-failed-bg', '--status-failed-text'],
        };
    }
}
