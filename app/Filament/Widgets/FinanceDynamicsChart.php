<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class FinanceDynamicsChart extends ChartWidget
{
    protected ?string $heading = 'დინამიკა';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    public array $labels = [];

    public array $income = [];

    public array $expense = [];

    public string $currency = 'GEL';

    protected function getData(): array
    {
        return [
            'datasets' => [
                [
                    'label' => 'შემოსავალი',
                    'data' => array_map('floatval', $this->income),
                    'borderColor' => '#34d399',
                    'backgroundColor' => 'rgba(52, 211, 153, 0.14)',
                    'fill' => true,
                    'tension' => 0.25,
                    'pointRadius' => 2,
                    'pointHoverRadius' => 4,
                ],
                [
                    'label' => 'ხარჯი',
                    'data' => array_map('floatval', $this->expense),
                    'borderColor' => '#fb7185',
                    'backgroundColor' => 'rgba(251, 113, 133, 0.10)',
                    'fill' => true,
                    'tension' => 0.25,
                    'pointRadius' => 2,
                    'pointHoverRadius' => 4,
                ],
            ],
            'labels' => $this->labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'animation' => [
                'duration' => 0,
            ],
            'maintainAspectRatio' => false,
            'responsive' => true,
            'interaction' => [
                'intersect' => false,
                'mode' => 'index',
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
