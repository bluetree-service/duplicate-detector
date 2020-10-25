<?php

namespace DuplicateDetector\Duplicated;

use DuplicateDetector\DuplicatedFilesTool;
use BlueData\Data\Formats;

class NoInteractive implements Strategy
{
    /**
     * @var \BlueConsole\Style
     */
    protected $blueStyle;

    /**
     * @var int
     */
    protected $duplicatedFilesSize = 0;

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    /**
     * @param DuplicatedFilesTool $dft
     */
    public function __construct(DuplicatedFilesTool $dft)
    {
        $this->blueStyle = $dft->getBlueStyle();
        $this->input = $dft->getInput();
    }

    /**
     * @param array $hash
     * @return $this
     */
    public function checkByHash(array $hash): Strategy
    {
        foreach ($hash as $file) {
            $size = null;

            if (!$this->input->getOption('list-only')) {
                /** @noinspection ReturnFalseInspection */
                $size = \filesize($file);
                $this->duplicatedFilesSize += $size;
                $formattedSize = Formats::dataSize($size);
                $size = " ($formattedSize)";
            }

            $this->blueStyle->writeln($file . $size);
        }

        if (!$this->input->getOption('list-only')) {
            $this->blueStyle->newLine();
        }

        return $this;
    }

    /**
     * @return array
     */
    public function returnCounters(): array
    {
        return [
            $this->duplicatedFilesSize,
            0,
            0,
        ];
    }
}
