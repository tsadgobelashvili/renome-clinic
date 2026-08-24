<?php

namespace App\Services;

use App\Models\TreatmentEstimate;
use Barryvdh\DomPDF\Facade\Pdf;
use FontLib\Font;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TreatmentEstimateExportService
{
    public function pdf(TreatmentEstimate $estimate): Response
    {
        $estimate->loadMissing(['patient', 'doctor', 'options.items', 'options.stages.items']);
        $font = $this->unicodeExportFont();

        $pdf = Pdf::loadView('exports.treatment-estimate', [
            'estimate' => $estimate,
            'clinicName' => config('app.name'),
            'exportFontFamily' => $font['family'],
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

    public function word(TreatmentEstimate $estimate): BinaryFileResponse
    {
        $estimate->loadMissing(['patient', 'doctor', 'options.items', 'options.stages.items']);
        $font = $this->unicodeExportFont();

        $document = new PhpWord;
        $document->setDefaultFontName($font['family']);
        $document->setDefaultFontSize(10);
        $section = $document->addSection([
            'marginTop' => 900,
            'marginRight' => 900,
            'marginBottom' => 900,
            'marginLeft' => 900,
        ]);

        $section->addText((string) config('app.name'), ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER]);
        $section->addText('მკურნალობის გეგმა და კალკულაცია', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        $section->addTextBreak();
        $section->addText("პაციენტი: {$estimate->patient->full_name}");
        $section->addText('თარიღი: '.$estimate->estimate_date->format('d.m.Y'));

        if ($estimate->doctor) {
            $section->addText("ექიმი: {$estimate->doctor->full_name}");
        }

        foreach ($estimate->options as $index => $option) {
            $section->addTextBreak();
            $section->addText($option->name ?: 'ვარიანტი '.($index + 1), ['bold' => true, 'size' => 13]);
            $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'B7B7B7', 'cellMargin' => 100]);
            $table->addRow();
            foreach (['მანიპულაცია', 'რაოდენობა', 'ერთეულის ფასი', 'ჯამი'] as $heading) {
                $table->addCell()->addText($heading, ['bold' => true]);
            }
            foreach ($option->items as $item) {
                $table->addRow();
                $table->addCell()->addText($item->description);
                $table->addCell()->addText((string) $item->quantity);
                $table->addCell()->addText(number_format((float) $item->unit_price, 2).' ₾');
                $table->addCell()->addText(number_format($item->line_total, 2).' ₾');
            }
            if ($option->discount_amount > 0) {
                $section->addText('საწყისი ჯამი: '.number_format($option->total_amount, 2).' ₾');
                $section->addText("ფასდაკლება: {$option->discount_display}");
                $section->addText('საბოლოო თანხა: '.number_format($option->final_amount, 2).' ₾', ['bold' => true]);
            } else {
                $section->addText('საბოლოო ჯამი: '.number_format($option->final_amount, 2).' ₾', ['bold' => true]);
            }
            if (filled($option->estimated_duration)) {
                $section->addText("სავარაუდო დრო: {$option->estimated_duration}");
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
            [
                'family' => 'Noto Sans Georgian',
                'regular' => '/usr/share/fonts/truetype/noto/NotoSansGeorgian-Regular.ttf',
                'bold' => '/usr/share/fonts/truetype/noto/NotoSansGeorgian-Bold.ttf',
            ],
        ]);

        foreach ($candidates as $candidate) {
            if (! is_file($candidate['regular']) || ! is_file($candidate['bold'])) {
                continue;
            }

            if ($this->fontSupportsGeorgianAndLari($candidate['regular'])
                && $this->fontSupportsGeorgianAndLari($candidate['bold'])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No export font with Georgian and Georgian Lari sign support was found.');
    }

    private function fontSupportsGeorgianAndLari(string $path): bool
    {
        $font = Font::load($path);
        $font?->parse();
        $characters = $font?->getUnicodeCharMap() ?? [];
        $font?->close();

        return isset($characters[0x10DB], $characters[0x20BE]);
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
