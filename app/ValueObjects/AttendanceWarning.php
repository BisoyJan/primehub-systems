<?php

namespace App\ValueObjects;

use Carbon\Carbon;

/**
 * Represents a structured attendance warning attached to an Attendance record.
 *
 * Stored as a JSON array element in `attendance.warnings`. Supports backward
 * compatibility with legacy plain-string warnings produced before 2026-05-07.
 */
readonly class AttendanceWarning
{
    public function __construct(
        /** Machine-readable warning category (e.g. 'double_punch', 'early_time_in'). */
        public string $type,
        /** Human-readable message shown in admin UI. */
        public string $message,
        /** 'info' | 'warning' | 'critical' */
        public string $severity,
        /** ISO-8601 timestamp of when the warning was raised. */
        public string $raised_at,
    ) {}

    /** Create a new warning stamped with the current time. */
    public static function make(string $type, string $message, string $severity = 'warning'): self
    {
        return new self($type, $message, $severity, Carbon::now()->toISOString());
    }

    /**
     * Reconstruct from a raw DB value — handles both structured arrays and
     * legacy plain strings from records created before this VO was introduced.
     */
    public static function fromRaw(mixed $raw): self
    {
        if (is_string($raw)) {
            return new self('legacy', $raw, 'warning', '');
        }

        return new self(
            $raw['type'] ?? 'legacy',
            $raw['message'] ?? (string) $raw,
            $raw['severity'] ?? 'warning',
            $raw['raised_at'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'severity' => $this->severity,
            'raised_at' => $this->raised_at,
        ];
    }
}
