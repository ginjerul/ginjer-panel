<?php

namespace Ginjer\Network\Services\Optimizer;

/** Reads and changes key=value lines (server.properties) and keeps every other line as it was. */
final class PropertiesEditor
{
    /** @var list<string> */
    private array $lines;

    private string $eol;

    public function __construct(string $content)
    {
        $this->eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $this->lines = explode("\n", str_replace("\r\n", "\n", $content));
    }

    public function get(string $key): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*[=:]\s*(.*)$/', $line, $m)) {
                return rtrim($m[1]);
            }
        }

        return null;
    }

    /** Returns false when the key isn't in the file (it is not added). */
    public function set(string $key, string $value): bool
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match('/^(\s*' . preg_quote($key, '/') . '\s*[=:]\s*)/', $line, $m)) {
                $this->lines[$i] = $m[1] . $value;

                return true;
            }
        }

        return false;
    }

    public function content(): string
    {
        return implode($this->eol, $this->lines);
    }
}
