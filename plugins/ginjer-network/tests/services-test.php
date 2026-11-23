<?php

/**
 * Checks for the parts of the plugin that don't need a running panel.
 *
 *   php tests/services-test.php <panel>/vendor/autoload.php [path/to/nodes/config.yml]
 */

$autoload = $argv[1] ?? null;
if ($autoload === null || !file_exists($autoload)) {
    fwrite(STDERR, "usage: php services-test.php <vendor/autoload.php> [nodes config.yml]\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/../src/Services/RegionList.php';
require __DIR__ . '/../src/Services/YamlScalarEditor.php';

use Ginjer\Network\Services\RegionList;
use Ginjer\Network\Services\YamlScalarEditor;
use Symfony\Component\Yaml\Yaml;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

// ---------------- RegionList
$good = <<<'YAML'
- {server: west, min-x: -20000, min-z: -10000, max-x: 0, max-z: 10000}
- {server: east, points: [[0, -10000], [20000, -10000], [20000, 10000], [0, 10000]], smooth: true}
- {server: east, center-x: 30000, center-z: 0, radius-x: 500, radius-z: 300}
YAML;
check('valid list has no problems', RegionList::problems($good, ['west', 'east']) === [], json_encode(RegionList::problems($good, ['west', 'east'])));
check('valid list parses to 3 areas', count(RegionList::parse($good)) === 3);
check('empty list is fine', RegionList::problems('') === []);
check('unknown shard is reported', count(RegionList::problems($good, ['west'])) === 2);
check('shard without area is reported', str_contains(implode(' ', RegionList::problems($good, ['west', 'east', 'north'])), 'north'));
check('min above max is reported', str_contains(implode(' ', RegionList::problems('- {server: a, min-x: 5, min-z: 0, max-x: 1, max-z: 1}')), 'min must be below max'));
check('2-point polygon is reported', str_contains(implode(' ', RegionList::problems('- {server: a, points: [[0,0],[1,1]]}')), 'at least 3 points'));
check('zero radius is reported', str_contains(implode(' ', RegionList::problems('- {server: a, center-x: 0, center-z: 0, radius: 0}')), 'radius'));
check('missing server is reported', str_contains(implode(' ', RegionList::problems('- {min-x: 0, min-z: 0, max-x: 1, max-z: 1}')), 'no server'));
check('a map instead of a list is reported', RegionList::problems('server: a') !== []);
check('bad YAML is reported', str_starts_with(RegionList::problems("- {server: a, min-x: [")[0] ?? '', 'Not valid YAML'));

// ---------------- YamlScalarEditor
$yaml = <<<'YAML'
# top comment
name: main
sharding:
  # turn it on
  enabled: false
  role: leader   # leader or follower
  name: main
  redis:
    host: 127.0.0.1
    port: 6379
    password: ""
  keyPrefix: nodes
duel:
  enabled: true
YAML;
$editor = (new YamlScalarEditor($yaml))
    ->set('sharding.enabled', true)
    ->set('sharding.role', 'follower')
    ->set('sharding.name', 'east')
    ->set('sharding.redis.host', '10.0.0.5')
    ->set('sharding.redis.port', 6380)
    ->set('sharding.redis.password', 'p@ss: #word')
    ->set('sharding.missing', 1);
$out = $editor->content();
$parsed = Yaml::parse($out);
check('nested values are set', $parsed['sharding']['enabled'] === true && $parsed['sharding']['role'] === 'follower'
    && $parsed['sharding']['name'] === 'east' && $parsed['sharding']['redis']['host'] === '10.0.0.5'
    && $parsed['sharding']['redis']['port'] === 6380, $out);
check('awkward password survives', $parsed['sharding']['redis']['password'] === 'p@ss: #word', $out);
check('other keys with the same name are untouched', $parsed['name'] === 'main' && $parsed['duel']['enabled'] === true, $out);
check('comments are kept', str_contains($out, '# top comment') && str_contains($out, '# turn it on') && str_contains($out, '# leader or follower'), $out);
check('missing key is reported', $editor->missing() === ['sharding.missing']);
check('line count unchanged', substr_count($out, "\n") === substr_count($yaml, "\n"));
$crlf = (new YamlScalarEditor(str_replace("\n", "\r\n", $yaml)))->set('sharding.role', 'follower')->content();
check('Windows line ends are kept', substr_count($crlf, "\r\n") === substr_count($yaml, "\n") && Yaml::parse($crlf)['sharding']['role'] === 'follower');
$utf8 = "# Åland — naïve …\nsharding:\n  name: \"Å\" # ÅÅ\n";
$utf8Out = (new YamlScalarEditor($utf8))->set('sharding.name', 'east')->content();
check('UTF-8 text survives', mb_check_encoding($utf8Out, 'UTF-8') && str_contains($utf8Out, '# Åland — naïve …') && Yaml::parse($utf8Out)['sharding']['name'] === 'east', $utf8Out);

// ---------------- against the real Nodes config.yml
if (isset($argv[2])) {
    $real = file_get_contents($argv[2]);
    $editor = (new YamlScalarEditor($real))
        ->set('sharding.enabled', true)
        ->set('sharding.role', 'follower')
        ->set('sharding.name', 'east')
        ->set('sharding.redis.host', 'redis.internal')
        ->set('sharding.redis.port', 6379)
        ->set('sharding.redis.password', 'secret')
        ->set('sharding.redis.database', 2)
        ->set('sharding.keyPrefix', 'ginjer');
    $before = Yaml::parse($real);
    $after = Yaml::parse($editor->content());
    check('real config: every key found', $editor->missing() === [], implode(', ', $editor->missing()));
    check('real config: sharding set', $after['sharding']['enabled'] === true && $after['sharding']['role'] === 'follower'
        && $after['sharding']['name'] === 'east' && $after['sharding']['redis']['database'] === 2 && $after['sharding']['keyPrefix'] === 'ginjer');
    $beforeRest = $before;
    $afterRest = $after;
    unset($beforeRest['sharding'], $afterRest['sharding']);
    check('real config: nothing outside sharding changed', $beforeRest === $afterRest);
    check('real config: failover block kept', $after['sharding']['failover'] === $before['sharding']['failover']);
    check('real config: comment count unchanged', substr_count($editor->content(), '#') === substr_count($real, '#'));
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
