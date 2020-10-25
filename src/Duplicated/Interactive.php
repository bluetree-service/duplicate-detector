<?php

declare(strict_types=1);

namespace DuplicateDetector\Duplicated;

use DuplicateDetector\DuplicatedFilesTool;
use BlueConsole\MultiSelect;
use BlueData\Data\Formats;
use BlueFilesystem\StaticObjects\Fs;
use BlueConsole\Style;

class Interactive implements Strategy
{
    public const MOD_LINE_CHAR = "\033[1A";

    /**
     * @var Style
     */
    protected Style $blueStyle;

    /**
     * @var MultiSelect
     */
    protected MultiSelect $multiselect;

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
        $this->multiselect = (new MultiSelect($this->blueStyle))->toggleShowInfo(false);
    }

    /**
     * @param array $hash
     * @return Interactive
     * @throws \Exception
     */
    public function checkByHash(array $hash) : Strategy
    {
        $this->blueStyle->newLine(2);

        $this->interactive($hash, $this->multiselect);

        $this->blueStyle->newLine();

        return $this;
    }

    /**
     * @return array
     */
    public function returnCounters() : array
    {
        return [
            $this->duplicatedFilesSize,
            $this->deleteCounter,
            $this->deleteSizeCounter,
        ];
    }

    /**
     * @param array $hash
     * @param MultiSelect $multiselect
     * @return $this
     * @throws \Exception
     */
    protected function interactive(array $hash, MultiSelect $multiselect) : self
    {
        $hashWithSize = [];

        foreach ($hash as $file) {
            $size = filesize($file);
            $this->duplicatedFilesSize += $size;

            $formattedSize = Formats::dataSize($size);
            $hashWithSize[] = "$file (<info>$formattedSize</>)";
        }

        $selected = $multiselect->renderMultiSelect($hashWithSize);

        if ($selected) {
            $this->processRemoving($selected, $hash);
        }

        return $this;
    }

    /**
     * @param array $selected
     * @param array $hash
     * @throws \Exception
     */
    protected function processRemoving(array $selected, array $hash) : void
    {
        foreach (array_keys($selected) as $idToDelete) {
            $this->deleteSizeCounter += filesize($hash[$idToDelete]);
            $this->blueStyle->infoMessage('Removing: ' . $hash[$idToDelete]);
            $out = Fs::delete($hash[$idToDelete]);

            echo self::MOD_LINE_CHAR;

            if (reset($out)) {
                $this->blueStyle->okMessage('Removed success: ' . $hash[$idToDelete]);
                $this->deleteCounter++;
            } else {
                $this->blueStyle->errorMessage('Removed fail: ' . $hash[$idToDelete]);
            }
        }
    }
}
