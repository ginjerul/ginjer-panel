<?php

namespace Ginjer\Network\Services\Ops;

use App\Enums\NodeJwtScope;
use App\Models\Server;
use App\Models\User;
use App\Services\Nodes\NodeJWTService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Uploads a local file into a server's files through Wings' upload endpoint (the one the file manager uses). */
class WingsUploader
{
    public function __construct(private NodeJWTService $jwt) {}

    public function upload(Server $server, User $user, string $localPath, string $directory, string $fileName): void
    {
        // a token is good for one upload, so every upload gets its own
        $token = $this->jwt->setExpiresAt(CarbonImmutable::now()->addMinutes(30))
            ->setScopes(NodeJwtScope::FileUpload)
            ->setUser($user)
            ->setClaims(['server_uuid' => $server->uuid])
            ->handle($server->node, $user->id . $server->uuid . Str::random(12));

        $url = $server->node->getConnectionAddress() . '/upload/file?' . http_build_query(['token' => $token->toString(), 'directory' => $directory]);
        $handle = fopen($localPath, 'r');
        try {
            $response = Http::timeout(900)->attach('files', $handle, $fileName)->post($url);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        if (!$response->successful()) {
            throw new RuntimeException("Uploading $fileName to {$server->name} failed (HTTP {$response->status()}): " . Str::limit($response->body(), 200));
        }
    }
}
