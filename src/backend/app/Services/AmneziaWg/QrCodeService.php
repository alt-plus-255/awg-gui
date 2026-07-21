<?php

namespace App\Services\AmneziaWg;

use BaconQrCode\Exception\WriterException;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Exception\ValidationException;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use RuntimeException;
use Symfony\Component\Process\Process;

class QrCodeService
{
    private const ECC_LEVELS = [
        ErrorCorrectionLevel::Low,
        ErrorCorrectionLevel::Medium,
        ErrorCorrectionLevel::Quartile,
        ErrorCorrectionLevel::High,
    ];

    private const QRENCODE_LEVELS = ['L', 'M', 'Q', 'H'];

    public function normalizeConfigText(string $conf): string
    {
        $conf = str_replace("\r\n", "\n", str_replace("\r", "\n", $conf));
        $lines = [];

        foreach (explode("\n", $conf) as $line) {
            if (preg_match('/^I[1-5]\s*=\s*$/i', $line)) {
                continue;
            }
            $lines[] = rtrim($line, "\r");
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines)."\n";
    }

    public function buildPng(string $data): string
    {
        $png = $this->buildWithQrencode($data, 'PNG');
        if ($png !== null) {
            return $png;
        }

        return $this->buildWithEndroid($data);
    }

    public function buildSvg(string $data): string
    {
        $svg = $this->buildWithQrencode($data, 'SVG');
        if ($svg !== null) {
            return $svg;
        }

        throw new RuntimeException('SVG QR generation requires qrencode');
    }

    private function buildWithQrencode(string $data, string $type): ?string
    {
        if (! $this->qrencodeAvailable()) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'awg-qr-');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $data);

            $moduleSize = $this->qrencodeModuleSize(strlen($data));

            foreach (self::QRENCODE_LEVELS as $level) {
                $process = new Process([
                    'qrencode',
                    '-t', $type,
                    '-o', '-',
                    '-m', '4',
                    '-l', $level,
                    '-s', (string) $moduleSize,
                    '-r', $tmp,
                ]);
                $process->setTimeout(5);
                $process->run();

                if ($process->isSuccessful()) {
                    $output = $process->getOutput();
                    if ($output === '') {
                        continue;
                    }
                    if ($type === 'PNG' && ! str_starts_with($output, "\x89PNG\r\n\x1a\n")) {
                        continue;
                    }
                    if ($type === 'SVG' && ! str_contains($output, '<svg')) {
                        continue;
                    }

                    return $output;
                }
            }
        } finally {
            @unlink($tmp);
        }

        return null;
    }

    private function buildWithEndroid(string $data): string
    {
        $size = $this->imageSizeForLength(strlen($data));
        $lastError = null;

        foreach (self::ECC_LEVELS as $level) {
            try {
                $result = (new Builder(
                    writer: new PngWriter,
                    writerOptions: [
                        PngWriter::WRITER_OPTION_NUMBER_OF_COLORS => 2,
                    ],
                    data: $data,
                    size: $size,
                    margin: 4,
                    errorCorrectionLevel: $level,
                    roundBlockSizeMode: RoundBlockSizeMode::None,
                    validateResult: true,
                ))->build();

                return $result->getString();
            } catch (ValidationException $e) {
                if (str_contains($e->getMessage(), 'khanamiryan/qrcode-detector-decoder')) {
                    return $this->buildWithEndroidWithoutValidation($data, $size, $level);
                }
                $lastError = $e;
            } catch (WriterException $e) {
                $lastError = $e;
            }
        }

        throw new RuntimeException(
            __('configs.qr_too_large'),
            0,
            $lastError
        );
    }

    private function buildWithEndroidWithoutValidation(string $data, int $size, ErrorCorrectionLevel $level): string
    {
        $result = (new Builder(
            writer: new PngWriter,
            writerOptions: [
                PngWriter::WRITER_OPTION_NUMBER_OF_COLORS => 2,
            ],
            data: $data,
            size: $size,
            margin: 4,
            errorCorrectionLevel: $level,
            roundBlockSizeMode: RoundBlockSizeMode::None,
            validateResult: false,
        ))->build();

        return $result->getString();
    }

    private function qrencodeAvailable(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        $process = new Process(['sh', '-c', 'command -v qrencode']);
        $process->setTimeout(2);
        $process->run();

        $available = $process->isSuccessful();

        return $available;
    }

    private function qrencodeModuleSize(int $bytes): int
    {
        if ($bytes < 1200) {
            return 6;
        }
        if ($bytes <= 2200) {
            return 5;
        }

        return 4;
    }

    private function imageSizeForLength(int $bytes): int
    {
        if ($bytes < 1200) {
            return 400;
        }
        if ($bytes <= 2200) {
            return 512;
        }

        return 640;
    }
}
