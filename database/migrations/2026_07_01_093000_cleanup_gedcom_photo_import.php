<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $albumIds = DB::table('photo_albums')
            ->where('title', 'Импорт GEDCOM')
            ->pluck('id');

        if ($albumIds->isNotEmpty()) {
            DB::table('person_photos')
                ->whereIn('photo_album_id', $albumIds)
                ->update(['photo_album_id' => null]);

            DB::table('photo_albums')
                ->whereIn('id', $albumIds)
                ->delete();
        }

        DB::table('people')
            ->where('gedcom_id', 'I88888888')
            ->update(['is_published' => false]);
    }

    public function down(): void
    {
        // Технические альбомы намеренно не восстанавливаются.
    }
};
