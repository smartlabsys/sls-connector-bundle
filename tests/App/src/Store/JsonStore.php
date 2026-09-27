<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Store;

/** A tiny JSON-file database: `update()` runs a read-modify-write under an exclusive lock. */
final class JsonStore
{
    public function __construct(private string $file) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $handle = fopen($this->file, 'r');
        flock($handle, LOCK_SH);
        $data = json_decode((string) stream_get_contents($handle), true) ?: [];
        flock($handle, LOCK_UN);
        fclose($handle);

        return $data;
    }

    /**
     * @template T
     *
     * @param callable(array<string, mixed>&): T $change
     *
     * @return T
     */
    public function update(callable $change): mixed
    {
        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0o777, true);
        }
        $handle = fopen($this->file, 'c+');
        flock($handle, LOCK_EX);
        $data   = json_decode((string) stream_get_contents($handle), true) ?: [];
        $result = $change($data);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $result;
    }

    public function reset(): void
    {
        @unlink($this->file);
    }

    public static function id(): string
    {
        return bin2hex(random_bytes(8));
    }
}
