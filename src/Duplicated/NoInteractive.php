<?php

declare(strict_types=1);

namespace DuplicateDetector\Duplicated;

use DuplicateDetector\DuplicatedFilesTool;
use BlueData\Data\Formats;

class NoInteractive implements Strategy
{
    /**
     * @var \BlueConsole\Style
     */
    protected \BlueConsole\Style $blueStyle;

    /**
     * @var int
     */
    protected int $duplicatedFilesSize = 0;

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected \Symfony\Component\Console\Input\InputInterface $input;

    /**
     * @param DuplicatedFilesTool $dft
     */
    public function __construct(DuplicatedFilesTool $dft)
    {
        $this->blueStyle = $dft->getBlueStyle();
        $this->input = $dft->getInput();
    }

    /**
     * @param array $hashes
     * @return $this
     */
    public function checkByHash(array $hashes): Strategy
    {
        foreach ($hashes as $file) {
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
