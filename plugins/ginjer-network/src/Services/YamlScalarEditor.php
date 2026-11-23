<?php

namespace Ginjer\Network\Services;

use Symfony\Component\Yaml\Yaml;

/**
 * Changes single values in a YAML file in place and keeps everything else (comments, order,
 * spacing) exactly as it was. Used for Nodes' config.yml, whose comments are its documentation.
 *
 * Only handles block-style mappings ("key: value" lines, nested by indentation), which is what
 * Bukkit plugin configs use.
 */
final class YamlScalarEditor
{
    /** @var list<string> */
    private array $lines;

    /** @var list<string> keys that weren't found */
    private array $missing = [];

    private string $eol;

    public function __construct(string $content)
    {
        // split on real line ends only: a bare \R also matches byte 0x85, which is inside some UTF-8 characters
        $this->eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $this->lines = explode("\n", str_replace("\r\n", "\n", $content));
    }

    /** Set "a.b.c" to a scalar. A key that isn't in the file is noted in missing(). */
    public function set(string $path, string|int|float|bool|null $value): self
    {
        $line = $this->find(explode('.', $path));
        if ($line === null) {
            $this->missing[] = $path;

            return $this;
        }

        preg_match('/^(\s*[^:#]+:)(\s*)(.*)$/', $this->lines[$line], $m);
        $comment = '';
        if (preg_match('/\s+#.*$/', $m[3], $c) && !str_starts_with(ltrim($m[3]), '"') && !str_starts_with(ltrim($m[3]), "'")) {
            $comment = $c[0];
        }
        $this->lines[$line] = $m[1] . ' ' . Yaml::dump($value) . $comment;

        return $this;
    }

    /** @return list<string> */
    public function missing(): array
    {
        return $this->missing;
    }

    public function content(): string
    {
        return implode($this->eol, $this->lines);
    }

    /** @param  list<string>  $keys */
    private function find(array $keys): ?int
    {
        $start = 0;
        $end = count($this->lines);
        $parentIndent = -1;

        foreach ($keys as $depth => $key) {
            $found = null;
            $childIndent = null;
            for ($i = $start; $i < $end; $i++) {
                $text = $this->lines[$i];
                if (trim($text) === '' || str_starts_with(ltrim($text), '#')) {
                    continue;
                }
                $indent = strlen($text) - strlen(ltrim($text));
                if ($indent <= $parentIndent) {
                    break; // left the parent block
                }
                $childIndent ??= $indent;
                if ($indent !== $childIndent) {
                    continue; // deeper than a direct child
                }
                if (preg_match('/^\s*(["\']?)' . preg_quote($key, '/') . '\1\s*:(\s|$)/', $text)) {
                    $found = $i;
                    $parentIndent = $indent;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }

            $start = $found + 1;
            if ($depth < count($keys) - 1) {
                // the child block ends at the next line indented no deeper than this key
                for ($j = $start; $j < count($this->lines); $j++) {
                    $text = $this->lines[$j];
                    if (trim($text) === '' || str_starts_with(ltrim($text), '#')) {
                        continue;
                    }
                    if (strlen($text) - strlen(ltrim($text)) <= $parentIndent) {
                        break;
                    }
                }
                $end = $j;
            } else {
                return $found;
            }
        }

        return null;
    }
}
