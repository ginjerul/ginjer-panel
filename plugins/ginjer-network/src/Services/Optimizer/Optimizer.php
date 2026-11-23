<?php

namespace Ginjer\Network\Services\Optimizer;

use Ginjer\Network\Services\YamlScalarEditor;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The optimizer's logic, on plain file contents (no panel, no Wings), so it can be tested on real
 * config files: compare each rule with what a server has, and produce the changed files.
 */
final class Optimizer
{
    public const CHANGE = 'change';

    public const OK = 'ok';

    public const SKIP = 'skip';

    public const MISSING = 'missing';

    /**
     * @param  array<string, ?string>  $files  path => content (null: the file doesn't exist)
     * @return list<array{id: string, title: string, file: string, key: string, reason: string, gameplay: bool, current: ?string, recommended: ?string, status: string, note: ?string}>
     */
    public static function analyze(array $files, string $startup, Context $context, string $profile, bool $canChangeStartup = true): array
    {
        $parsed = [];
        $rows = [];
        foreach (Rules::all() as $rule) {
            $recommended = $rule->valueFor($profile, $context);
            $row = [
                'id' => $rule->id, 'title' => $rule->title, 'file' => $rule->file, 'key' => $rule->key, 'reason' => $rule->reason,
                'gameplay' => $rule->gameplay, 'current' => null, 'recommended' => $recommended === null ? null : self::show($recommended),
                'status' => self::OK, 'note' => null,
            ];

            if ($recommended === null) {
                continue; // this profile leaves the setting alone
            }
            if (($skip = $rule->skipReason($context)) !== null) {
                $rows[] = ['status' => self::SKIP, 'note' => $skip] + $row;

                continue;
            }

            if ($rule->format === 'startup') {
                if (!$canChangeStartup) {
                    $rows[] = ['status' => self::SKIP, 'note' => 'Changing the startup command needs the "startup" permission on this server.'] + $row;

                    continue;
                }
                $current = JvmFlags::extract($startup);
                if ($current === null) {
                    $rows[] = ['status' => self::SKIP, 'note' => 'The startup command isn\'t "java ... -jar ...", so it\'s left alone.', 'current' => $startup] + $row;

                    continue;
                }
                $recommended = JvmFlags::merge($current, (string) $recommended);
                $rows[] = ['current' => $current, 'recommended' => $recommended, 'status' => self::same($current, $recommended) ? self::OK : self::CHANGE] + $row;

                continue;
            }

            $content = $files[$rule->file] ?? null;
            if ($content === null) {
                $rows[] = ['status' => self::MISSING, 'note' => "No {$rule->file} yet. Start the server once so it's created."] + $row;

                continue;
            }

            if ($rule->format === 'properties') {
                $current = (new PropertiesEditor($content))->get($rule->key);
            } else {
                $parsed[$rule->file] ??= self::parse($content);
                $current = self::dig($parsed[$rule->file], $rule->key);
                $current = $current === null ? null : self::show($current);
            }
            if ($current === null) {
                $rows[] = ['status' => self::MISSING, 'note' => "{$rule->key} isn't in {$rule->file} (a different version?), so it's left alone."] + $row;

                continue;
            }

            $rows[] = ['current' => $current, 'status' => self::same($current, $recommended) ? self::OK : self::CHANGE] + $row;
        }

        return $rows;
    }

    /**
     * The files and startup command with the chosen rules applied. Only rows with status "change"
     * are applied; files that end up unchanged are left out of the result.
     *
     * @param  array<string, ?string>  $files
     * @param  list<string>  $ids  rules to apply
     * @return array{files: array<string, string>, startup: ?string, applied: list<string>}
     */
    public static function apply(array $files, string $startup, Context $context, string $profile, array $ids, bool $canChangeStartup = true): array
    {
        $rules = collect(Rules::all())->keyBy('id');
        $rows = collect(self::analyze($files, $startup, $context, $profile, $canChangeStartup))
            ->filter(fn ($row) => $row['status'] === self::CHANGE && in_array($row['id'], $ids, true));

        $editors = [];
        $newStartup = null;
        $applied = [];
        foreach ($rows as $row) {
            $rule = $rules[$row['id']];
            $value = $rule->valueFor($profile, $context);

            if ($rule->format === 'startup') {
                $newStartup = JvmFlags::replace($startup, JvmFlags::merge((string) JvmFlags::extract($startup), (string) $value));
                $applied[] = $rule->id;

                continue;
            }

            $editors[$rule->file] ??= $rule->format === 'properties' ? new PropertiesEditor($files[$rule->file]) : new YamlScalarEditor($files[$rule->file]);
            $editor = $editors[$rule->file];
            if ($editor instanceof PropertiesEditor) {
                if ($editor->set($rule->key, self::show($value))) {
                    $applied[] = $rule->id;
                }
            } else {
                $before = count($editor->missing());
                $editor->set($rule->key, $value);
                if (count($editor->missing()) === $before) {
                    $applied[] = $rule->id;
                }
            }
        }

        $out = [];
        foreach ($editors as $path => $editor) {
            if ($editor->content() !== $files[$path]) {
                $out[$path] = $editor->content();
            }
        }

        return ['files' => $out, 'startup' => $newStartup, 'applied' => $applied];
    }

    /** @return list<string> every file the rules read */
    public static function files(): array
    {
        return collect(Rules::all())->where('format', '!=', 'startup')->pluck('file')->unique()->values()->all();
    }

    /** @return array<mixed> */
    private static function parse(string $content): array
    {
        try {
            $data = Yaml::parse($content);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /** @param  array<mixed>  $data */
    private static function dig(array $data, string $key): mixed
    {
        foreach (explode('.', $key) as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return null;
            }
            $data = $data[$part];
        }

        return is_array($data) ? null : $data;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') . (floor($value) == $value ? '.0' : ''),
            default => (string) $value,
        };
    }

    private static function same(string $current, mixed $recommended): bool
    {
        $want = self::show($recommended);
        if (is_numeric($current) && is_numeric($want)) {
            return (float) $current === (float) $want;
        }

        return strcasecmp(trim($current, " \"'"), $want) === 0;
    }
}
