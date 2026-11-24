<?php

namespace Ginjer\Network\Services\Rebalance;

/**
 * Minecraft's region files (r.X.Z.mca, 32x32 chunks): an 8 KiB header of chunk locations and
 * timestamps, then each chunk as "length, compression, data" padded to 4 KiB sectors. Chunks too
 * big for the file live in c.X.Z.mcc next to it (compression byte + 128).
 */
final class Anvil
{
    public const SECTOR = 4096;

    /** Index of a chunk inside its region file. */
    public static function index(int $chunkX, int $chunkZ): int
    {
        return ($chunkX & 31) + ($chunkZ & 31) * 32;
    }

    /** @return array{0: int, 1: int} region x, z of a region file name, or throws */
    public static function regionOf(string $file): array
    {
        if (!preg_match('/^r\.(-?\d+)\.(-?\d+)\.mca$/', $file, $m)) {
            throw new \InvalidArgumentException("Not a region file: $file");
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @return array<int, array{payload: string, time: int, external: bool}> chunk index => its stored bytes (length + compression + data)
     */
    public static function read(string $bytes): array
    {
        $chunks = [];
        if (strlen($bytes) < 2 * self::SECTOR) {
            return $chunks;
        }
        for ($i = 0; $i < 1024; $i++) {
            $loc = unpack('N', substr($bytes, $i * 4, 4))[1];
            $sector = $loc >> 8;
            if ($sector < 2) {
                continue;
            }
            $start = $sector * self::SECTOR;
            if ($start + 5 > strlen($bytes)) {
                continue; // a broken entry: Minecraft would regenerate it too
            }
            $length = unpack('N', substr($bytes, $start, 4))[1];
            if ($length < 1 || $start + 4 + $length > strlen($bytes)) {
                continue;
            }
            $payload = substr($bytes, $start, 4 + $length);
            $chunks[$i] = [
                'payload' => $payload,
                'time' => unpack('N', substr($bytes, self::SECTOR + $i * 4, 4))[1],
                'external' => (ord($payload[4]) & 0x80) !== 0,
            ];
        }

        return $chunks;
    }

    /** @param  array<int, array{payload: string, time: int}>  $chunks */
    public static function write(array $chunks): string
    {
        ksort($chunks);
        $locations = array_fill(0, 1024, 0);
        $times = array_fill(0, 1024, 0);
        $body = '';
        $sector = 2;
        foreach ($chunks as $i => $chunk) {
            $count = (int) ceil(strlen($chunk['payload']) / self::SECTOR);
            if ($count > 255) {
                throw new \RuntimeException("Chunk $i is too big for a region file.");
            }
            $locations[$i] = ($sector << 8) | $count;
            $times[$i] = $chunk['time'];
            $body .= str_pad($chunk['payload'], $count * self::SECTOR, "\0");
            $sector += $count;
        }

        return pack('N*', ...$locations) . pack('N*', ...$times) . $body;
    }
}
