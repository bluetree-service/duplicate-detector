<?php

declare(strict_types=1);

namespace DuplicateDetector\Duplicated;

use DuplicateDetector\DuplicatedFilesTool;
use BlueData\Data\Formats;
use BlueConsole\Style;
use Symfony\Component\Console\Input\InputInterface;

class NoInteractive implements Strategy
{
    /**
     * @var Style
     */
    protected Style $blueStyle;

    /**
     * @var int
     */
    protected int $duplicatedFilesSize = 0;

    /**
     * @var InputInterface
     */
    protected InputInterface $input;

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
