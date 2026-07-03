<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonPhoto;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function photo(Request $request, int|string $photo): BinaryFileResponse
    {
        $photo = PersonPhoto::withoutGlobalScope('family_tree')->findOrFail($photo);
        abort_unless($photo->path && Storage::disk('public')->exists($photo->path), 404);

        return $this->file($request, $photo->path);
    }

    public function photoThumbnail(Request $request, int|string $photo): BinaryFileResponse
    {
        $photo = PersonPhoto::withoutGlobalScope('family_tree')->findOrFail($photo);
        $path = $photo->thumbnail_path ?: $photo->path;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return $this->file($request, $path);
    }

    public function person(Request $request, int|string $person): BinaryFileResponse
    {
        $person = Person::withoutGlobalScope('family_tree')->findOrFail($person);
        abort_unless($person->photo_path && Storage::disk('public')->exists($person->photo_path), 404);

        return $this->file($request, $person->photo_path);
    }

    public function personThumbnail(Request $request, int|string $person): BinaryFileResponse
    {
        $person = Person::withoutGlobalScope('family_tree')->findOrFail($person);
        $path = $person->photo_thumbnail_path ?: $person->photo_path;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return $this->file($request, $path);
    }

    private function file(Request $request, string $path): BinaryFileResponse
    {
        $absolute = Storage::disk('public')->path($path);
        $modified = filemtime($absolute) ?: time();
        $response = response()->file($absolute, [
            'Cache-Control' => 'private, max-age=21600, stale-while-revalidate=86400',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
        $response->setEtag(sha1($path.'|'.filesize($absolute).'|'.$modified));
        $response->setLastModified(Carbon::createFromTimestamp($modified));
        $response->isNotModified($request);

        return $response;
    }
}
