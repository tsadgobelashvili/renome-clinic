<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Models\Doctor;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDoctor extends EditRecord
{
    protected static string $resource = DoctorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->disabled(fn (Doctor $record): bool => $record->visits()->exists())
                ->tooltip(fn (Doctor $record): ?string => $record->visits()->exists()
                    ? 'ექიმის წაშლა შეუძლებელია, რადგან მას ვიზიტების ისტორია აქვს.'
                    : null),
        ];
    }
}
