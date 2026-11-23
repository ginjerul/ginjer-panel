<?php

namespace Ginjer\Network\Services;

/**
 * Edits Velocity's velocity.toml in place: replaces the server list and the "try" order in the
 * [servers] table, drops [forced-hosts] entries that point at servers no longer in the list
 * (Velocity refuses to start with those), and sets top-level keys. Every other line (comments,
 * other tables) stays as it was. Only handles the layout Velocity writes itself.
 */
final class VelocityConfig
{
    /**
     * @param  array<string, string>  $servers  name => "host:port"
     * @param  list<string>  $try  servers players join first, in order
     */
    public static function setServers(string $toml, array $servers, array $try): string
    {
        $eol = str_contains($toml, "\r\n") ? "\r\n" : "\n";
        $lines = explode("\n", str_replace("\r\n", "\n", $toml));

        $entries = [];
        foreach ($servers as $name => $address) {
            $entries[] = self::key($name) . ' = ' . self::string($address);
        }
        $tryLine = 'try = [' . implode(', ', array_map([self::class, 'string'], $try)) . ']';

        [$start, $end] = self::table($lines, 'servers');
        if ($start === null) {
            // no [servers] table yet: add one at the end
            $lines = array_merge(self::trimEnd($lines), ['', '[servers]'], $entries, [$tryLine, '']);
        } else {
            $out = [];
            $serversDone = false;
            $tryDone = false;
            $skipArray = false;
            for ($i = $start + 1; $i < $end; $i++) {
                $line = $lines[$i];
                if ($skipArray) {
                    $skipArray = !str_contains($line, ']');

                    continue;
                }
                if (preg_match('/^\s*("[^"]*"|[A-Za-z0-9_-]+)\s*=/', $line, $m)) {
                    $skipArray = str_contains($line, '[') && !str_contains($line, ']');
                    if (!$serversDone) {
                        array_push($out, ...$entries); // the list goes where the old one started
                        $serversDone = true;
                    }
                    if (trim($m[1], '"') === 'try') {
                        $out[] = $tryLine;
                        $tryDone = true;
                    }

                    continue;
                }
                $out[] = $line;
            }
            // anything the table didn't have goes before the blank lines that end it
            $trailing = [];
            while ($out !== [] && trim(end($out)) === '') {
                array_unshift($trailing, array_pop($out));
            }
            if (!$serversDone) {
                array_push($out, ...$entries);
            }
            if (!$tryDone) {
                $out[] = $tryLine;
            }
            if ($trailing === [] && $end < count($lines)) {
                $trailing = [''];
            }
            $lines = array_merge(array_slice($lines, 0, $start + 1), $out, $trailing, array_slice($lines, $end));
        }

        return implode($eol, self::pruneForcedHosts($lines, array_keys($servers)));
    }

    /** Sets a top-level key (before the first table). Returns the file unchanged if the key isn't there. */
    public static function setTopLevel(string $toml, string $key, string $value): string
    {
        $lines = explode("\n", $toml);
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*\[/', $line)) {
                break;
            }
            if (preg_match('/^(\s*' . preg_quote($key, '/') . '\s*=\s*)("[^"]*"|\S+)(.*)$/', $line, $m)) {
                $lines[$i] = $m[1] . self::string($value) . $m[3];

                return implode("\n", $lines);
            }
        }

        return $toml;
    }

    /**
     * Drops forced hosts that point at a server not in $names (Velocity won't start otherwise).
     *
     * @param  list<string>  $lines
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function pruneForcedHosts(array $lines, array $names): array
    {
        [$start, $end] = self::table($lines, 'forced-hosts');
        if ($start === null) {
            return $lines;
        }
        $out = [];
        for ($i = $start + 1; $i < $end; $i++) {
            $line = $lines[$i];
            if (!preg_match('/^\s*("[^"]*"|[A-Za-z0-9_.-]+)\s*=/', $line)) {
                $out[] = $line;

                continue;
            }
            // collect the whole entry (the list may span lines)
            $entry = [$line];
            while (!str_contains(implode('', $entry), ']') && $i + 1 < $end) {
                $entry[] = $lines[++$i];
            }
            preg_match_all('/"([^"]*)"/', substr(implode("\n", $entry), strpos(implode("\n", $entry), '=')), $m);
            if (array_diff($m[1], $names) === []) {
                array_push($out, ...$entry);
            }
        }

        return array_merge(array_slice($lines, 0, $start + 1), $out, array_slice($lines, $end));
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: ?int, 1: int} the header line and the line after the table
     */
    private static function table(array $lines, string $name): array
    {
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*\[' . preg_quote($name, '/') . '\]\s*(#.*)?$/', $line)) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return [null, count($lines)];
        }
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (preg_match('/^\s*\[/', $lines[$i])) {
                return [$start, $i];
            }
        }

        return [$start, count($lines)];
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function trimEnd(array $lines): array
    {
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    private static function key(string $name): string
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $name) ? $name : self::string($name);
    }

    private static function string(string $value): string
    {
        return '"' . addcslashes($value, "\"\\") . '"';
    }
}
