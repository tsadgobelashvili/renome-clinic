<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Services\PurchaseRecovery;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class RecoverPurchaseFromFile
{
    public static function make(EditPurchase $page): Action
    {
        return Action::make('reimportRs')
            ->label(app()->getLocale() === 'en' ? 'Re-import from RS file' : 'RS ფაილიდან ხელახლა იმპორტი')
            ->icon('heroicon-o-arrow-up-tray')->modalWidth('7xl')
            ->visible(fn () => auth()->user()?->canManageOwnerModules() && $page->getRecord()->source === 'rs')
            ->mountUsing(function (Schema $schema) use ($page): void {
                $page->rsRecoveryPreview = null;
                $page->rsRecoveryUpload = null;
                $page->rsRecoveryRequest = (string) Str::uuid();
                $schema->fill();
            })
            ->steps([
                Step::make('RS ფაილი')->schema([
                    FileUpload::make('file')->label('RS Excel / CSV ფაილი')->disk('local')->directory('purchase-recovery')->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->rules(['extensions:xlsx,csv'])->maxSize(10240)->required()
                        ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file) use ($page): string {
                            $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
                            $page->rsRecoveryUpload = 'purchase-recovery/'.$name;

                            return $name;
                        }),
                    TextInput::make('document_number')->label('RS დოკუმენტის ნომერი')->required()->maxLength(255),
                ])->afterValidation(function (Step $component) use ($page): void {
                    $data = $component->getChildSchema()->getState();
                    $path = self::uploadPath($page, $data['file'] ?? null);
                    $page->rsRecoveryPreview = app(PurchaseRecovery::class)->preview($page->getRecord()->id, $path, $data['document_number'], auth()->user());
                }),
                Step::make('წინასწარი ნახვა და დადასტურება')->schema([
                    View::make('filament.resources.purchases.recovery-preview')->viewData(fn () => ['preview' => $page->rsRecoveryPreview]),
                    Checkbox::make('confirm_recovery')->label('ვადასტურებ წინასწარ ნაჩვენები გადაუხდელი RS ჩანაწერების ჩანაცვლებას / გაერთიანებას')->accepted()->required(),
                ]),
            ])->modalSubmitActionLabel('ჩანაცვლების დადასტურება')->action(function (array $data) use ($page): void {
                if (! $page->rsRecoveryPreview || trim($data['document_number']) !== $page->rsRecoveryPreview['source']['document_number']) {
                    throw ValidationException::withMessages(['recovery' => 'ჯერ იხილეთ ახალი წყაროს წინასწარი მონაცემები.']);
                }
                $path = self::uploadPath($page, $data['file'] ?? null);
                try {
                    app(PurchaseRecovery::class)->recover($page->getRecord()->id, $path, $data['document_number'],
                        $page->rsRecoveryPreview['fingerprint'], $page->rsRecoveryRequest, auth()->user());
                    $page->refreshRsRecoveryForm();
                    Notification::make()->success()->title('RS დოკუმენტი აღდგენილია; ფინანსური გატარება არ შექმნილა')->send();
                } finally {
                    Storage::disk('local')->delete($page->rsRecoveryUpload);
                    $page->rsRecoveryUpload = null;
                    $page->rsRecoveryPreview = null;
                }
            });
    }

    public static function uploadPath(EditPurchase $page, mixed $path): string
    {
        abort_unless(auth()->user()?->canManageOwnerModules(), 403);
        if (! is_string($path) || ! preg_match('~\Apurchase-recovery/[a-zA-Z0-9_-]+\.(xlsx|csv)\z~', $path) || $path !== $page->rsRecoveryUpload) {
            throw ValidationException::withMessages(['file' => 'აირჩიეთ ამ ფორმაში ატვირთული RS ფაილი.']);
        }

        return Storage::disk('local')->path($path);
    }
}
