<?php

namespace Tests\Feature;

use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\PersonPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GedcomImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_places_residence_address_and_family_idempotently(): void
    {
        $path = storage_path('framework/testing-family.ged');
        file_put_contents($path, <<<'GEDCOM'
0 HEAD
1 CHAR UTF-8
0 @I1@ INDI
1 NAME Иван Петрович /Иванов/
2 GIVN Иван Петрович
2 SURN Иванов
1 SEX M
1 BIRT
2 DATE 1 JAN 1980
2 PLAC Омск
1 RESI
2 NOTE Current address:1
2 ADDR
3 ADR1 Hauptstraße 7
3 CITY Berlin
3 CTRY Германия
1 OCCU Инженер
1 OBJE
2 FORM jpg
2 FILE https://example.test/ivan-1.jpg
2 TITL Портрет
2 _PRIM Y
1 OBJE
2 FORM jpg
2 FILE https://example.test/ivan-2.jpg
2 TITL Семейная фотография
0 @I2@ INDI
1 NAME Анна /Петрова/
2 GIVN Анна
2 SURN Петрова
2 _MARNM Иванова
1 SEX F
1 DEAT Y
2 DATE 2 FEB 2020
2 PLAC Москва
1 BURI
2 PLAC Химкинское кладбище
0 @F1@ FAM
1 HUSB @I1@
1 WIFE @I2@
1 CHIL @I3@
1 MARR
2 DATE 3 MAR 2000
2 PLAC Берлин
0 @I3@ INDI
1 NAME Мария /Иванова/
2 GIVN Мария
2 SURN Иванова
1 SEX F
1 BIRT
2 DATE 4 APR 2005
0 TRLR
GEDCOM);

        try {
            $this->assertSame(0, Artisan::call('gedcom:import', [
                'file' => $path,
                '--fresh' => true,
            ]));

            $ivan = Person::query()->where('gedcom_id', 'I1')->firstOrFail();
            $anna = Person::query()->where('gedcom_id', 'I2')->firstOrFail();

            $this->assertSame('Berlin', $ivan->current_city);
            $this->assertSame('Hauptstraße 7, Berlin, Германия', $ivan->current_address);
            $this->assertSame('Омск', $ivan->birth_place);
            $this->assertSame('Москва', $anna->death_place);
            $this->assertSame('Химкинское кладбище', $anna->burial_place);
            $this->assertSame('Иванова', $anna->married_name);
            $this->assertNotEmpty($ivan->gedcom_data['raw']);
            $this->assertSame(1, Partnership::query()->count());
            $this->assertSame(2, ParentChild::query()->count());
            $this->assertSame(2, $ivan->photos()->count());
            $this->assertSame(1, $ivan->photos()->where('is_primary', true)->count());
            $this->assertSame(
                ['Портрет', 'Семейная фотография'],
                PersonPhoto::query()->where('person_id', $ivan->id)->orderBy('sort_order')->pluck('title')->all(),
            );

            $this->assertSame(0, Artisan::call('gedcom:import', ['file' => $path]));
            $this->assertSame(3, Person::query()->count());
            $this->assertSame(1, Partnership::query()->count());
            $this->assertSame(2, ParentChild::query()->count());
            $this->assertSame(2, PersonPhoto::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_it_imports_all_referenced_media_without_duplicates(): void
    {
        $path = storage_path('framework/testing-referenced-media.ged');
        file_put_contents($path, <<<'GEDCOM'
0 HEAD
1 CHAR UTF-8
0 @I1@ INDI
1 NAME Анна /Иванова/
1 OBJE @M1@
1 OBJE @M2@
0 @M1@ OBJE
1 FILE https://example.test/anna-portrait.jpg
2 FORM jpg
2 TITL Портрет
1 _PRIM Y
0 @M2@ OBJE
1 FILE https://example.test/anna-family.png
2 FORM png
2 TITL Семейное фото
0 TRLR
GEDCOM);

        try {
            $this->assertSame(0, Artisan::call('gedcom:import', ['file' => $path]));
            $person = Person::query()->where('gedcom_id', 'I1')->firstOrFail();
            $this->assertSame(2, $person->photos()->count());
            $this->assertSame(
                ['I1:M1', 'I1:M2'],
                $person->photos()->orderBy('sort_order')->pluck('gedcom_key')->all(),
            );

            $this->assertSame(0, Artisan::call('gedcom:import', ['file' => $path]));
            $this->assertSame(2, $person->photos()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_it_reassembles_utf8_characters_split_by_myheritage_conc_lines(): void
    {
        $path = storage_path('framework/testing-split-utf8.ged');
        file_put_contents(
            $path,
            "\xEF\xBB\xBF0 HEAD\r\n1 CHAR UTF-8\r\n0 @I1@ INDI\r\n"
            ."1 NAME Анна /Иванова/\r\n"
            .'1 NOTE Истори'.chr(0xD1)."\r\n"
            .'2 CONC '.chr(0x8F)." семьи\r\n"
            ."0 TRLR\r\n",
        );

        try {
            $this->assertSame(0, Artisan::call('gedcom:import', ['file' => $path]));
            $this->assertSame(
                'История семьи',
                Person::query()->where('gedcom_id', 'I1')->firstOrFail()->bio,
            );
        } finally {
            @unlink($path);
        }
    }
}
