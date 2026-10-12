<?php

namespace App\Console\Commands;

use App\Services\CredentialsPdfService;
use Illuminate\Console\Command;

class ExportCredentialsPdfCommand extends Command
{
    protected $signature = 'export:credentials-pdf {--path= : Custom output file path}';
    protected $description = 'Generate and export all Armant login credentials as a PDF file';

    public function handle(CredentialsPdfService $service): int
    {
        $this->info('Generating Armant credentials PDF...');

        $customPath = $this->option('path');
        $outputPath = $service->saveToDisk($customPath);

        $sizeKb = round(filesize($outputPath) / 1024, 2);

        $this->info("✓ PDF successfully generated: {$outputPath} ({$sizeKb} KB)");
        $this->line("  Protected link : /credentials-pdf (Admin Manager & Super Admin only)");

        return self::SUCCESS;
    }
}
