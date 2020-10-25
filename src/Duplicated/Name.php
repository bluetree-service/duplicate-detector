<?php

declare(strict_types=1);

namespace DuplicateDetector\Duplicated;

class Name
{
    public function checkByName(array $names, array $hashes, int $similarity) : array
    {
        foreach ($names as $path => $fileName) {
            unset($names[$path]);

            foreach ($names as $verifiedPath => $toVerified) {
                $val = 0;
                \similar_text($fileName, $toVerified, $val);

                if ($val >= $similarity) {
                    if (!($hashes[$fileName] ?? false)) {
                        $hashes[$fileName][] = $path;
                    }

                    $hashes[$fileName][] = $verifiedPath;
                }
            }
        }

        return $hashes;
    }
}
