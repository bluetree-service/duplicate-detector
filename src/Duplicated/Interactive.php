<?php

namespace DuplicateDetector\Duplicated;

use DuplicateDetector\DuplicatedFilesTool;
use BlueConsole\MultiSelect;
use BlueData\Data\Formats;
use BlueFilesystem\StaticObjects\Fs;

class Interactive implements Strategy
{
    /**
     * @todo use \BlueConsole\MultiSelect::MOD_LINE_CHAR after library update
     */
    public const MOD_LINE_CHAR = "\033[1A";

    /**
     * @var \BlueConsole\Style
     */
    protected $blueStyle;

    /**
     * @var MultiSelect
     */
    protected $multiselect;

    protected $deleteCounter = 0;
    protected $deleteSizeCounter = 0;
    protected $duplicatedFiles = 0;
    protected $duplicatedFilesSize = 0;

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

        //@todo show deleted file size
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
            //delete process
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
