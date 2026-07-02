<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonPhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function photo(int|string $photo): BinaryFileResponse
    {
        $photo = PersonPhoto::withoutGlobalScope('family_tree')->findOrFail($photo);
        abort_unless($photo->path && Storage::disk('public')->exists($photo->path), 404);

        return response()->file(Storage::disk('public')->path($photo->path), [
            'Cache-Control' => 'private, max-age=1800',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function person(int|string $person): BinaryFileResponse
    {
        $person = Person::withoutGlobalScope('family_tree')->findOrFail($person);
        abort_unless($person->photo_path && Storage::disk('public')->exists($person->photo_path), 404);

        return response()->file(Storage::disk('public')->path($person->photo_path), [
            'Cache-Control' => 'private, max-age=1800',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
