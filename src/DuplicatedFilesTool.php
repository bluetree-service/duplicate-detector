<?php

declare(strict_types=1);

namespace DuplicateDetector;

use Symfony\Component\Console\{
    Input\InputInterface,
    Input\InputArgument,
    Input\InputOption,
    Output\OutputInterface,
    Helper\FormatterHelper,
    Helper\ProgressBar,
    Command\Command,
};
use BlueFilesystem\StaticObjects\{
    Structure,
    Fs
};
use BlueData\Data\Formats;
use BlueRegister\{
    Register,
    RegisterException,
};
use BlueConsole\Style;
use DuplicateDetector\Duplicated\{
    Strategy,
    Interactive,
    Name,
    AutoDel,
};
use React\{
    EventLoop\Factory,
    ChildProcess\Process,
    EventLoop\LoopInterface,
};
use Ramsey\Uuid\{
    Uuid,
    Exception\UnsatisfiedDependencyException,
};

class DuplicatedFilesTool extends Command
{
    public const TMP_DUMP_DIR = '/tmp/';
    public const DELETE_POLICY_EXAMPLE_FILE = __DIR__ . '/../etc/delete_policy.json';
    public const HTML_DIR = '/out';
    public const HTML_PAGE_SIZE = 100;

    /**
     * @var Register
     */
    protected Register $register;

    /**
     * @var InputInterface
     */
    protected InputInterface $input;

    /**
     * @var OutputInterface
     */
    protected OutputInterface $output;

    /**
     * @var Style
     */
    protected Style $blueStyle;

    /**
     * @var FormatterHelper
     */
    protected FormatterHelper $formatter;

    /**
     * @var string
     */
    protected string $messageFormat = ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%';

    /**
     * @var ProgressBar
     */
    protected ProgressBar $progressBar;

    protected int $deleteCounter = 0;
    protected int $deleteSizeCounter = 0;
    protected int $duplicatedFiles = 0;
    protected int $duplicatedFilesSize = 0;

    /**
     * @var array
     */
    protected array $dataPipe = [];

    public function __construct()
    {
        parent::__construct();

        $this->register = new Register();
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

        $this->addOption(
            'auto-delete',
            'd',
            null,
            'Automatically delete duplicated files. Files for delete depend of list of duplications.'
        );

        $this->addOption(
            'delete-backup',
            'b',
            InputArgument::OPTIONAL,
            'Instead of delete, all duplicated files are backup with directory structure.'
        );

        $this->addOption(
            'delete-policy',
            'D',
            InputArgument::OPTIONAL,
            'Path to file with delete policy for automatic delete.'
        );

        $this->addOption(
            'delete-policy-example',
            'E',
            InputArgument::OPTIONAL,
            'Return example file with delete policy rules.'
        );

        $this->addOption(
            'auto-delete-test',
            'T',
            null,
            'Test auto deletion. Proceed normally (apply rules, copy if required), but skip file delete.'
        );

        $this->addOption(
            'html',
            'H',
            InputOption::VALUE_OPTIONAL,
            'Save list of duplicated files as HTML documents (index.html + pages by '
                . self::HTML_PAGE_SIZE . ' duplications) in given directory.',
            false
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

        if ($this->input->getOption('delete-policy-example')) {
            $output->write(\file_get_contents(self::DELETE_POLICY_EXAMPLE_FILE));
            return;
        }

        $sources = $this->input->getArgument('source');
        if (empty($sources)) {
            $sources = ['/duplicates'];
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

        if ($this->input->getOption('auto-delete') && $this->input->getOption('interactive')) {
            throw new \InvalidArgumentException('Interactive & auto delete options are incompatible.');
        }

        if (
            !$this->input->getOption('auto-delete')
            && ($this->input->getOption('delete-policy') || $this->input->getOption('delete-backup'))
        ) {
            throw new \InvalidArgumentException('Delete policy & delete backup require auto delete option.');
        }

        $this->getDeletePolicy();

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

        if ($this->input->getOption('interactive') || $this->input->getOption('auto-delete')) {
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

    protected function getDeletePolicy(): void
    {
        if ($this->input->getOption('delete-policy')) {
            try {
                $dataPipe = pipe($this->input->getOption('delete-policy'))
                    ->fileGetContents
                    ->trim
                    ->jsonDecode(_, true, 512, JSON_THROW_ON_ERROR);

                $this->dataPipe = $dataPipe();
            } catch (\Throwable $exception) {
                $this->blueStyle->error(
                    "Error {$exception->getMessage()} - {$exception->getFile()}:{$exception->getLine()}"
                );
                exit;
            }
        }
    }

    /**
     * @param array $fileList
     * @param array $data
     * @param int $chunk
     * @return array
     * @throws \JsonException
     * @throws \JsonException
     */
    protected function useThreads(array $fileList, array $data, int $chunk): array
    {
        $hashFiles = [];
        $threads = $this->input->getOption('thread');
        $chunkValue = \ceil(\count($fileList) / $threads);

        if ($chunkValue > 0) {
            $fileList = \array_chunk($fileList, (int) $chunkValue);
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
                if (!$file) {
                    continue;
                }

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

            if ($this->input->getOption('skip-empty') && \filesize($file) === 0) {
                continue;
            }

            if ($this->input->getOption('check-by-name')) {
                $fileInfo = new \SplFileInfo($file);
                $name = $fileInfo->getFilename();
                $data['names'] = $name;
            } else {
                if ($chunk) {
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
     * @throws \JsonException
     * @throws \JsonException
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

        switch (true) {
            case $this->input->getOption('auto-delete'):
                $name = 'AutoDel';
                break;

            case !$this->input->getOption('interactive'):
                $name = 'NoInteractive';
                break;

            default:
                $name = 'Interactive';
                break;
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

        if ($strategy instanceof AutoDel) {
            $strategy->options = [
                $this->input->getOption('delete-backup'),
                $this->dataPipe,
                $this->input->getOption('auto-delete-test'),
            ];
        }

        $duplications = $this->duplicationsInfo($hashes);
        $left = $duplications;

        // false = option not used, null = used without value
        if ($this->input->getOption('html') !== false) {
            $this->saveHtmlReport($hashes, $this->input->getOption('html') ?? self::HTML_DIR);
        }

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
     * Files in group sorted by directory then name, groups sorted by their directory lists.
     *
     * @param array $hashes
     * @param string $dir
     * @throws \Exception
     */
    protected function saveHtmlReport(array $hashes, string $dir): void
    {
        $byDirectory = static function (string $first, string $second): int {
            return \strnatcasecmp(\dirname($first), \dirname($second))
                ?: \strnatcasecmp(\basename($first), \basename($second));
        };

        $groups = [];
        $totalFiles = 0;
        $totalSize = 0;

        foreach ($hashes as $hash => $files) {
            $files = \array_unique($files);
            \usort($files, $byDirectory);
            $totalFiles += \count($files);
            $groups[] = ['hash' => (string)$hash, 'files' => $files];
        }

        // compare directory lists: first directories, on tie second ones etc., then file names
        \usort($groups, static function (array $first, array $second) use ($byDirectory): int {
            $count = \min(\count($first['files']), \count($second['files']));

            for ($i = 0; $i < $count; $i++) {
                $result = \strnatcasecmp(\dirname($first['files'][$i]), \dirname($second['files'][$i]));

                if ($result !== 0) {
                    return $result;
                }
            }

            return \count($first['files']) <=> \count($second['files'])
                ?: $byDirectory($first['files'][0], $second['files'][0]);
        });

        if (!\is_dir($dir) && !@\mkdir($dir, 0777, true)) {
            $this->blueStyle->errorMessage("Unable to create HTML report directory: $dir");
            return;
        }

        foreach (\glob("$dir/duplicates-*.html") as $oldPage) {
            Fs::delete($oldPage);
        }

        $pages = \array_chunk($groups, self::HTML_PAGE_SIZE);
        $pagesCount = \count($pages);
        $pageName = static function (int $page): string {
            return \sprintf('duplicates-%04d.html', $page);
        };
        $indexRows = '';

        foreach ($pages as $index => $pageGroups) {
            $page = $index + 1;
            $body = '';
            $pageSize = 0;

            foreach ($pageGroups as $groupIndex => $group) {
                $number = $index * self::HTML_PAGE_SIZE + $groupIndex + 1;
                $rows = '';

                foreach ($group['files'] as $file) {
                    $size = (int)@\filesize($file);
                    $pageSize += $size;
                    $rows .= '<tr><td>' . \htmlspecialchars($file) . '</td><td class="size">'
                        . Formats::dataSize($size) . '</td></tr>';
                }

                $body .= "<h2>#$number <small>" . \htmlspecialchars($group['hash']) . '</small></h2>'
                    . "<table>$rows</table>";
            }

            $totalSize += $pageSize;
            $nav = '<p class="nav"><a href="index.html">index</a>'
                . ($page > 1 ? ' | <a href="' . $pageName($page - 1) . '">&lsaquo; previous</a>' : '')
                . " | page $page / $pagesCount"
                . ($page < $pagesCount ? ' | <a href="' . $pageName($page + 1) . '">next &rsaquo;</a>' : '')
                . '</p>';

            $this->writeHtml("$dir/{$pageName($page)}", "Duplicated files - page $page", $nav . $body . $nav);

            $firstDir = \htmlspecialchars(\dirname(\reset($pageGroups)['files'][0]));
            $lastDir = \htmlspecialchars(\dirname(\end($pageGroups)['files'][0]));
            $indexRows .= "<tr><td><a href=\"{$pageName($page)}\">page $page</a></td>"
                . "<td>$firstDir<br>$lastDir</td><td class=\"size\">" . Formats::dataSize($pageSize) . '</td></tr>';
        }

        $summary = '<p>Duplications: <b>' . \count($groups) . "</b>, duplicated files: <b>$totalFiles</b>, size: <b>"
            . Formats::dataSize($totalSize) . '</b>, generated: ' . \date('Y-m-d H:i:s') . '</p>';

        $this->writeHtml("$dir/index.html", 'Duplicated files', "$summary<table>$indexRows</table>");
        $this->blueStyle->infoMessage("HTML report saved: <info>$dir/index.html</> ($pagesCount pages)");
    }

    /**
     * @param string $path
     * @param string $title
     * @param string $body
     * @throws \Exception
     */
    protected function writeHtml(string $path, string $title, string $body): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>$title</title>
<style>
:root { color-scheme: dark; }
body { font-family: sans-serif; margin: 2em; background: #1e1f22; color: #d4d4d4; }
b { color: #fff; }
h2 { font-size: 1em; margin: 1.5em 0 .3em; }
h2 small { color: #8a8a8a; font-weight: normal; font-family: monospace; }
table { border-collapse: collapse; width: 100%; }
td { border: 1px solid #3a3b3f; padding: .3em .6em; font-family: monospace; word-break: break-all; }
td.size { width: 8em; text-align: right; white-space: nowrap; color: #9cdcfe; }
tr:nth-child(even) { background: #26272b; }
a { color: #6cb6ff; }
.nav { font-size: 1.1em; }
</style>
</head>
<body>
<h1>$title</h1>
$body
</body>
</html>
HTML;

        if (\file_put_contents($path, $html) === false) {
            $this->blueStyle->errorMessage("Unable to save HTML report: $path");
        }
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
