<?php

namespace Emaia\MediaMan\Console\Concerns;

/**
 * Section headers and icon-prefixed status lines for mediaman console commands.
 * Layout matches Laravel's `about` command (section + twoColumnDetail rows).
 *
 * Consumers can read $hasErrors to drive their exit code (DoctorCommand does);
 * commands that don't care can ignore the property.
 */
trait CommandOutputStyle
{
    protected bool $hasErrors = false;

    protected function section(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('  <fg=green;options=bold>'.$title.'</>');
    }

    /**
     * Levels: ok (✓ green), warn (⚠ yellow), error (✗ red), info (· dim).
     */
    protected function statusLine(string $label, string $level, string $value): void
    {
        $icon = match ($level) {
            'ok' => '<fg=green>✓</>',
            'warn' => '<fg=yellow>⚠</>',
            'error' => '<fg=red>✗</>',
            default => '<fg=gray>·</>',
        };

        if ($level === 'error') {
            $this->hasErrors = true;
        }

        $this->components->twoColumnDetail($label, "{$icon} {$value}");
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return sprintf('%.1f %s', $value, $units[$unitIndex]);
    }
}
