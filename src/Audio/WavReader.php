<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Audio;

use IndianOpinion\AudioToPost\AudioException;

/**
 * Reads the RIFF/WAVE header: the `fmt ` chunk and where the `data`
 * chunk starts and ends.
 */
final class WavReader
{
    /**
     * @return array{audio_format: int, channels: int, sample_rate: int, byte_rate: int, block_align: int, bits: int, data_offset: int, data_size: int, pcm: bool}
     *
     * @throws AudioException
     */
    public static function read(string $path): array
    {
        $size = filesize($path);
        $h = fopen($path, 'rb');
        if ($h === false || $size === false) {
            throw new AudioException('source_copy_missing', 'The audio file cannot be opened.');
        }
        $riff = (string) fread($h, 12);
        if (strlen($riff) < 12 || !str_starts_with($riff, 'RIFF') || substr($riff, 8, 4) !== 'WAVE') {
            fclose($h);
            throw new AudioException('unreadable_audio', 'Not a RIFF/WAVE file.');
        }
        $fmt = null;
        $pos = 12;
        while ($pos + 8 <= $size) {
            fseek($h, $pos);
            $hdr = (string) fread($h, 8);
            if (strlen($hdr) < 8) {
                break;
            }
            $id = substr($hdr, 0, 4);
            $len = (int) (unpack('V', substr($hdr, 4, 4))[1] ?? 0);
            if ($id === 'fmt ') {
                $body = (string) fread($h, max(1, min($len, 64)));
                if (strlen($body) < 16) {
                    break;
                }
                $f = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', $body);
                if ($f === false) {
                    break;
                }
                $fmt = $f;
            } elseif ($id === 'data') {
                fclose($h);
                if ($fmt === null) {
                    throw new AudioException('unreadable_audio', 'The WAV data chunk comes before its format chunk.');
                }
                $dataOffset = $pos + 8;
                $dataSize = $len;
                if ($dataSize === 0 || $dataSize === 0xFFFFFFFF || $dataOffset + $dataSize > $size) {
                    $dataSize = $size - $dataOffset;
                }
                $align = max(1, (int) $fmt['align']);
                $dataSize -= $dataSize % $align;

                return [
                    'audio_format' => (int) $fmt['format'],
                    'channels' => (int) $fmt['channels'],
                    'sample_rate' => (int) $fmt['rate'],
                    'byte_rate' => (int) $fmt['byterate'],
                    'block_align' => $align,
                    'bits' => (int) $fmt['bits'],
                    'data_offset' => $dataOffset,
                    'data_size' => $dataSize,
                    'pcm' => in_array((int) $fmt['format'], [1, 0xFFFE], true),
                ];
            }
            $pos += 8 + $len + ($len % 2);
        }
        fclose($h);
        throw new AudioException('unreadable_audio', 'The WAV file has no data chunk.');
    }

    /**
     * A 44-byte canonical PCM header for a data block of the given size.
     */
    public static function header(int $channels, int $sampleRate, int $bits, int $dataSize, int $format = 1): string
    {
        $align = max(1, intdiv($channels * $bits, 8));

        return 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, $format === 0xFFFE ? 1 : $format, $channels, $sampleRate, $sampleRate * $align, $align, $bits)
            . 'data' . pack('V', $dataSize);
    }
}
