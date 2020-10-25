<?php

declare(strict_types=1);

namespace DuplicateDetector\Duplicated;

use DuplicateDetector\DuplicatedFilesTool;

interface Strategy
{
    public function __construct(DuplicatedFilesTool $dft);
    public function checkByHash(array $hashes) : self;
    public function returnCounters() : array;
}
