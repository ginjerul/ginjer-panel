<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use ZipArchive;

/**
 * Checks every plugin on a network's shards for newer versions, and finds shards whose copy of a
 * plugin differs from the others.
 *
 *  - Modrinth: by the jar's SHA-1, so the exact project and version are known
 *  - Hangar (PaperMC) and SpigotMC: by the plugin's name from its plugin.yml (a guess, shown as one)
 *
 * Modrinth and Hangar updates can be installed on every shard at once; SpigotMC only links to the
 * page (its downloads need a browser).
 */
class PluginUpdates
{
    /** Updated with Update shards on the network page instead. */
    public const SKIP = '/^(nodes(?![-_]?map)|ginjermap|reef)/i';

    public const MAX_JAR = 64 * 1024 * 1024;

    public function __construct(private DaemonFileRepository $files, private Artifacts $artifacts, private WingsUploader $uploader) {}

    /**
     * Every plugin jar on the shards: which shards have it, and whether their copies match.
     *
     * @return array<string, array{file: string, enabled: bool, shards: array<string, int>, same: bool}> keyed by the jar's base name
     */
    public function inventory(Network $network): array
    {
        $out = [];
        foreach ($network->loadMissing('members.server')->membersWithRole(MemberRole::Shard) as $member) {
            $entries = rescue(fn () => $this->files->setServer($member->server)->getDirectory('plugins'), [], false);
            foreach ($entries as $e) {
                if (!($e['file'] ?? false) || !preg_match('/^(.+\.jar)(\.disabled)?$/i', $e['name'] ?? '', $m) || preg_match(self::SKIP, $m[1])) {
                    continue;
                }
                $out[$m[1]] ??= ['file' => $m[1], 'enabled' => !isset($m[2]), 'shards' => []];
                $out[$m[1]]['shards'][$member->shard_name ?: $member->server->name] = (int) ($e['size'] ?? 0);
            }
        }
        foreach ($out as &$row) {
            $row['same'] = count(array_unique($row['shards'])) <= 1;
        }
        unset($row);
        ksort($out, SORT_FLAG_CASE | SORT_STRING);

        return $out;
    }

    /** The Minecraft version a server runs, from Paper's version_history.json. */
    public function minecraftVersion(Server $server): ?string
    {
        $json = rescue(fn () => json_decode((string) $this->files->setServer($server)->getContent('version_history.json'), true), null, false);
        if (is_array($json) && preg_match('/MC: ([\d.]+)/', (string) ($json['currentVersion'] ?? ''), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Name, version and SHA-1 of a jar on a server (read from its plugin.yml; cached by size).
     *
     * @return array{name: string, version: string, sha1: string}|null
     */
    public function describe(Server $server, string $file, bool $enabled, int $size): ?array
    {
        return Cache::remember("ginjer-network:jar:{$server->uuid}:$file:$size", now()->addDay(), function () use ($server, $file, $enabled) {
            try {
                $bytes = (string) $this->files->setServer($server)->getContent('plugins/' . $file . ($enabled ? '' : '.disabled'), self::MAX_JAR);
            } catch (Throwable) {
                return null;
            }

            return self::readJar($bytes) + ['sha1' => sha1($bytes)];
        });
    }

    /** @return array{name: string, version: string} */
    public static function readJar(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ginjer-jar');
        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive();
            $yaml = null;
            if ($zip->open($tmp) === true) {
                $yaml = $zip->getFromName('paper-plugin.yml') ?: $zip->getFromName('plugin.yml') ?: null;
                $zip->close();
            }
            $name = $yaml && preg_match('/^name:\s*[\'"]?([^\'"\r\n#]+)/m', $yaml, $n) ? trim($n[1]) : '';
            $version = $yaml && preg_match('/^version:\s*[\'"]?([^\'"\r\n#]+)/m', $yaml, $v) ? trim($v[1]) : '';

            return ['name' => $name, 'version' => $version];
        } finally {
            @unlink($tmp);
        }
    }

    /** Whether $latest is newer than $current, comparing their numbers (1.10.2 > 1.9). Null: can't tell. */
    public static function newer(string $current, string $latest): ?bool
    {
        $num = fn (string $v) => preg_match('/(\d+(?:\.\d+)*)/', $v, $m) ? $m[1] : null;
        [$a, $b] = [$num($current), $num($latest)];
        if ($a === null || $b === null) {
            return $current === $latest ? false : null;
        }

        return version_compare($b, $a, '>');
    }

    /**
     * Every plugin with what's newer.
     *
     * @return list<array{file: string, name: string, version: string, enabled: bool, shards: array<string, int>, same: bool,
     *                    source: ?string, latest: ?string, url: ?string, download: ?string, filename: ?string, status: string}>
     *                    status: current, update, unknown
     */
    public function check(Network $network): array
    {
        $inventory = $this->inventory($network);
        $members = $network->membersWithRole(MemberRole::Shard)->keyBy(fn ($m) => $m->shard_name ?: $m->server->name);
        $leader = $network->leader()?->server ?? $members->first()?->server;
        $mc = $leader ? $this->minecraftVersion($leader) : null;

        // describe each jar from the leader if it has it, else the first shard that does
        $rows = [];
        foreach ($inventory as $file => $item) {
            $from = isset($item['shards'][$network->leader()?->shard_name ?? '']) ? $network->leader()->shard_name : array_key_first($item['shards']);
            $info = $this->describe($members[$from]->server, $file, $item['enabled'], $item['shards'][$from]) ?? ['name' => '', 'version' => '', 'sha1' => ''];
            $rows[$file] = $item + [
                'name' => $info['name'] ?: preg_replace('/([-_ ]v?\d.*)?\.jar$/i', '', $file), 'version' => $info['version'], 'sha1' => $info['sha1'],
                'source' => null, 'latest' => null, 'url' => null, 'download' => null, 'filename' => null, 'status' => 'unknown',
            ];
        }

        $this->fromModrinth($rows, $mc);
        foreach ($rows as &$row) {
            if ($row['source'] === null) {
                rescue(fn () => $this->fromHangar($row), report: false);
            }
            if ($row['source'] === null) {
                rescue(fn () => $this->fromSpigot($row), report: false);
            }
        }
        unset($row);

        return array_values(array_map(function ($row) {
            unset($row['sha1']);

            return $row;
        }, $rows));
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asJson()->timeout(15)->withHeaders(['User-Agent' => 'ginjer-network (plugin update check)']);
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function fromModrinth(array &$rows, ?string $mc): void
    {
        $hashes = array_values(array_filter(array_column($rows, 'sha1')));
        if ($hashes === []) {
            return;
        }
        $current = rescue(fn () => $this->http()->post('https://api.modrinth.com/v2/version_files', ['hashes' => $hashes, 'algorithm' => 'sha1'])->throw()->json(), [], false);
        $body = ['hashes' => array_keys($current), 'algorithm' => 'sha1', 'loaders' => ['paper', 'spigot', 'bukkit', 'purpur', 'folia']];
        if ($mc) {
            $body['game_versions'] = [$mc];
        }
        $latest = $current === [] ? [] : rescue(fn () => $this->http()->post('https://api.modrinth.com/v2/version_files/update', $body)->throw()->json(), [], false);

        foreach ($rows as &$row) {
            $now = $current[$row['sha1']] ?? null;
            if ($now === null) {
                continue;
            }
            $new = $latest[$row['sha1']] ?? null;
            $file = $new ? (collect($new['files'] ?? [])->firstWhere('primary', true) ?? ($new['files'][0] ?? null)) : null;
            $row['source'] = 'Modrinth';
            $row['version'] = $row['version'] ?: (string) ($now['version_number'] ?? '');
            $row['url'] = 'https://modrinth.com/project/' . $now['project_id'];
            if ($new === null || ($new['id'] ?? null) === ($now['id'] ?? null)) {
                $row['latest'] = (string) ($now['version_number'] ?? $row['version']);
                $row['status'] = 'current';
            } else {
                $row['latest'] = (string) ($new['version_number'] ?? '');
                $row['status'] = 'update';
                $row['download'] = $file['url'] ?? null;
                $row['filename'] = $file['filename'] ?? null;
            }
        }
        unset($row);
    }

    /** @param array<string, mixed> $row */
    private function fromHangar(array &$row): void
    {
        $found = $this->http()->get('https://hangar.papermc.io/api/v1/projects', ['q' => $row['name'], 'limit' => 5])->throw()->json('result') ?? [];
        $project = collect($found)->first(fn ($p) => strcasecmp((string) ($p['name'] ?? ''), $row['name']) === 0);
        if ($project === null) {
            return;
        }
        $slug = $project['namespace']['slug'] ?? $project['name'];
        $latest = trim($this->http()->get("https://hangar.papermc.io/api/v1/projects/$slug/latestrelease")->throw()->body());
        $row['source'] = 'Hangar';
        $row['url'] = "https://hangar.papermc.io/{$project['namespace']['owner']}/$slug";
        $row['latest'] = $latest;
        $newer = self::newer($row['version'], $latest);
        $row['status'] = $newer === null ? 'unknown' : ($newer ? 'update' : 'current');
        if ($newer) {
            $row['download'] = "https://hangar.papermc.io/api/v1/projects/$slug/versions/" . rawurlencode($latest) . '/PAPER/download';
            $row['filename'] = preg_replace('/[^\w.+-]/', '', $row['name']) . '-' . preg_replace('/[^\w.+-]/', '', $latest) . '.jar';
        }
    }

    /** @param array<string, mixed> $row */
    private function fromSpigot(array &$row): void
    {
        $found = $this->http()->get('https://api.spiget.org/v2/search/resources/' . rawurlencode($row['name']), ['field' => 'name', 'size' => 5])->throw()->json() ?? [];
        $resource = collect($found)->first(fn ($r) => strcasecmp(trim(preg_replace('/[^\w ]+.*$/', '', (string) ($r['name'] ?? ''))), $row['name']) === 0);
        if ($resource === null) {
            return;
        }
        $latest = (string) ($this->http()->get("https://api.spiget.org/v2/resources/{$resource['id']}/versions/latest")->throw()->json('name') ?? '');
        $row['source'] = 'SpigotMC';
        $row['url'] = "https://www.spigotmc.org/resources/{$resource['id']}/";
        $row['latest'] = $latest;
        $newer = self::newer($row['version'], $latest);
        $row['status'] = $newer === null ? 'unknown' : ($newer ? 'update' : 'current');
    }

    /**
     * Installs a new version on every shard that has the plugin, replacing the old jar (kept as
     * .ginjer-old). It loads on the next restart.
     *
     * @param  array{file: string, enabled: bool, shards: array<string, int>, download: string, filename: string}  $row
     * @return array{done: list<string>, failed: list<string>}
     */
    public function install(Network $network, array $row, User $user): array
    {
        if (!preg_match('/^[\w.+-]+\.jar$/', (string) $row['filename'])) {
            throw new \RuntimeException('That download has an odd file name: ' . $row['filename']);
        }
        $local = $this->artifacts->download($row['download']);
        $done = [];
        $failed = [];
        try {
            $members = $network->loadMissing('members.server')->membersWithRole(MemberRole::Shard)->keyBy(fn ($m) => $m->shard_name ?: $m->server->name);
            foreach (array_keys($row['shards']) as $shard) {
                $server = $members[$shard]->server ?? null;
                if ($server === null) {
                    continue;
                }
                try {
                    $old = $row['file'] . ($row['enabled'] ? '' : '.disabled');
                    $new = $row['filename'] . ($row['enabled'] ? '' : '.disabled');
                    $this->uploader->upload($server, $user, $local, '/plugins', $new . '.ginjer-new');
                    if ($old !== $new) {
                        $this->files->setServer($server)->renameFiles('/plugins', [['from' => $old, 'to' => $old . '.ginjer-old']]);
                    } else {
                        $this->files->setServer($server)->deleteFiles('/plugins', [$old]);
                    }
                    $this->files->setServer($server)->renameFiles('/plugins', [['from' => $new . '.ginjer-new', 'to' => $new]]);
                    $done[] = $shard;
                } catch (Throwable $exception) {
                    $failed[] = "$shard: {$exception->getMessage()}";
                }
            }
        } finally {
            @unlink($local);
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
