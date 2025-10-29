<?php

namespace App\Jobs;

use App\Models\Station;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

class GenerateAllStationQRCodesZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $jobId;
    protected $format;
    protected $size;
    protected $metadata;

    public function __construct($jobId, $format = 'png', $size = 256, $metadata = 0)
    {
        $this->jobId = $jobId;
        $this->format = $format;
        $this->size = $size;
        $this->metadata = $metadata;
    }

    public function handle()
    {
        $stations = Station::all();
        $total = $stations->count();
        $zipFileName = "station-qrcodes-{$this->jobId}.zip";
        $zipPath = storage_path("app/temp/{$zipFileName}");
        if (!is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0777, true);
        }
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $count = 0;
        foreach ($stations as $station) {
            $qrData = url("/stations/scan/{$station->id}");
            $writer = $this->format === 'svg' ? new SvgWriter() : new PngWriter();
            $stationNumber = $station->station_number;
            $builder = new Builder(
                writer: $writer,
                data: $qrData,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $this->size,
                margin: 10,
                labelText: $stationNumber
            );
            $result = $builder->build();
            $fileName = "station-{$stationNumber}.{$this->format}";
            $zip->addFromString($fileName, $result->getString());
            $count++;
            $percent = intval(($count / $total) * 100);
            Cache::put("station_qrcode_zip_job:{$this->jobId}", [
                'percent' => $percent,
                'status' => "Processing {$count}/{$total}",
                'finished' => false,
                'downloadUrl' => null,
            ], 600);
        }
        $zip->close();
        Cache::put("station_qrcode_zip_job:{$this->jobId}", [
            'percent' => 100,
            'status' => 'Finished',
            'finished' => true,
            'downloadUrl' => url("/stations/qrcode/zip/{$this->jobId}/download"),
        ], 600);
    }
}
