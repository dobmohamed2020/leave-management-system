<?php

namespace App\Services;

use App\Support\ArabicPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

class CredentialsPdfService
{
    /**
     * Generate the credentials PDF binary string.
     */
    public function generate(): string
    {
        $data = $this->buildData();

        $html = view('pdf.credentials', $data)->render();
        $html = ArabicPdf::shapeHtml($html);

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->output();
    }

    /**
     * Generate and save the PDF file to disk.
     */
    public function saveToDisk(?string $path = null): string
    {
        $path = $path ?? public_path('armant-credentials.pdf');
        $pdfBinary = $this->generate();

        File::put($path, $pdfBinary);

        // Also ensure it is in storage/app/public
        $storagePath = storage_path('app/public/armant-credentials.pdf');
        File::ensureDirectoryExists(dirname($storagePath));
        File::put($storagePath, $pdfBinary);

        return $path;
    }

    /**
     * Build the data required for the PDF report.
     */
    public function buildData(): array
    {
        $jsonPath = database_path('data/armant-schools.json');
        $payload = json_decode(File::get($jsonPath), true, flags: JSON_THROW_ON_ERROR);

        $defaultPassword = $payload['default_password'] ?? '123456789';

        $adminAccounts = [
            [
                'name'     => 'مدير النظام العام (Super Admin)',
                'org'      => 'مديرية التربية والتعليم بالأقصر',
                'role'     => 'مدير النظام (كامل الصلاحيات)',
                'email'    => 'admin@rtltec.com',
                'phone'    => '01000000000',
                'password' => 'Admin@2026!',
            ],
            [
                'name'     => 'مدير إدارة أرمنت التعليمية',
                'org'      => 'إدارة أرمنت التعليمية',
                'role'     => 'مدير الإدارة (اعتماد نهائي)',
                'email'    => 'armant.manager@armant-schools.edu',
                'phone'    => '01000000001',
                'password' => $defaultPassword,
            ],
            [
                'name'     => 'مسؤول شؤون العاملين بالإدارة',
                'org'      => 'إدارة أرمنت التعليمية',
                'role'     => 'شؤون عاملين (اعتماد ثاني)',
                'email'    => 'armant.hr@armant-schools.edu',
                'phone'    => '01000000003',
                'password' => $defaultPassword,
            ],
            [
                'name'     => 'مسؤول إجازات إدارة أرمنت',
                'org'      => 'إدارة أرمنت التعليمية',
                'role'     => 'مسؤول الإجازات (اعتماد أول)',
                'email'    => 'armant.leaves@armant-schools.edu',
                'phone'    => '01000000002',
                'password' => $defaultPassword,
            ],
        ];

        $schools = [];
        foreach ($payload['schools'] as $schoolData) {
            $manager = collect($schoolData['accounts'] ?? [])
                ->firstWhere('role', 'manager');

            $schools[] = [
                'code'     => $schoolData['code'],
                'name'     => $schoolData['name'],
                'category' => $schoolData['category'] ?? 'عام',
                'manager'  => [
                    'email'    => $manager['email'] ?? ($schoolData['code'] . '.manager@armant-schools.edu'),
                    'phone'    => $manager['phone'] ?? '—',
                    'password' => $manager['password'] ?? $defaultPassword,
                ],
            ];
        }

        return [
            'adminAccounts' => $adminAccounts,
            'schools'       => $schools,
            'generatedAt'   => now()->format('Y/m/d H:i'),
            'appUrl'        => config('app.url', 'http://localhost'),
        ];
    }
}
