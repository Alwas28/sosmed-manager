<?php

namespace App\Services\Buffer;

/**
 * Reconciles local post status with Buffer (Blueprint §11):
 * scheduled / published / failed / cancelled.
 *
 * Placeholder — to be implemented alongside `publish_jobs` in Modul Konten,
 * either via a scheduled polling command (BufferService::findUpdate) or a
 * Buffer webhook endpoint if the account supports it.
 */
class BufferWebhookService
{
    public function __construct(private BufferService $buffer) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        throw new BufferException('Sinkronisasi status Buffer belum diimplementasikan.');
    }
}
