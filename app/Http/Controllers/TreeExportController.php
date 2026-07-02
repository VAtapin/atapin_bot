<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Services\TreeArchiveService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TreeExportController extends Controller
{
    public function __invoke(
        Request $request,
        FamilyTree $tree,
        TreeArchiveService $archive,
    ): StreamedResponse {
        abort_unless($request->user()?->canManageTree($tree), 403);
        $payload = $archive->export($tree);

        return response()->streamDownload(
            fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            $tree->slug.'-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
