<?php

namespace App\Jobs;

use App\Models\PcSpec;
use App\Traits\AddsQrCodeBorder;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class GenerateSelectedPcSpecQRCodesZip implements ShouldQueue
{
    use AddsQrCodeBorder, InteractsWithQueue, Queueable, SerializesModels;

    public $jobId;

    public $pcIds;

    public $format;

    public $size;

    public $metadata;

    public function __construct($jobId, $pcIds, $format, $size, $metadata)
    {
        $this->jobId = $jobId;
        $this->pcIds = $pcIds;
        $this->format = $format;
        $this->size = $size;
        $this->metadata = $metadata;
    }

    public function handle()
    {
        $statusKey = "qrcode_zip_selected_job:{$this->jobId}";
        Cache::put($statusKey, [
            'percent' => 0,
            'status' => 'Starting...',
            'finished' => false,
        ], 3600);

        $pcSpecs = PcSpec::whereIn('id', $this->pcIds)->get();
        $total = $pcSpecs->count();
        $done = 0;

        $zipFileName = "pc-qrcodes-selected-{$this->jobId}.zip";
        $zipPath = storage_path("app/temp/{$zipFileName}");

        if (! file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            Cache::put($statusKey, [
                'percent' => 0,
                'status' => 'Failed to create ZIP file',
                'finished' => true,
            ], 3600);

            return;
        }

        foreach ($pcSpecs as $pcspec) {
            $data = $this->metadata
                ? json_encode([
                    'url' => route('pcspecs.scanResult', $pcspec->id),
                    'pc_number' => $pcspec->pc_number ?? "PC-{$pcspec->id}",
                    'manufacturer' => $pcspec->manufacturer,
                    'memory_type' => $pcspec->memory_type,
                ])
                : route('pcspecs.scanResult', $pcspec->id);

            $writer = $this->format === 'svg' ? new SvgWriter : new PngWriter;
            $pcNumber = $pcspec->pc_number ?? "PC-{$pcspec->id}";

            $builder = new Builder(
                writer: $writer,
                data: $data,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $this->size,
                margin: 10,
                labelText: $pcNumber
            );

            $result = $builder->build();
            $filename = $pcNumber.".{$this->format}";
            $zip->addFromString($filename, $this->addQrCodeBorder($result->getString(), $this->format));

            $done++;
            $percent = $total > 0 ? intval(($done / $total) * 100) : 100;
            Cache::put($statusKey, [
                'percent' => $percent,
                'status' => "Processing {$done}/{$total}...",
                'finished' => false,
            ], 3600);
        }

        $zip->close();

        // If no files were added, ZipArchive doesn't create a physical file
        // Ensure the file exists by creating a minimal valid ZIP file structure
        if ($total === 0 && ! file_exists($zipPath)) {
            file_put_contents($zipPath, hex2bin('504b0506'.str_repeat('00', 18)));
        }

        // Use relative path to avoid APP_URL mismatch
        $downloadUrl = '/pcspecs/qrcode/selected-zip/'.$this->jobId.'/download';
        Cache::put($statusKey, [
            'percent' => 100,
            'status' => 'ZIP ready',
            'finished' => true,
            'downloadUrl' => $downloadUrl,
        ], 3600);
    }
}
