<?php

declare(strict_types=1);

namespace DuplicateDetector\Duplicated;

use DuplicateDetector\DuplicatedFilesTool;
use BlueFilesystem\StaticObjects\Fs;
use BlueConsole\Style;
use SplFileInfo;

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

        $hash = $this->processKeepPolicy($hash);

        foreach ($hash as $file) {
            $this->copy($file);
            $this->delete($file);
        }

        $this->blueStyle->newLine();

        return $this;
    }

    /**
     * @param array $hash
     * @return array
     * @throws \Exception
     */
    protected function processKeepPolicy(array $hash): array
    {
        if (empty($this->options[1])) {
            $keep = \array_shift($hash);
            $this->blueStyle->okMessage("Keep: $keep");
            return $hash;
        }

        foreach ($this->options[1]['keep_rule'] as $name => $rule) {
            if ($rule === '') {
                continue;
            }

            foreach ($hash as $index => $file) {
                $fileInfo = new SplFileInfo($file);

                $hash = $this->checkFileName($hash, $index, $name, $file, $rule);
                $hash = $this->checkPath($hash, $index, $name, $file, $rule);
                $hash = $this->checkDate($hash, $index, $name, $fileInfo, $rule, true);
                $hash = $this->checkDate($hash, $index, $name, $fileInfo, $rule, false);

                if ($name === 'permissions') {
                    $currentPermissions = \substr(\sprintf('%o', $fileInfo->getPerms()), -3);

                    if ($currentPermissions === $rule) {
                        $hash = $this->keep($hash, $index);
                    }
                }

                if ($name === 'owner' && \in_array($fileInfo->getOwner(), $rule)) {
                    $hash = $this->keep($hash, $index);
                }

                if ($name === 'group' && \in_array($fileInfo->getGroup(), $rule)) {
                    $hash = $this->keep($hash, $index);
                }
            }
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @param string $name
     * @param string $file
     * @param string $rule
     * @return array
     * @throws \Exception
     */
    protected function checkFileName(array $hash, int $index, string $name, string $file, string $rule): array
    {
        if ($name === 'filename_is' || $name === 'filename_not_is') {
            $type = $name === 'filename_is' ? 1 : 0;

            return $this->fileName($hash, $index, $file, $type, $rule);
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @param string $file
     * @param int $type
     * @param string $rule
     * @return array
     * @throws \Exception
     */
    protected function fileName(array $hash, int $index, string $file, int $type, string $rule): array
    {
        $fileName = \basename($file);
        $match = \preg_match($rule, $fileName);

        if ($match === $type) {
            $hash = $this->keep($hash, $index);
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @param string $name
     * @param string $file
     * @param string $rule
     * @return array
     * @throws \Exception
     */
    protected function checkPath(array $hash, int $index, string $name, string $file, string $rule): array
    {
        if ($name === 'path_is' || $name === 'path_not_is') {
            $type = $name === 'path_is' ? 1 : 0;

            return $this->fileName($hash, $index, $file, $type, $rule);
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @param string $file
     * @param int $type
     * @param string $rule
     * @return array
     * @throws \Exception
     */
    protected function path(array $hash, int $index, string $file, int $type, string $rule): array
    {
        $dir = \dirname($file);
        $match = \preg_match($rule, $dir);

        if ($match === $type) {
            $hash = $this->keep($hash, $index);
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @param string $name
     * @param SplFileInfo $fileInfo
     * @param string $rule
     * @param bool $gt
     * @return array
     * @throws \Exception
     */
    protected function checkDate(
        array $hash,
        int $index,
        string $name,
        SplFileInfo $fileInfo,
        string $rule,
        bool $gt
    ): array {
        $suffix = $gt ? 'gt' : 'lt';

        switch ($name) {
            case 'a_datetime_' . $suffix:
                $fileStamp = $fileInfo->getATime();
                break;

            case 'c_datetime_' . $suffix:
                $fileStamp = $fileInfo->getCTime();
                break;

            case 'm_datetime_' . $suffix:
                $fileStamp = $fileInfo->getMTime();
                break;

            default:
                return $hash;
        }

        $ruleStamp = \strtotime($rule);

        if ($gt) {
            if ($ruleStamp > $fileStamp) {
                $hash = $this->keep($hash, $index);
            }
        } elseif ($ruleStamp < $fileStamp) {
            $hash = $this->keep($hash, $index);
        }

        return $hash;
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

    /**
     * @param array $hash
     * @param int $index
     * @return array
     * @throws \Exception
     */
    protected function keep(array $hash, int $index): array
    {
        $keep = $hash[$index];
        unset($hash[$index]);
        $this->blueStyle->okMessage("Keep: $keep");

        return $hash;
    }

    /**
     * @param string $file
     * @throws \Exception
     */
    protected function delete(string $file): void
    {
        $out = [true];
        //choose deletion strategy (auto or rules  from file)
        if (!$this->options[2]) {
            $out = Fs::delete($file);
        }

        if (reset($out)) {
            $this->blueStyle->okMessage("Removed: $file");
            $this->deleteCounter++;
        } else {
            $this->blueStyle->errorMessage("Removed fail: $file");
        }
    }

    /**
     * @param string $file
     * @throws \Exception
     */
    protected function copy(string $file): void
    {
        if ($this->options[0]) {
            $destination = $this->options[0] . $file;
            Fs::mkdir(\dirname($this->options[0] . $file));
            $out = Fs::copy($file, $destination, true);

            if (reset($out)) {
                $this->blueStyle->okMessage("Copy: $file to ");
                $this->deleteCounter++;
            } else {
                $this->blueStyle->errorMessage("Copy fail: $file");
            }
        }
    }
}
