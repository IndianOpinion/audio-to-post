<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * Shared streaming copy for chunkers.
 */
abstract class AbstractChunker implements Chunker
{
    public function __construct(private readonly ?string $outputDir = null)
    {
    }

    public function split(string $path, ChunkPlan $plan): iterable
    {
        $dir = $this->outputDir ?? sys_get_temp_dir();
        foreach ($plan->chunks as $chunk) {
            $dest = rtrim($dir, '/') . '/' . bin2hex(random_bytes(16)) . '.' . $plan->format;
            $this->extract($path, $chunk, $dest);
            yield $chunk->index => $dest;
        }
    }

    /**
     * Copy [from, to) of $path to the end of the open handle in 1 MB blocks.
     *
     * @param resource $out
     *
     * @throws AudioException
     */
    protected static function copyRange(string $path, int $from, int $to, $out): void
    {
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new AudioException('source_copy_missing', 'The private audio copy is missing.');
        }
        fseek($in, $from);
        $left = $to - $from;
        while ($left > 0) {
            $block = fread($in, min(1048576, $left));
            if ($block === false || $block === '') {
                break;
            }
            fwrite($out, $block);
            $left -= strlen($block);
        }
        fclose($in);
        if ($left > 0) {
            throw new AudioException('source_copy_missing', 'The private audio copy is shorter than planned.');
        }
    }

    /**
     * @param list<array{start: int, end: int, bs: int, be: int}> $snapped
     */
    protected static function rebuild(ChunkPlan $plan, array $snapped): ChunkPlan
    {
        $chunks = [];
        foreach ($snapped as $i => $s) {
            $prevEnd = $i > 0 ? $snapped[$i - 1]['end'] : $s['start'];
            $nextStart = isset($snapped[$i + 1]) ? $snapped[$i + 1]['start'] : $s['end'];
            $chunks[] = new Chunk(
                $i,
                $s['start'],
                $s['end'],
                $s['bs'],
                $s['be'],
                max(0, $prevEnd - $s['start']),
                max(0, $s['end'] - $nextStart),
            );
        }

        return new ChunkPlan($plan->format, $chunks, $plan->native);
    }
}
