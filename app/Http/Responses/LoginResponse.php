<?php

namespace App\Http\Responses;

use App\Filament\Resources\LabCases\LabCaseResource;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse extends \Filament\Auth\Http\Responses\LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = Filament::auth()->user();
        if ($user?->isLabTechnician()) {
            abort_unless($user->canAccessPanel(Filament::getCurrentOrDefaultPanel()), 403);
            // A previous denied URL (including Dashboard) must not become the landing page.
            session()->forget('url.intended');

            return redirect()->to(LabCaseResource::getUrl());
        }

        return parent::toResponse($request);
    }
}
