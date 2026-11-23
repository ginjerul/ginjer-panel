<?php

/**
 * Checks for the velocity.toml editor on Velocity's own default config (fixtures/velocity.toml,
 * from Velocity's default-velocity.toml). If python3 is there, the result is also parsed with a
 * real TOML parser.
 *
 *   php tests/velocity-test.php
 */

require __DIR__ . '/../src/Services/VelocityConfig.php';

use Ginjer\Network\Services\VelocityConfig;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

/** @return ?array<mixed> the file parsed by Python's tomllib, or null if python3 isn't there */
function toml(string $content): ?array
{
    if (trim((string) shell_exec('command -v python3')) === '') {
        return null;
    }
    $file = tempnam(sys_get_temp_dir(), 'toml');
    file_put_contents($file, $content);
    $json = shell_exec('python3 -c ' . escapeshellarg('import json,sys,tomllib; print(json.dumps(tomllib.load(open(sys.argv[1],"rb"))))') . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);
    $data = json_decode((string) $json, true);

    return is_array($data) ? $data : ['__error' => $json];
}

$original = file_get_contents(__DIR__ . '/fixtures/velocity.toml');
$servers = ['americas' => '172.18.0.1:25571', 'europe' => '172.18.0.1:25570', 'asia' => '10.0.0.5:25565'];
$out = VelocityConfig::setServers($original, $servers, ['europe', 'americas', 'asia']);
$out = VelocityConfig::setTopLevel($out, 'player-info-forwarding-mode', 'modern');

check('the sample servers are gone', !str_contains($out, 'lobby = ') && !str_contains($out, '30066'));
check('our servers are listed', str_contains($out, 'americas = "172.18.0.1:25571"') && str_contains($out, 'asia = "10.0.0.5:25565"'));
check('try order set', str_contains($out, 'try = ["europe", "americas", "asia"]'));
check('the comment above the list is kept', str_contains($out, "# represents the IP address of the server to connect to.\namericas"));
check('the comment above try is kept', str_contains($out, "# In what order we should try servers when a player logs in or is kicked from a server.\ntry = "));
check('forced hosts for the sample servers are dropped (Velocity won\'t start otherwise)', !str_contains($out, 'lobby.example.com') && !str_contains($out, 'minigames.example.com'));
check('the forced-hosts table and its comment stay', str_contains($out, "[forced-hosts]\n# Configure your forced hosts here."));
check('forwarding set to modern', str_contains($out, 'player-info-forwarding-mode = "modern"'));
check('other settings untouched', str_contains($out, 'forwarding-secret-file = "forwarding.secret"') && str_contains($out, '[advanced]') && str_contains($out, '[query]'));
check('same number of comment lines', substr_count($out, "\n#") === substr_count($original, "\n#"));
check('running it again changes nothing', VelocityConfig::setTopLevel(VelocityConfig::setServers($out, $servers, ['europe', 'americas', 'asia']), 'player-info-forwarding-mode', 'modern') === $out);

$kept = VelocityConfig::setServers(str_replace('"lobby.example.com" = [', '"play.example.com" = [' . "\n    \"europe\"\n]\n\"lobby.example.com\" = [", $original), $servers, ['europe']);
check('a forced host pointing at one of our servers is kept', str_contains($kept, '"play.example.com"') && !str_contains($kept, 'lobby.example.com'));
$fresh = VelocityConfig::setServers("bind = \"0.0.0.0:25577\"\n", ['a' => 'h:1'], ['a']);
check('a file without [servers] gets one', str_contains($fresh, "[servers]\na = \"h:1\"\ntry = [\"a\"]"));
check('quoted names when needed', str_contains(VelocityConfig::setServers($original, ['shard one' => 'h:1'], ['shard one']), '"shard one" = "h:1"'));

$parsed = toml($out);
if ($parsed === null) {
    echo "SKIP real TOML parse (no python3)\n";
} else {
    check('a real TOML parser reads it', !isset($parsed['__error']), (string) ($parsed['__error'] ?? ''));
    check('parsed: servers and try', ($parsed['servers'] ?? null) === $servers + ['try' => ['europe', 'americas', 'asia']], json_encode($parsed['servers'] ?? null));
    check('parsed: forced hosts empty, forwarding modern', ($parsed['forced-hosts'] ?? null) === [] && ($parsed['player-info-forwarding-mode'] ?? null) === 'modern');
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
