<?php

namespace Ginjer\Network\Services\Ops;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Downloads update files once, into the panel's storage, so they can be uploaded to every shard.
 * (Wings can't fetch GitHub release files itself: they redirect, and Wings refuses redirects.)
 */
class Artifacts
{
    /** Downloads a URL (following redirects) and returns the local path. */
    public function download(string $url, ?string $token = null): string
    {
        $request = Http::timeout(600)->withHeaders(['User-Agent' => 'ginjer-network']);
        if (filled($token) && str_contains((string) parse_url($url, PHP_URL_HOST), 'github')) {
            $request = $request->withToken($token);
        }
        $path = $this->tempPath(basename((string) parse_url($url, PHP_URL_PATH)) ?: 'download');
        $response = $request->sink($path)->get($url);
        if (!$response->successful()) {
            @unlink($path);
            throw new RuntimeException("Downloading $url failed (HTTP {$response->status()}).");
        }

        return $path;
    }

    /**
     * Downloads one file from a GitHub release ("latest" or a tag). Works for private repositories
     * with a token that can read the repository's contents.
     */
    public function githubAsset(string $repo, string $release, string $name, ?string $token = null): string
    {
        $api = Http::timeout(30)->withHeaders(['User-Agent' => 'ginjer-network', 'Accept' => 'application/vnd.github+json']);
        if (filled($token)) {
            $api = $api->withToken($token);
        }
        $endpoint = $release === 'latest' ? "https://api.github.com/repos/$repo/releases/latest" : "https://api.github.com/repos/$repo/releases/tags/$release";
        $response = $api->get($endpoint);
        if (!$response->successful()) {
            throw new RuntimeException("Couldn't read release $release of $repo (HTTP {$response->status()})" . (filled($token) ? '.' : '. A private repository needs a GitHub token in the network\'s update settings.'));
        }
        $asset = collect($response->json('assets', []))->firstWhere('name', $name);
        if ($asset === null) {
            throw new RuntimeException("Release $release of $repo has no $name.");
        }

        $download = Http::timeout(600)->withHeaders(['User-Agent' => 'ginjer-network', 'Accept' => 'application/octet-stream']);
        if (filled($token)) {
            $download = $download->withToken($token);
        }
        $path = $this->tempPath($name);
        $file = $download->sink($path)->get($asset['url']);
        if (!$file->successful()) {
            @unlink($path);
            throw new RuntimeException("Downloading $name from $repo failed (HTTP {$file->status()}).");
        }

        return $path;
    }

    private function tempPath(string $name): string
    {
        $dir = storage_path('app/ginjer-network/updates');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . '/' . Str::random(8) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }
}
