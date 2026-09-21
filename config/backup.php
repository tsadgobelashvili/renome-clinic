<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

return [
    // Scheduling is opt-in, and routes/console.php also restricts it to production.
    'enabled' => (bool) env('BACKUP_ENABLED', false),

    'backup' => [
        // Separate prefixes prevent cleanup from touching another environment's archives.
        'name' => env('BACKUP_NAME', 'renome-'.env('APP_ENV', 'production')),
        'source' => [
            'files' => [
                'include' => [storage_path('app/private'), storage_path('app/public')],
                'exclude' => [
                    storage_path('app/private/livewire-tmp'),
                    storage_path('app/public/livewire-tmp'),
                    storage_path('app/backup-temp'),
                    storage_path('framework'),
                    storage_path('logs'),
                ],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                // Archives retain portable app/private and app/public paths.
                'relative_path' => storage_path(),
            ],
            'databases' => ['pgsql'],
        ],
        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => 'Y-m-d-H-i-s',
        'database_dump_filename_base' => 'connection',
        'database_dump_file_extension' => 'dump',
        'destination' => [
            'compression_method' => ZipArchive::CM_DEFLATE,
            'compression_level' => 6,
            'filename_prefix' => '',
            'disks' => ['s3'],
            'continue_on_failure' => false,
        ],
        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'aes256',
        'verify_backup' => true,
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => env('BACKUP_MAIL_TO') ? ['mail'] : [],
            BackupWasSuccessfulNotification::class => env('BACKUP_MAIL_TO') ? ['mail'] : [],
            CleanupHasFailedNotification::class => env('BACKUP_MAIL_TO') ? ['mail'] : [],
            UnhealthyBackupWasFoundNotification::class => env('BACKUP_MAIL_TO') ? ['mail'] : [],
        ],
        'notifiable' => Notifiable::class,
        'mail' => [
            'to' => env('BACKUP_MAIL_TO') ? [env('BACKUP_MAIL_TO')] : [],
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS'),
                'name' => env('MAIL_FROM_NAME', 'RenoMe'),
            ],
        ],
    ],

    'monitor_backups' => [[
        'name' => env('BACKUP_NAME', 'renome-'.env('APP_ENV', 'production')),
        'disks' => ['s3'],
        'health_checks' => [MaximumAgeInDays::class => 2],
    ]],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 16,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 6,
            'keep_yearly_backups_for_years' => 1,
            // Retention, not an arbitrary size cap, determines what can be deleted.
            'delete_oldest_backups_when_using_more_megabytes_than' => null,
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],
];
