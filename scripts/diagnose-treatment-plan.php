<?php

use App\Services\TreatmentEstimateExportService;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;

// CLI-only, read-only deployment diagnostics. Does not read patient records or print secrets.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$report = [
    'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY,
        'ini' => php_ini_loaded_file(), 'memory_limit' => ini_get('memory_limit'),
        'temp_directory' => sys_get_temp_dir()],
];
$lock = json_decode(file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$packages = ['laravel/framework', 'filament/filament', 'barryvdh/laravel-dompdf', 'dompdf/dompdf', 'dompdf/php-font-lib', 'phpoffice/phpword'];
foreach ($lock['packages'] as $package) {
    foreach ($package['require'] ?? [] as $name => $constraint) {
        if (str_starts_with($name, 'ext-')) {
            $extension = substr($name, 4);
            $report['extensions'][$extension] = extension_loaded($extension);
        }
    }
    if (in_array($package['name'], $packages, true)) {
        $report['packages'][$package['name']] = [
            'locked' => $package['version'],
            'installed' => InstalledVersions::isInstalled($package['name'])
                ? InstalledVersions::getPrettyVersion($package['name']) : null,
            'requirements' => $package['require'],
        ];
    }
}
foreach (['storage', 'storage/fonts', 'storage/framework/views', 'bootstrap/cache'] as $directory) {
    $report['directories'][$directory] = ['exists' => is_dir($root.'/'.$directory), 'writable' => is_writable($root.'/'.$directory)];
}
$report['directories']['system_temp'] = ['exists' => is_dir(sys_get_temp_dir()), 'writable' => is_writable(sys_get_temp_dir())];
foreach ([
    'composer.lock', 'package-lock.json', 'app/Services/TreatmentEstimateExportService.php',
    'app/Filament/Pages/Dashboard.php', 'resources/views/filament/pages/dashboard-treatment-plan.blade.php',
    'resources/views/exports/treatment-estimate.blade.php', 'resources/css/filament/admin/theme.css',
    'public/build/manifest.json',
] as $file) {
    $report['sha256'][$file] = is_file($root.'/'.$file) ? hash_file('sha256', $root.'/'.$file) : null;
}
$report['vite_hot_file_exists'] = is_file($root.'/public/hot');
if (is_file($root.'/public/build/manifest.json')) {
    $manifest = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $asset = $manifest['resources/css/filament/admin/theme.css']['file'] ?? null;
    $report['theme']['file'] = $asset;
    if ($asset && is_file($root.'/public/build/'.$asset)) {
        $css = file_get_contents($root.'/public/build/'.$asset);
        $report['theme']['sha256'] = hash('sha256', $css);
        foreach (['renome-plan-document', 'renome-visit-badges', 'renome-visit-plan-chip'] as $selector) {
            $report['theme']['selectors'][$selector] = str_contains($css, $selector);
        }
    }
}
try {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $report['cache'] = ['config' => $app->configurationIsCached(), 'routes' => $app->routesAreCached()];
    foreach (['font_dir', 'font_cache', 'temp_dir'] as $key) {
        $path = config('dompdf.options.'.$key);
        $report['dompdf'][$key] = ['path' => $path, 'writable' => is_string($path) && is_writable($path)];
    }
    $fontResolver = new ReflectionMethod(TreatmentEstimateExportService::class, 'unicodeExportFont');
    $report['export_font'] = $fontResolver->invoke(app(TreatmentEstimateExportService::class));
} catch (Throwable $exception) {
    $report['diagnostic_exception'] = ['class' => get_class($exception), 'message' => $exception->getMessage(),
        'file' => $exception->getFile(), 'line' => $exception->getLine()];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
