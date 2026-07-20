<?php

namespace App\Services;

use App\Models\LoginProtection;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class LoginProtectionService
{
    public const CAPTCHA_AFTER = 5;

    public const LOCK_AFTER = 10;

    public const BASE_LOCK_MINUTES = 30;

    public const LOCK_STEP_MINUTES = 15;

    public function forIp(string $ip): LoginProtection
    {
        return LoginProtection::query()->firstOrCreate(
            ['ip' => $ip],
            ['attempts' => 0, 'lockout_count' => 0, 'locked_until' => null]
        );
    }

    public function status(string $ip): array
    {
        $row = $this->forIp($ip);
        $this->expireLockIfNeeded($row);

        $lockedUntil = $row->locked_until;
        $remaining = $this->remainingSeconds($lockedUntil);
        $locked = $remaining > 0;

        return [
            'attempts' => (int) $row->attempts,
            'captcha_required' => ! $locked && (int) $row->attempts >= self::CAPTCHA_AFTER,
            'locked' => $locked,
            'locked_until' => $lockedUntil?->toIso8601String(),
            'remaining_seconds' => $remaining,
            'lock_duration_minutes' => $locked
                ? $this->lockDurationMinutes(max(0, (int) $row->lockout_count - 1))
                : $this->lockDurationMinutes((int) $row->lockout_count),
            'lockout_count' => (int) $row->lockout_count,
        ];
    }

    public function isLocked(string $ip): bool
    {
        return $this->status($ip)['locked'] === true;
    }

    public function requiresCaptcha(string $ip): bool
    {
        return $this->status($ip)['captcha_required'] === true;
    }

    public function lockDurationMinutes(int $lockoutCount): int
    {
        return self::BASE_LOCK_MINUTES + max(0, $lockoutCount) * self::LOCK_STEP_MINUTES;
    }

    /**
     * Record a failed password attempt. Returns updated status.
     */
    public function recordFailedPassword(string $ip): array
    {
        $row = $this->forIp($ip);
        $this->expireLockIfNeeded($row);

        if ($this->remainingSeconds($row->locked_until) > 0) {
            return $this->status($ip);
        }

        $row->attempts = (int) $row->attempts + 1;

        if ($row->attempts >= self::LOCK_AFTER) {
            $minutes = $this->lockDurationMinutes((int) $row->lockout_count);
            $row->locked_until = now()->addMinutes($minutes);
            $row->lockout_count = (int) $row->lockout_count + 1;
            $row->attempts = 0;
        }

        $row->save();

        return $this->status($ip);
    }

    public function clear(string $ip): void
    {
        LoginProtection::query()->where('ip', $ip)->delete();
    }

    public function clearAll(): void
    {
        LoginProtection::query()->delete();
    }

    protected function expireLockIfNeeded(LoginProtection $row): void
    {
        if ($row->locked_until && $row->locked_until->isPast()) {
            $row->locked_until = null;
            $row->save();
        }
    }

    protected function remainingSeconds(?CarbonInterface $lockedUntil): int
    {
        if (! $lockedUntil || $lockedUntil->isPast()) {
            return 0;
        }

        return max(0, (int) ceil(Carbon::now()->diffInSeconds($lockedUntil)));
    }
}
