<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class DoctorDynamicsChart extends ChartWidget
{
    protected ?string $heading = null;

    protected ?string $maxHeight = '240px';

    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    public array $labels = [];

    public array $series = [];

    protected function getData(): array
    {
        return [
            'datasets' => collect($this->series)->map(fn (array $series): array => [
                'label' => $series['label'],
                'data' => array_map('floatval', $series['data']),
                'borderColor' => $series['color'],
                'backgroundColor' => $series['backgroundColor'],
                'fill' => true,
                'tension' => 0.25,
                'pointRadius' => 2,
                'pointHoverRadius' => 4,
            ])->all(),
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
