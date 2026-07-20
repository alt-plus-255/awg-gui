<?php

namespace App\Services\Resolver;

class ResolverFileHelper
{
    /** Write file only when content hash differs. Returns true if written. */
    public function writeFileIfChanged(string $path, string $contents): bool
    {
        if (is_file($path)) {
            $existing = (string) file_get_contents($path);
            if (hash_equals(hash('sha256', $existing), hash('sha256', $contents))) {
                return false;
            }
        }
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);

        return true;
    }

    /** Atomic write (temp + rename); returns true if content changed. */
    public function atomicWriteIfChanged(string $path, string $contents): bool
    {
        if (is_file($path)) {
            $existing = (string) file_get_contents($path);
            if (hash_equals(hash('sha256', $existing), hash('sha256', $contents))) {
                return false;
            }
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $path.'.tmp.'.getmypid();
        file_put_contents($tmp, $contents);
        rename($tmp, $path);

        return true;
    }

    /** Write shell helper and chmod +x when content changed. */
    public function writeExecutable(string $path, string $body): bool
    {
        $changed = $this->writeFileIfChanged($path, $body);
        if ($changed || ! is_executable($path)) {
            @chmod($path, 0755);
        }

        return $changed;
    }
}
