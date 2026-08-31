<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\PartnerFinance\PartnerFinanceResource;
use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
test('renamed sidebar labels keep their existing destinations', function () {
    expect(Dashboard::getNavigationLabel())->toBe('მთავარი')
        ->and(DoctorResource::getNavigationLabel())->toBe('ექიმები')
        ->and(PartnerFinanceResource::getNavigationLabel())->toBe('ისრაელი - ფინანსები')
        ->and(PartnerPatientResource::getNavigationLabel())->toBe('ისრაელი - პაციენტები')
        ->and(parse_url(Dashboard::getUrl(), PHP_URL_PATH))->toBe('/admin')
        ->and(parse_url(DoctorResource::getUrl('index'), PHP_URL_PATH))->toBe('/admin/doctors')
        ->and(parse_url(PartnerFinanceResource::getUrl('index'), PHP_URL_PATH))->toBe('/admin/partner-finance/partner-finances')
        ->and(parse_url(PartnerPatientResource::getUrl('index'), PHP_URL_PATH))->toBe('/admin/partner-patients');
});
