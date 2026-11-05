<?php

namespace App\Services\Scanners;

use App\Contracts\MalwareScanner;
use App\Enums\ScanStatus;
use RuntimeException;

/**
 * Scans through a ClamAV daemon's INSTREAM command. The daemon sees decrypted
 * content, so it must be on a private network (developer guide §15).
 */
class ClamdScanner implements MalwareScanner
{
    public function __construct(private string $host, private int $port) {}

    public function scan(string $contents): ScanStatus
    {
        $socket = @fsockopen($this->host, $this->port, $errorCode, $errorMessage, 5);

        if ($socket === false) {
            throw new RuntimeException("Could not reach the malware scanner: {$errorMessage}");
        }

        try {
            stream_set_timeout($socket, 60);
            fwrite($socket, "zINSTREAM\0");

            foreach (str_split($contents === '' ? "\n" : $contents, 8192) as $chunk) {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($socket, pack('N', 0));

            $reply = trim((string) fgets($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }

        return match (true) {
            str_ends_with($reply, 'OK') => ScanStatus::Clean,
            str_ends_with($reply, 'FOUND') => ScanStatus::Infected,
            default => throw new RuntimeException('The malware scanner gave an unexpected reply.'),
        };
    }
}
