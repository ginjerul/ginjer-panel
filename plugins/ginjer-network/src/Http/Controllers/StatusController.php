<?php

namespace Ginjer\Network\Http\Controllers;

use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Status\StatusPage;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/** A network's public status page (only when the network turned it on), as HTML or with .json. */
class StatusController
{
    public function __invoke(string $token, StatusPage $page): BaseResponse
    {
        $json = str_ends_with($token, '.json');
        $token = $json ? substr($token, 0, -5) : $token;
        $network = strlen($token) === 32 ? Network::where('status_token', $token)->where('status_public', true)->first() : null;
        abort_if($network === null, 404);
        $data = $page->data($network);

        return $json
            ? response()->json($data)->header('Access-Control-Allow-Origin', '*')
            : new Response($page->html($data), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
