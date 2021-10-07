<?php

declare(strict_types=1);

namespace DuplicateDetector\Duplicated;

use DuplicateDetector\DuplicatedFilesTool;
use BlueFilesystem\StaticObjects\Fs;
use BlueConsole\Style;

class AutoDel implements Strategy
{
    public array $options = [];
    protected Style $blueStyle;
    protected int $deleteCounter = 0;
    protected int $deleteSizeCounter = 0;
    protected int $duplicatedFiles = 0;
    protected int $duplicatedFilesSize = 0;

    /**
     * @param DuplicatedFilesTool $dft
     * @throws \Exception
     */
    public function __construct(DuplicatedFilesTool $dft)
    {
        $this->blueStyle = $dft->getBlueStyle();
    }

    /**
     * @param array $hash
     * @return Interactive
     * @throws \Exception
     */
    public function checkByHash(array $hash): Strategy
    {
        $this->blueStyle->newLine();
        $copy = false;

        if ($this->options[0]) {
            $copy = true;
        }

        $keep = \array_shift($hash);
        $this->blueStyle->okMessage("Keep: $keep");

        foreach ($hash as $file) {
            if ($copy) {
                Fs::mkdir(\dirname($this->options[0] . $file));
                $out = Fs::copy($file, $this->options[0] . $file, true);
            }

            $out = Fs::delete($file);

            if (reset($out)) {
                $this->blueStyle->okMessage("Removed: $file");
                $this->deleteCounter++;
            } else {
                $this->blueStyle->errorMessage("Removed fail: $file");
            }
        }

        //choose deletion strategy (auto or rules  from file)

        $this->blueStyle->newLine();

        return $this;
    }

    /**
     * @return array
     */
    public function returnCounters(): array
    {
        return [
            $this->duplicatedFilesSize,
            $this->deleteCounter,
            $this->deleteSizeCounter,
        ];
    }
}
