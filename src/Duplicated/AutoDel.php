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
    protected bool $showNewLine = true;
    protected bool $keep;

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
        if ($this->showNewLine) {
            $this->blueStyle->newLine();
            $this->showNewLine = false;
        }

        foreach ($hash as $file) {
            $this->duplicatedFilesSize += \filesize($file);
        }

        if (empty($this->options[1])) {
            $this->processAuto($hash);

            return $this;
        }

        $hash = $this->processKeepPolicy($hash);

        if (!$this->keep) {
            $hash = $this->processAuto($hash);
        }

        $this->processDeletePolicy($hash);

        return $this;
    }

    /**
     * @param array $hash
     * @return array
     * @throws \Exception
     */
    protected function processAuto(array $hash): array
    {
        $keep = \array_shift($hash);

        $this->blueStyle->okMessage("<fg=green>Keep</>: $keep");
        $this->showNewLine = true;

        foreach (\array_keys($hash) as $index) {
            $hash = $this->delete($hash, $index);
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @return array
     * @throws \Exception
     */
    protected function processKeepPolicy(array $hash): array
    {
        $this->keep = false;
        //odwrócić pętle, żeby niepotrzebnie nie sprawdzał ruli
        foreach ($this->options[1]['keep_rule'] as $name => $rule) {
            if (empty($rule)) {
                continue;
            }

            foreach ($hash as $index => $file) {
                $fileInfo = new SplFileInfo($file);

                $hash = $this->checkFileName($hash, $index, $name, $file, $rule, 'keep');
                $hash = $this->checkPath($hash, $index, $name, $file, $rule, 'keep');
                $hash = $this->checkDate($hash, $index, $name, $fileInfo, $rule, true, 'keep');
                $hash = $this->checkDate($hash, $index, $name, $fileInfo, $rule, false, 'keep');

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
     * @param string|array $rule
     * @param string $action
     * @return array
     * @throws \Exception
     */
    protected function checkFileName(array $hash, int $index, string $name, string $file, $rule, string $action): array
    {
        if ($name === 'filename_is' || $name === 'filename_not_is') {
            $type = $name === 'filename_is' ? 1 : 0;
            $fileName = \basename($file);

            return $this->fileName($hash, $index, $type, $fileName, $rule, $action);
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @param int $type
     * @param string $name
     * @param string|array $rule
     * @param string $action
     * @return array
     * @throws \Exception
     */
    protected function fileName(array $hash, int $index, int $type, string $name, $rule, string $action): array
    {
        $match = \preg_match($rule, $name);

        if ($match === $type) {
            $hash = $this->$action($hash, $index);
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @param string $name
     * @param string $file
     * @param string|array $rule
     * @param string $action
     * @return array
     * @throws \Exception
     */
    protected function checkPath(array $hash, int $index, string $name, string $file, $rule, string $action): array
    {
        if ($name === 'path_is' || $name === 'path_not_is') {
            $type = $name === 'path_is' ? 1 : 0;
            $dir = \dirname($file);

            return $this->fileName($hash, $index, $type, $dir, $rule, $action);
        }

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @param string $name
     * @param SplFileInfo $fileInfo
     * @param string|array $rule
     * @param bool $greater
     * @param string $action
     * @return array
     */
    protected function checkDate(
        array $hash,
        int $index,
        string $name,
        SplFileInfo $fileInfo,
        $rule,
        bool $greater,
        string $action
    ): array {
        $suffix = $greater ? 'gt' : 'lt';

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

        if ($greater) {
            if ($ruleStamp > $fileStamp) {
                $hash = $this->$action($hash, $index);
            }
        } elseif ($ruleStamp < $fileStamp) {
            $hash = $this->$action($hash, $index);
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
        $this->keep = true;
        $keep = $hash[$index];
        unset($hash[$index]);
        $this->blueStyle->okMessage("<fg=green>Keep</>: $keep");
        $this->showNewLine = true;

        return $hash;
    }

    /**
     * @param array $hash
     * @param int $index
     * @return array
     * @throws \Exception
     */
    protected function delete(array $hash, int $index): array
    {
        $this->remove($hash[$index]);
        unset($hash[$index]);

        return $hash;
    }

    /**
     * @param array $hash
     * @return array
     * @throws \Exception
     */
    protected function processDeletePolicy(array $hash): array
    {
        $deleteRules = false;
        foreach ($this->options[1]['delete_rule'] as $name => $rule) {
            if (empty($rule)) {
                continue;
            }

            $deleteRules = true;

            foreach ($hash as $index => $file) {
                $fileInfo = new SplFileInfo($file);

                $hash = $this->checkFileName($hash, $index, $name, $file, $rule, 'delete');
                $hash = $this->checkPath($hash, $index, $name, $file, $rule, 'delete');
                $hash = $this->checkDate($hash, $index, $name, $fileInfo, $rule, true, 'delete');
                $hash = $this->checkDate($hash, $index, $name, $fileInfo, $rule, false, 'delete');

                if ($name === 'permissions') {
                    $currentPermissions = \substr(\sprintf('%o', $fileInfo->getPerms()), -3);

                    if ($currentPermissions === $rule) {
                        $hash = $this->delete($hash, $index);
                    }
                }

                if ($name === 'owner' && \in_array($fileInfo->getOwner(), $rule)) {
                    $hash = $this->delete($hash, $index);
                }

                if ($name === 'group' && \in_array($fileInfo->getGroup(), $rule)) {
                    $hash = $this->delete($hash, $index);
                }
            }
        }

        if (!$deleteRules) {
            foreach (\array_keys($hash) as $index) {
                $hash = $this->delete($hash, $index);
            }
        }

        return $hash;
    }

    /**
     * @param string $file
     * @throws \Exception
     */
    public function remove(string $file): void
    {
        $out = [true];
        $this->copy($file);
        $size = \filesize($file);

        if (!$this->options[2]) {
            $out = Fs::delete($file);
        }

        if (reset($out)) {
            $this->blueStyle->okMessage("<fg=red>Removed</>: $file");
            $this->deleteCounter++;
            $this->deleteSizeCounter += $size;
        } else {
            $this->blueStyle->errorMessage("<fg=red>Removed</> failed: $file");
        }

        $this->showNewLine = true;
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
                $this->blueStyle->okMessage("<fg=blue>Copy</>: $file to $destination");
            } else {
                $this->blueStyle->errorMessage("<fg=blue>Copy</> failed: $file");
            }

            $this->showNewLine = true;
        }
    }
}
