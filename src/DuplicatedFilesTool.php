<?php

namespace DuplicateDetector;

use Symfony\Component\Console\{
    Input\InputInterface,
    Input\InputArgument,
    Output\OutputInterface,
    Helper\FormatterHelper,
    Helper\ProgressBar,
};
use Symfony\Component\Console\Command\Command;
use DuplicateDetector\Duplicated\Strategy;
use BlueFilesystem\StaticObjects\Structure;
use BlueFilesystem\StaticObjects\Fs;
use BlueData\Data\Formats;
use BlueRegister\{
    Register,
    RegisterException,
};
use BlueConsole\Style;
use DuplicateDetector\Duplicated\Interactive;
use DuplicateDetector\Duplicated\Name;
use React\{
    EventLoop\Factory,
    ChildProcess\Process,
    EventLoop\LoopInterface,
};
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

class DuplicatedFilesTool extends Command
{
    public const TMP_DUMP_DIR = __DIR__ . '/../var/tmp/dup/';

    /**
     * @var Register
     */
    protected $register;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var Style
     */
    protected $blueStyle;

    /**
     * @var FormatterHelper
     */
    protected $formatter;

    /**
     * @var string
     */
    protected $messageFormat = ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%';

    /**
     * @var ProgressBar
     */
    protected $progressBar;

    protected $deleteCounter = 0;
    protected $deleteSizeCounter = 0;
    protected $duplicatedFiles = 0;
    protected $duplicatedFilesSize = 0;

    /**
     * @param Register $register
     */
    public function __construct()
    {
        parent::__construct();
        $this->register = new Register;
    }

    /**
     * @return Style
     */
    public function getBlueStyle(): Style
    {
        return $this->blueStyle;
    }

    /**
     * @return InputInterface
     */
    public function getInput(): InputInterface
    {
        return $this->input;
    }

    protected function configure(): void
    {
        $this->setName('duplicate')
            ->setDescription('Search files duplication and make some action on it.')
            ->setHelp('');

        $this->addArgument(
            'source',
            InputArgument::IS_ARRAY,
            'source files to check'
        );

        $this->addOption(
            'interactive',
            'i',
            null,
            'show multi-checkbox with duplicated files, selected will be deleted'
        );

        $this->addOption(
            'skip-empty',
            's',
            null,
            'skip check if file is empty'
        );

        $this->addOption(
            'check-by-name',
            'N',
            InputArgument::OPTIONAL,
            'compare files using their file names. As arg give comparision parameter'
        );

        $this->addOption(
            'progress-info',
            'p',
            null,
            'Show massage on progress bar (like filename during hashing)'
        );

        $this->addOption(
            'thread',
            't',
            InputArgument::OPTIONAL,
            'Set number of threads used to calculate hash',
            0
        );

        $this->addOption(
            'size',
            'S',
            null,
            'First check files by size, before run hashing. Make checking faster.'
        );

        $this->addOption(
            'min-size',
            'm',
            InputArgument::OPTIONAL,
            'Set minimal size of files to be checked (in bytes).'
        );

        $this->addOption(
            'chunk',
            'c',
            InputArgument::OPTIONAL,
            'Use only given in bytes part of file to compare. Help with very large files.'
        );

        $this->addOption(
            'list-only',
            'l',
            null,
            'Show only list of duplicated files with their paths.'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;

        $sources = $this->input->getArgument('source');
        if (empty($sources)) {
            $sources = '/duplicates';
        }

        $oldDup = \glob(self::TMP_DUMP_DIR . '*.json');
        foreach ($oldDup as $dupJson) {
            Fs::delete($dupJson);
        }

        try {
            $this->formatter = $this->register->factory(FormatterHelper::class);
            $this->blueStyle = $this->register->factory(Style::class, [$this->input, $output, $this->formatter]);
        } catch (RegisterException $exception) {
            throw new \DomainException('RegisterException: ' . $exception->getMessage());
        }

        if ($this->input->getOption('list-only') && $this->input->getOption('interactive')) {
            throw new \InvalidArgumentException('Interactive & list only options are incompatible.');
        }

        $this->blueStyle->title('Check file duplications');
        $this->blueStyle->infoMessage('Reading directory.');

        $fileList = $this->readDirectories($sources);
        $allFiles = \count($fileList);
        $data = [
            'hashes' => [],
            'names' => [],
        ];

        $this->blueStyle->infoMessage("All files to check: <info>$allFiles</>");
        $this->blueStyle->infoMessage('Building file hash list.');

        $fileList = $this->filterBySize($fileList);
        $fileList = $this->checkBySize($fileList);

        if ($this->input->getOption('thread') > 0) {
            $data = $this->useThreads($fileList, $data, (int)$this->input->getOption('chunk'));
        } else {
            $data = $this->useSingleProcess($fileList, $data, (int)$this->input->getOption('chunk'));
        }

        $this->blueStyle->infoMessage('Compare files.');
        $this->duplicationCheckStrategy($data);

        if ($this->input->getOption('interactive')) {
            $this->blueStyle->infoMessage('Deleted files: <info>' . $this->deleteCounter . '</>');
            $this->blueStyle->infoMessage(
                'Deleted files size: <info>' . Formats::dataSize($this->deleteSizeCounter) . '</>'
            );
            $this->blueStyle->newLine(2);
        }

        if ($this->input->getOption('list-only')) {
            $this->blueStyle->newLine();
        }

        $this->blueStyle->infoMessage('Duplicated files: <info>' . $this->duplicatedFiles . '</>');
        $this->blueStyle->infoMessage(
            'Duplicated files size: <info>' . Formats::dataSize($this->duplicatedFilesSize) . '</>'
        );
        $this->blueStyle->newLine();
    }

    /**
     * @param array $fileList
     * @param array $data
     * @param int $chunk
     * @return array
     */
    protected function useThreads(array $fileList, array $data, int $chunk): array
    {
        $hashFiles = [];
        $threads = $this->input->getOption('thread');
        $chunkValue = \ceil(\count($fileList) / $threads);

        if ($chunkValue > 0) {
            $fileList = \array_chunk($fileList, $chunkValue);
        }

        $loop = Factory::create();

        $this->createProcesses($fileList, $loop, $data, $hashFiles, $chunk);
        $loop->run();

        foreach ($hashFiles as $hasFile) {
            Fs::delete($hasFile);
        }

        return $data;
    }

    /**
     * @param array $fileList
     * @return array
     * @throws \Exception
     */
    protected function checkBySize(array $fileList): array
    {
        $fileListBySize = [];

        if ($this->input->getOption('size')) {
            foreach ($fileList as $file) {
                $fileInfo = new \SplFileInfo($file);
                $fileListBySize[$fileInfo->getSize()][] = $file;
            }

            $fileList = [];

            foreach ($fileListBySize as $filesBySize) {
                if (\count($filesBySize) > 1) {
                    foreach ($filesBySize as $fileBySize) {
                        $fileList[] = $fileBySize;
                    }
                }
            }

            if ($fileList === []) {
                $this->blueStyle->warningMessage('Files with the same file size not found. No duplications.');
                return [];
            }
        }

        return $fileList;
    }

    /**
     * @param array $fileList
     * @return array
     * @throws \Exception
     */
    protected function filterBySize(array $fileList): array
    {
        if ($this->input->getOption('min-size')) {
            foreach ($fileList as $index => $file) {
                $fileInfo = new \SplFileInfo($file);

                if ($fileInfo->getSize() < (int)$this->input->getOption('min-size')) {
                    unset($fileList[$index]);
                }
            }
        }

        return $fileList;
    }

    /**
     * @param array $fileList
     * @param array $data
     * @param int $chunk
     * @return array
     * @throws \Exception
     */
    protected function useSingleProcess(array $fileList, array $data, int $chunk): array
    {
        try {
            $progressBar = $this->register->factory(ProgressBar::class, [$this->output]);
        } catch (RegisterException $exception) {
            throw new \DomainException('RegisterException: ' . $exception->getMessage());
        }

        $progressBar->start(\count($fileList));

        foreach ($fileList as $file) {
            $progressBar->advance();

            if ($this->input->getOption('progress-info')) {
                $progressBar->setMessage($file);
            }

            /** @noinspection ReturnFalseInspection */
            if ($this->input->getOption('skip-empty') && \filesize($file) === 0) {
                continue;
            }

            if ($this->input->getOption('check-by-name')) {
                $fileInfo = new \SplFileInfo($file);
                $name = $fileInfo->getFilename();
                $data['names'] = $name;
            } else {
                if ($chunk) {
                    /** @noinspection ReturnFalseInspection */
                    $content = \file_get_contents($file, false, null, 0, $chunk);
                    $hash = hash('sha3-256', $content);
                } else {
                    $hash = hash_file('sha3-256', $file);
                }

                $data['hashes'][$hash][] = $file;
            }
        }

        $progressBar->finish();
        echo "\r";

        return $data;
    }

    /**
     * @param array $processArrays
     * @param LoopInterface $loop
     * @param array $data
     * @param array $hashFiles
     * @param int $chunk
     * @return void
     */
    protected function createProcesses(
        array $processArrays,
        LoopInterface $loop,
        array &$data,
        array &$hashFiles,
        int $chunk
    ): void {
        $progressList = [];
        $counter = $this->input->getOption('thread');

        foreach ($processArrays as $thread => $processArray) {
            $hashes = \json_encode($processArray, JSON_THROW_ON_ERROR, 512);

            $uuid = $this->getUuid();
            $path = self::TMP_DUMP_DIR . "$uuid.json";
            $hashFiles[] = $path;
            /** @noinspection ReturnFalseInspection */
            \file_put_contents($path, $hashes);

            $dir = __DIR__;
            $first = new Process("php $dir/Duplicated/Hash.php $path $chunk");
            $first->start($loop);
            $self = $this;

            $first->stdout->on('data', static function ($chunk) use ($thread, &$progressList, $self) {
                $response = \json_decode($chunk, true);
                if ($response && isset($response['status']['all'], $response['status']['left'])) {
                    $status = $response['status'];
                    $progressList[$thread] = "Thread $thread - {$status['all']}/{$status['left']}";

                    $self->renderThreadInfo($progressList);

                    for ($i = 0; $i < $self->input->getOption('thread') - 1; $i++) {
                        echo Interactive::MOD_LINE_CHAR;
                    }
                }
            });

            $first->on('exit', static function ($code) use (&$data, $path, &$counter, $self, &$progressList, $thread) {
                $counter--;

                try {
                    $dataPipe = pipe($path)
                        ->fileGetContents
                        ->trim
                        ->jsonDecode(_, true, 512, JSON_THROW_ON_ERROR);
                    $data = \array_merge_recursive($data, $dataPipe());
                } catch (\Throwable $exception) {
                    $progressList[$thread] =
                        "Error {$exception->getMessage()} - {$exception->getFile()}:{$exception->getLine()}";
                }

                $progressList[$thread] = "Process <options=bold>$thread</> exited with code <info>$code</>";

                if ($counter === 0) {
                    $self->renderThreadInfo($progressList);

                    $self->blueStyle->newLine();
                }
            });
        }
    }

    /**
     * @param array $progressList
     * @throws \Exception
     */
    protected function renderThreadInfo(array $progressList): void
    {
        for ($i = 0; $i < $this->input->getOption('thread'); $i++) {
            if ($i > 0) {
                echo "\n";
            }

            $message = $progressList[$i] ?? '';
            echo "\r";

            /** @noinspection ReturnFalseInspection */
            if (\strpos($message, 'Error') === 0) {
                $this->blueStyle->errorMessage($message);
            } else {
                $this->blueStyle->infoMessage($message);
            }

            echo Interactive::MOD_LINE_CHAR;
        }
    }

    /**
     * @param array $paths
     * @return array
     * @throws \Exception
     */
    protected function readDirectories(array $paths): array
    {
        $fileList = [];

        foreach ($paths as $path) {
            try {
                /** @var Structure $structure */
                $structure = $this->register->factory(Structure::class, [$path, true]);
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $fileList = \array_merge($fileList, $structure->returnPaths()['file']);
            } catch (RegisterException $exception) {
                $this->blueStyle->errorMessage('RegisterException: ' . $exception->getMessage());
            }
        }

        return $fileList;
    }

    /**
     * @return string
     */
    protected function getUuid(): string
    {
        try {
            $uuid5 = Uuid::uuid4();

            return $uuid5->toString();
        } catch (\Exception | UnsatisfiedDependencyException $exception) {
            return \hash_file('sha3-256', \microtime(true));
        }
    }

    /**
     * @param array $list
     * @throws \Exception
     */
    protected function duplicationCheckStrategy(array $list): void
    {
        $hashes = $list['hashes'];
        $names = $list['names'];
        $name = 'Interactive';

        if (!$this->input->getOption('interactive')) {
            $name = 'No' . $name;
        }

        try {
            if ($this->input->getOption('check-by-name')) {
                /** @var Name $checkByName */
                $checkByName = $this->register->factory(Name::class);
                $hashes = $checkByName->checkByName($names, $hashes, $this->input->getOption('check-by-name'));
            }

            /** @var Strategy $strategy */
            $strategy = $this->register->factory('DuplicateDetector\Duplicated\\' . $name, [$this]);
        } catch (RegisterException $exception) {
            throw new \DomainException('RegisterException: ' . $exception->getMessage());
        }

        $duplications = $this->duplicationsInfo($hashes);
        $left = $duplications;

        foreach ($hashes as $hash) {
            if (\count($hash) > 1) {
                $strategy->checkByHash($hash);
                $left--;

                if ($left === 0) {
                    break;
                }

                if ($this->input->getOption('interactive')) {
                    $this->blueStyle->newLine();
                    $this->blueStyle->infoMessage("Duplication <options=bold>$left</> of <info>$duplications</>");
                }
            }
        }

        [$this->duplicatedFilesSize, $this->deleteCounter, $this->deleteSizeCounter] = $strategy->returnCounters();
    }

    /**
     * @param array $hashes
     * @return int
     * @throws \Exception
     */
    protected function duplicationsInfo(array &$hashes): int
    {
        $duplications = 0;

        foreach ($hashes as $index => $hash) {
            $hash = \array_unique($hash);

            if (\count($hash) > 1) {
                $duplications++;
                $this->duplicatedFiles += \count($hash);
            } else {
                unset($hashes[$index]);
            }
        }

        $this->blueStyle->infoMessage("Duplicated files: <info>$this->duplicatedFiles</>");
        $this->blueStyle->infoMessage("Duplications: <info>$duplications</>");

        if ($this->input->getOption('list-only')) {
            $this->blueStyle->newLine();
        }

        return $duplications;
    }
}
