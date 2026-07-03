<?php

namespace App\Filament\Resources\CmsPages\Pages;

use App\Filament\Resources\CmsPages\CmsPageResource;
use App\Models\CmsPageVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCmsPage extends EditRecord
{
    protected static string $resource = CmsPageResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $published = ($data['status'] ?? 'draft') === 'published';

        return [
            ...$data,
            'is_published' => $published,
            'published_at' => $published ? ($this->record->published_at ?: now()) : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Предпросмотр')
                ->url(fn (): string => route('public.page.preview', $this->record))
                ->openUrlInNewTab(),
            Action::make('restore_version')
                ->label('История версий')
                ->visible(fn (): bool => $this->record->versions()->exists())
                ->schema([
                    Select::make('version_id')
                        ->label('Версия')
                        ->options(fn (): array => $this->record->versions()
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (CmsPageVersion $version): array => [
                                $version->id => $version->created_at->format('d.m.Y H:i').' — '.$version->title,
                            ])->all())
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $version = $this->record->versions()->findOrFail($data['version_id']);
                    $this->record->update($version->only([
                        'title', 'meta_title', 'meta_description', 'content', 'status',
                    ]));
                    Notification::make()->title('Версия восстановлена')->success()->send();
                    $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
                }),
        ];
    }
}
