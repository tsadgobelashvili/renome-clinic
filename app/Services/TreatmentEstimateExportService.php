<?php

namespace App\Services;

use App\Models\TreatmentEstimate;
use App\Support\TreatmentPlanDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use FontLib\Font;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TreatmentEstimateExportService
{
    public function pdf(TreatmentEstimate $estimate, string $language = 'ka'): Response
    {
        $labels = TreatmentPlanDocument::labels($language);
        $estimate->loadMissing(['patient', 'doctor', 'options.items', 'options.stages.items']);
        $font = $this->unicodeExportFont();

        $pdf = Pdf::loadView('exports.treatment-estimate', [
            'estimate' => $estimate,
            'exportFontFamily' => $font['family'],
            'labels' => $labels,
            'language' => $language,
        ])->setPaper('a4');

        File::ensureDirectoryExists(storage_path('fonts'));
        $dompdf = $pdf->getDomPDF();
        $options = $dompdf->getOptions();
        $options->setChroot(array_values(array_unique([
            ...(array) $options->getChroot(),
            dirname($font['regular']),
            dirname($font['bold']),
        ])));

        foreach (['normal' => $font['regular'], 'bold' => $font['bold']] as $weight => $path) {
            if ($dompdf->getFontMetrics()->registerFont([
                'family' => $font['family'],
                'style' => 'normal',
                'weight' => $weight,
            ], $this->fontFileUri($path))) {
                continue;
            }

            throw new \RuntimeException("Unicode export font could not be registered for PDF ({$weight}).");
        }

        return $pdf->download($this->pdfDownloadName($estimate));
    }

    public function word(TreatmentEstimate $estimate, string $language = 'ka'): BinaryFileResponse
    {
        $labels = TreatmentPlanDocument::labels($language);
        $estimate->loadMissing(['patient', 'doctor', 'options.items', 'options.stages.items']);
        $font = $this->unicodeExportFont();

        Settings::setOutputEscapingEnabled(true);
        $document = new PhpWord;
        $document->setDefaultFontName($font['family']);
        $document->setDefaultFontSize(10);
        $section = $document->addSection([
            'marginTop' => 1440,
            'headerHeight' => 360,
            'marginRight' => 900,
            'marginBottom' => 900,
            'marginLeft' => 900,
        ]);

        $header = $section->addHeader();
        $header->addText($labels['clinic'], ['bold' => true, 'size' => 12], ['spaceAfter' => 40]);
        $header->addText($labels['address'], ['size' => 9, 'color' => '555555'], ['spaceAfter' => 20]);
        $header->addText(TreatmentPlanDocument::CONTACT, ['size' => 8, 'color' => '555555'], ['spaceAfter' => 0]);
        $section->addFooter()->addText(TreatmentPlanDocument::CONTACT, ['size' => 8, 'color' => '555555'], ['alignment' => Jc::CENTER]);

        $section->addText($labels['title'], ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        $section->addTextBreak();
        $section->addText($labels['patient'].': '.($estimate->patient?->full_name ?? '—'));
        $section->addText($labels['date'].': '.($estimate->estimate_date?->format('d.m.Y') ?? '—'));

        if ($estimate->doctor) {
            $section->addText($labels['doctor'].": {$estimate->doctor->full_name}");
        }

        foreach ($estimate->options as $index => $option) {
            $section->addTextBreak();
            $section->addText($option->name ?: $labels['variant'].' '.($index + 1), ['bold' => true, 'size' => 13]);
            foreach ($option->stages as $stage) {
                if ($option->stages->count() > 1) {
                    $section->addText($stage->name, ['bold' => true]);
                }
                $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'B7B7B7', 'cellMargin' => 100]);
                $table->addRow();
                foreach (['manipulation', 'quantity', 'unit_price', 'total'] as $key) {
                    $table->addCell()->addText($labels[$key], ['bold' => true], ['alignment' => $key === 'manipulation' ? Jc::START : Jc::END]);
                }
                foreach ($stage->items as $item) {
                    $table->addRow();
                    $table->addCell()->addText($item->description);
                    $table->addCell()->addText((string) $item->quantity, [], ['alignment' => Jc::END]);
                    $table->addCell()->addText(TreatmentPlanDocument::formatAmount((float) $item->unit_price).' GEL', [], ['alignment' => Jc::END]);
                    $table->addCell()->addText(TreatmentPlanDocument::formatAmount($item->line_total).' GEL', [], ['alignment' => Jc::END]);
                }
                $section->addText($labels['stage_total'].': '.TreatmentPlanDocument::formatAmount($stage->subtotal).' GEL', ['bold' => true], ['alignment' => Jc::END]);
            }
            if ($option->discount_amount > 0) {
                $section->addText($labels['subtotal'].': '.TreatmentPlanDocument::formatAmount($option->total_amount).' GEL');
                $section->addText($labels['discount'].': '.TreatmentPlanDocument::formatDiscount($option));
                $section->addText($labels['final_total'].': '.TreatmentPlanDocument::formatAmount($option->final_amount).' GEL', ['bold' => true]);
            } else {
                $section->addText($labels['final_total'].': '.TreatmentPlanDocument::formatAmount($option->final_amount).' GEL', ['bold' => true]);
            }
            if (filled($option->estimated_duration)) {
                $section->addText($labels['duration'].": {$option->estimated_duration}");
            }
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'estimate-');
        IOFactory::createWriter($document, 'Word2007')->save($temporaryPath);

        return response()
            ->download($temporaryPath, "treatment-estimate-{$estimate->getKey()}.docx")
            ->deleteFileAfterSend(true);
    }

    /** @return array{family: string, regular: string, bold: string} */
    private function unicodeExportFont(): array
    {
        $candidates = array_filter([
            env('EXPORT_UNICODE_FONT_PATH') ? [
                'family' => env('EXPORT_UNICODE_FONT_FAMILY', 'Unicode Export Font'),
                'regular' => env('EXPORT_UNICODE_FONT_PATH'),
                'bold' => env('EXPORT_UNICODE_BOLD_FONT_PATH', env('EXPORT_UNICODE_FONT_PATH')),
            ] : null,
            [
                'family' => 'Segoe UI',
                'regular' => 'C:/Windows/Fonts/segoeui.ttf',
                'bold' => 'C:/Windows/Fonts/segoeuib.ttf',
            ],
            ...array_map(fn (string $directory): array => [
                'family' => 'DejaVu Sans',
                'regular' => $directory.'/DejaVuSans.ttf',
                'bold' => $directory.'/DejaVuSans-Bold.ttf',
            ], [
                '/usr/share/fonts/truetype/dejavu',
                '/usr/share/fonts/truetype/ttf-dejavu',
                '/usr/share/fonts/dejavu',
                '/usr/share/fonts/dejavu-sans-fonts',
                '/usr/local/share/fonts/dejavu',
            ]),
            [
                'family' => 'Noto Sans Georgian',
                'regular' => '/usr/share/fonts/truetype/noto/NotoSansGeorgian-Regular.ttf',
                'bold' => '/usr/share/fonts/truetype/noto/NotoSansGeorgian-Bold.ttf',
            ],
        ]);

        $unsupportedFonts = [];
        foreach ($candidates as $candidate) {
            if (! is_file($candidate['regular']) || ! is_readable($candidate['regular'])
                || ! is_file($candidate['bold']) || ! is_readable($candidate['bold'])) {
                continue;
            }

            if ($this->fontSupportsExportCharacters($candidate['regular'])
                && $this->fontSupportsExportCharacters($candidate['bold'])) {
                return $candidate;
            }

            $unsupportedFonts[] = $candidate['regular'].' / '.$candidate['bold'];
        }

        throw new \RuntimeException('No export font with Georgian, Latin and Cyrillic support was found. '
            .($unsupportedFonts === []
                ? 'No readable regular/bold font pair exists at the configured or standard system paths.'
                : 'These readable font pairs lack required glyphs: '.implode('; ', $unsupportedFonts)));
    }

    private function fontSupportsExportCharacters(string $path): bool
    {
        $font = Font::load($path);
        $font?->parse();
        $characters = $font?->getUnicodeCharMap() ?? [];
        $font?->close();

        return ! empty($characters[0x10DB])
            && ! empty($characters[0x041F]) && ! empty($characters[0x0050]);
    }

    private function fontFileUri(string $path): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);

        return 'file://'.$path;
    }

    private function pdfDownloadName(TreatmentEstimate $estimate): string
    {
        if (! $estimate->patient) {
            return 'calculation.pdf';
        }

        $name = collect([$estimate->patient->first_name, $estimate->patient->last_name])
            ->map(fn (mixed $part): string => trim((string) preg_replace('/[\/\\\\:*?"<>|]+/u', '', (string) $part)))
            ->filter()
            ->implode('_');

        return $name === '' ? 'calculation.pdf' : $name.'.pdf';
    }
}
