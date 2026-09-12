<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Revision = 'revision';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Menunggu Approval',
            self::Revision => 'Perlu Revisi',
            self::Approved => 'Disetujui',
            self::Scheduled => 'Terjadwal',
            self::Published => 'Dipublikasikan',
            self::Failed => 'Gagal',
        };
    }

    /**
     * CSS custom-property pair [background, text] from admin.css.
     *
     * @return array{0: string, 1: string}
     */
    public function pill(): array
    {
        return match ($this) {
            self::Draft => ['--surface-alt', '--text-secondary'],
            self::PendingApproval => ['--status-review-bg', '--status-review-text'],
            self::Revision => ['--status-failed-bg', '--status-failed-text'],
            self::Approved => ['--status-ready-bg', '--status-ready-text'],
            self::Scheduled => ['--status-scheduled-bg', '--status-scheduled-text'],
            self::Published => ['--status-posted-bg', '--status-posted-text'],
            self::Failed => ['--status-failed-bg', '--status-failed-text'],
        };
    }

    /** Statuses a creator is still allowed to edit / delete. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Revision, self::Failed], true);
    }

    /** Statuses a creator can send into the approval queue. */
    public function canSubmit(): bool
    {
        return in_array($this, [self::Draft, self::Revision], true);
    }

    public static function fromRouteName(?string $name): ?self
    {
        return match ($name) {
            'konten.draft' => self::Draft,
            'konten.approval' => self::PendingApproval,
            'konten.revision' => self::Revision,
            'konten.approved' => self::Approved,
            'konten.scheduled' => self::Scheduled,
            'konten.published' => self::Published,
            'konten.failed' => self::Failed,
            default => null,
        };
    }

    /** Route name for this status' list page. */
    public function route(): string
    {
        return match ($this) {
            self::Draft => 'konten.draft',
            self::PendingApproval => 'konten.approval',
            self::Revision => 'konten.revision',
            self::Approved => 'konten.approved',
            self::Scheduled => 'konten.scheduled',
            self::Published => 'konten.published',
            self::Failed => 'konten.failed',
        };
    }
}
