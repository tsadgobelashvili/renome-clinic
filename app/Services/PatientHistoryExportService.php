<?php

namespace App\Services;

use App\Models\Patient;
use App\Support\Currency;
use Barryvdh\DomPDF\Facade\Pdf;
use FontLib\Font;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PatientHistoryExportService
{
    public function pdf(Patient $patient): Response
    {
        $patient = $this->loadHistory($patient);
        $font = $this->unicodeExportFont();

        $pdf = Pdf::loadView('exports.patient-history', [
            'patient' => $patient,
            'financialSummaries' => $patient->getFinancialSummariesByCurrency(),
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

        return $pdf->download($this->downloadName($patient, 'pdf'));
    }

    public function word(Patient $patient): BinaryFileResponse
    {
        $patient = $this->loadHistory($patient);
        $font = $this->unicodeExportFont();
        $document = new PhpWord;
        $document->setDefaultFontName($font['family']);
        $document->setDefaultFontSize(10);
        $section = $document->addSection([
            'marginTop' => 800,
            'marginRight' => 800,
            'marginBottom' => 800,
            'marginLeft' => 800,
        ]);

        $section->addText('RenoMe Dental Clinic', ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER]);
        $section->addText('პაციენტის მკურნალობის ისტორია', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        $section->addTextBreak();
        $this->addPatientInformationToWord($section, $patient);
        $this->addFinancialSummaryToWord($section, $patient->getFinancialSummariesByCurrency());

        $section->addTextBreak();
        $section->addText('ვიზიტების ქრონოლოგია', ['bold' => true, 'size' => 13]);

        if ($patient->visits->isEmpty()) {
            $section->addText('ვიზიტების ისტორია ჯერ არ არის.');
        }

        foreach ($patient->visits as $visit) {
            $section->addTextBreak();
            $section->addText($visit->visit_date->format('d.m.Y').' — '.$visit->doctor->full_name, ['bold' => true, 'size' => 11]);
            $section->addText('ტიპი: '.($visit->visit_type === 'consultation' ? 'კონსულტაცია' : 'მკურნალობა'));

            if (filled($visit->comment ?? $visit->notes)) {
                $section->addText('კომენტარი: '.($visit->comment ?? $visit->notes));
            }

            if ($visit->treatmentCaseItems->isNotEmpty()) {
                $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'B7B7B7', 'cellMargin' => 80]);
                $table->addRow();
                foreach (['მანიპულაცია', 'კატეგორია', 'კბილები/უბანი', 'რაოდ.', 'ერთ. ფასი', 'ჯამი'] as $heading) {
                    $table->addCell()->addText($heading, ['bold' => true]);
                }
                foreach ($visit->treatmentCaseItems as $item) {
                    $table->addRow();
                    $table->addCell()->addText($item->treatmentCase->name);
                    $table->addCell()->addText($item->treatmentCase->category_label);
                    $table->addCell()->addText($item->teeth ?: '—');
                    $table->addCell()->addText((string) $item->quantity);
                    $table->addCell()->addText(Currency::format($item->unit_price, $visit->currency));
                    $table->addCell()->addText(Currency::format($item->manipulation_total, $visit->currency));
                }
            }

            $section->addText('ვიზიტის ღირებულება: '.($visit->total_price === null ? '—' : Currency::format($visit->net_amount, $visit->currency)), ['bold' => true]);
            foreach ($visit->payments as $payment) {
                $section->addText('გადახდა: '.$payment->payment_date->format('d.m.Y').' · '.Currency::format($payment->amount, $payment->currency).' · '.$payment->method_display);
                if (filled($payment->comment)) {
                    $section->addText('გადახდის კომენტარი: '.$payment->comment);
                }
            }
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'patient-history-');
        IOFactory::createWriter($document, 'Word2007')->save($temporaryPath);

        return response()
            ->download($temporaryPath, $this->downloadName($patient, 'docx'))
            ->deleteFileAfterSend(true);
    }

    private function loadHistory(Patient $patient): Patient
    {
        $patient->load([
            'visits' => fn ($query) => $query->orderBy('visit_date')->orderBy('id'),
            'visits.doctor',
            'visits.treatmentCaseItems' => fn ($query) => $query->orderBy('id'),
            'visits.treatmentCaseItems.treatmentCase',
            'visits.payments' => fn ($query) => $query->orderBy('payment_date')->orderBy('id'),
            'visits.payments.splits',
        ]);

        return $patient;
    }

    private function addPatientInformationToWord($section, Patient $patient): void
    {
        $section->addText('პაციენტის ინფორმაცია', ['bold' => true, 'size' => 12]);
        $section->addText("სახელი და გვარი: {$patient->full_name}");
        $section->addText('ტელეფონი: '.($patient->phone ?: '—'));
        $section->addText('პირადი ნომერი: '.($patient->personal_id ?: '—'));
        $section->addText('დაბადების თარიღი: '.($patient->birth_date?->format('d.m.Y') ?: '—'));
        if (filled($patient->notes)) {
            $section->addText("შენიშვნა: {$patient->notes}");
        }
    }

    /** @param array<string, array<string, float|int>> $summaries */
    private function addFinancialSummaryToWord($section, array $summaries): void
    {
        $section->addTextBreak();
        $section->addText('ფინანსური შეჯამება', ['bold' => true, 'size' => 12]);
        foreach ($summaries as $currency => $summary) {
            $section->addText(implode(' · ', [
                'ღირებულება: '.Currency::format($summary['net_amount'], $currency),
                'გადახდილი: '.Currency::format($summary['paid_amount'], $currency),
                'დარჩენილი: '.Currency::format($summary['remaining_amount'], $currency),
            ]));
        }
        if ($summaries === []) {
            $section->addText('ფინანსური მონაცემები ჯერ არ არის.');
        }
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
            ['family' => 'Segoe UI', 'regular' => 'C:/Windows/Fonts/segoeui.ttf', 'bold' => 'C:/Windows/Fonts/segoeuib.ttf'],
            ['family' => 'Noto Sans Georgian', 'regular' => '/usr/share/fonts/truetype/noto/NotoSansGeorgian-Regular.ttf', 'bold' => '/usr/share/fonts/truetype/noto/NotoSansGeorgian-Bold.ttf'],
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate['regular']) && is_file($candidate['bold'])
                && $this->fontSupportsGeorgianAndLari($candidate['regular'])
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
        return 'file://'.str_replace('\\', '/', realpath($path) ?: $path);
    }

    private function downloadName(Patient $patient, string $extension): string
    {
        $slug = Str::slug($patient->full_name);
        $slug = $slug !== '' ? $slug : 'patient-'.$patient->getKey();

        return 'patient-history-'.$slug.'-'.now()->format('Y-m-d').'.'.$extension;
    }
}
