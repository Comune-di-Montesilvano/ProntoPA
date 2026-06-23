<?php

namespace App\Support;

class CosineSimilarity
{
    /**
     * @param float[] $a
     * @param float[] $b
     */
    public static function compute(array $a, array $b): float
    {
        $dot    = 0.0;
        $normA  = 0.0;
        $normB  = 0.0;
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $dot   += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0.0 ? $dot / $denom : 0.0;
    }
}
