<?php

namespace App\Services;

use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonMergeService
{
    public function merge(Person $source, Person $target): Person
    {
        if ($source->tree_id !== $target->tree_id || $source->is($target)) {
            throw ValidationException::withMessages([
                'target' => 'Выберите другого человека из этого же дерева.',
            ]);
        }

        return DB::transaction(function () use ($source, $target): Person {
            foreach ([
                'first_name', 'middle_name', 'last_name', 'maiden_name', 'married_name',
                'gender', 'birth_date', 'death_date', 'birth_place', 'death_place',
                'burial_place', 'current_city', 'current_address', 'occupation', 'bio',
                'photo_path',
            ] as $field) {
                if (blank($target->{$field}) && filled($source->{$field})) {
                    $target->{$field} = $source->{$field};
                }
            }
            $target->save();

            $source->photos()->update(['person_id' => $target->id, 'tree_id' => $target->tree_id]);
            $source->albums()->update(['person_id' => $target->id, 'tree_id' => $target->tree_id]);
            $source->events()->update(['person_id' => $target->id, 'tree_id' => $target->tree_id]);
            DB::table('tree_memberships')->where('person_id', $source->id)->update(['person_id' => $target->id]);
            DB::table('data_issues')->where('person_id', $source->id)->update(['person_id' => $target->id]);

            ParentChild::query()->where('parent_id', $source->id)->get()->each(function ($link) use ($target): void {
                if ($link->child_id !== $target->id) {
                    ParentChild::query()->firstOrCreate([
                        'tree_id' => $target->tree_id,
                        'parent_id' => $target->id,
                        'child_id' => $link->child_id,
                    ], ['type' => $link->type, 'notes' => $link->notes]);
                }
                $link->delete();
            });
            ParentChild::query()->where('child_id', $source->id)->get()->each(function ($link) use ($target): void {
                if ($link->parent_id !== $target->id) {
                    ParentChild::query()->firstOrCreate([
                        'tree_id' => $target->tree_id,
                        'parent_id' => $link->parent_id,
                        'child_id' => $target->id,
                    ], ['type' => $link->type, 'notes' => $link->notes]);
                }
                $link->delete();
            });

            Partnership::query()
                ->where(fn ($query) => $query
                    ->where('partner_one_id', $source->id)
                    ->orWhere('partner_two_id', $source->id))
                ->get()
                ->each(function (Partnership $partnership) use ($source, $target): void {
                    $otherId = $partnership->partner_one_id === $source->id
                        ? $partnership->partner_two_id
                        : $partnership->partner_one_id;
                    if ($otherId !== $target->id) {
                        Partnership::query()->firstOrCreate([
                            'tree_id' => $target->tree_id,
                            'partner_one_id' => min($target->id, $otherId),
                            'partner_two_id' => max($target->id, $otherId),
                        ], [
                            'status' => $partnership->status,
                            'started_at' => $partnership->started_at,
                            'ended_at' => $partnership->ended_at,
                            'place' => $partnership->place,
                            'notes' => $partnership->notes,
                        ]);
                    }
                    $partnership->delete();
                });

            $source->delete();

            return $target->refresh();
        });
    }
}
