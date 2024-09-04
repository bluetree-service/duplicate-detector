<?php

namespace BlueFilesystemTest;

use PHPUnit\Framework\TestCase;

class BaseTest extends TestCase
{
    public function setUp(): void
    {
        \shell_exec('    chmod -R 0777 ' . StaticFsDelTest::TEST_DIR . ' > /dev/null 2>&1');
        \shell_exec('rm -r ' . StaticFsDelTest::TEST_DIR . 'copy > /dev/null 2>&1');
    }

    //@todo copy file exceptions

    public function testCopyDirSuccess(): void
    {
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/2/2-1/2-1-1/file');
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/1/1-1/1-1-1/file2');
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/1/1-1/1-1-1');

        $out = Fs::copy(__DIR__ . '/test-dirs/del', StaticFsDelTest::TEST_DIR . 'copy');

        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy/2/2-1/2-1-1/file');
        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy/1/1-1/1-1-1/file2');
        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy/1/1-1/1-1-1');

        $dir1 = StaticFsDelTest::TEST_DIR . 'copy';
        $dir2 = __DIR__ . '/test-dirs/del';

        $this->assertTrue(Fs::validateComplexOutput($out));
        $this->assertEquals(
            [
                'mkdir:' . $dir1  => true,
                'mkdir:' . $dir1 . '/2' => true,
                'mkdir:' . $dir1 . '/2/2-1' => true,
                'mkdir:' . $dir1 . '/2/2-1/2-1-2' => true,
                'mkdir:' . $dir1 . '/2/2-1/2-1-1' => true,
                'mkdir:' . $dir1 . '/1' => true,
                'mkdir:' . $dir1 . '/1/1-1' => true,
                'mkdir:' . $dir1 . '/1/1-1/1-1-1' => true,
                'copy:' . $dir2 . '/1/1-1/1-1-1/file:' . $dir1 . '/1/1-1/1-1-1/file' => true,
                'copy:' . $dir2 . '/1/1-1/1-1-1/file2:' . $dir1 . '/1/1-1/1-1-1/file2' => true,
                'copy:' . $dir2 . '/file:' . $dir1 . '/file' => true,
                'copy:' . $dir2 . '/2/2-1/2-1-1/file:' . $dir1 . '/2/2-1/2-1-1/file' => true,
                'copy:' . $dir2 . '/2/2-1/2-1-2/file:' . $dir1 . '/2/2-1/2-1-2/file' => true,
                'copy:' . $dir2 . '/2/2-1/file:' . $dir1 . '/2/2-1/file' => true,
            ],
            $out
        );
    }

    public function testCopyWithError(): void
    {
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/2/2-1/2-1-1/file');
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/1/1-1/1-1-1/file2');
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/1/1-1/1-1-1');

        \chmod(StaticFsDelTest::TEST_DIR, 0555);

        $out = Fs::copy(__DIR__ . '/test-dirs/del', StaticFsDelTest::TEST_DIR . 'copy');

        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/2/2-1/2-1-1/file');
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/1/1-1/1-1-1/file2');
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/1/1-1/1-1-1');

        $dir1 = StaticFsDelTest::TEST_DIR . 'copy';
        $dir2 = __DIR__ . '/test-dirs/del';

        $this->assertFalse(Fs::validateComplexOutput($out));
        $this->assertEquals(
            [
                'mkdir:' . $dir1  => 'mkdir(): Permission denied',
                'mkdir:' . $dir1 . '/2' => 'mkdir(): No such file or directory',
                'mkdir:' . $dir1 . '/2/2-1' => 'mkdir(): No such file or directory',
                'mkdir:' . $dir1 . '/2/2-1/2-1-2' => 'mkdir(): No such file or directory',
                'mkdir:' . $dir1 . '/2/2-1/2-1-1' => 'mkdir(): No such file or directory',
                'mkdir:' . $dir1 . '/1' => 'mkdir(): No such file or directory',
                'mkdir:' . $dir1 . '/1/1-1' => 'mkdir(): No such file or directory',
                'mkdir:' . $dir1 . '/1/1-1/1-1-1' => 'mkdir(): No such file or directory',
                'copy:' . $dir2 . '/1/1-1/1-1-1/file:' . $dir1 . '/1/1-1/1-1-1/file' => 'copy(' . $dir1 . '/1/1-1/1-1-1/file): failed to open stream: No such file or directory',
                'copy:' . $dir2 . '/1/1-1/1-1-1/file2:' . $dir1 . '/1/1-1/1-1-1/file2' => 'copy(' . $dir1 . '/1/1-1/1-1-1/file2): failed to open stream: No such file or directory',
                'copy:' . $dir2 . '/file:' . $dir1 . '/file' => 'copy(' . $dir1 . '/file): failed to open stream: No such file or directory',
                'copy:' . $dir2 . '/2/2-1/2-1-1/file:' . $dir1 . '/2/2-1/2-1-1/file' => 'copy(' . $dir1 . '/2/2-1/2-1-1/file): failed to open stream: No such file or directory',
                'copy:' . $dir2 . '/2/2-1/2-1-2/file:' . $dir1 . '/2/2-1/2-1-2/file' => 'copy(' . $dir1 . '/2/2-1/2-1-2/file): failed to open stream: No such file or directory',
                'copy:' . $dir2 . '/2/2-1/file:' . $dir1 . '/2/2-1/file' => 'copy(' . $dir1 . '/2/2-1/file): failed to open stream: No such file or directory',
                'copy:' . $dir2 . '/2/2-1/file:' . $dir1 . '/2/2-1/file' => 'copy(' . $dir1 . '/2/2-1/file): failed to open stream: No such file or directory',
            ],
            $out
        );
    }

    public function testCopyDirWhenTargetExists(): void
    {
        \mkdir(StaticFsDelTest::TEST_DIR . 'copy');

        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/del/2/2-1/2-1-1/file');
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/del/1/1-1/1-1-1/file2');
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy/del/1/1-1/1-1-1');

        $out = Fs::copy(__DIR__ . '/test-dirs/del', StaticFsDelTest::TEST_DIR . 'copy');

        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy/del/2/2-1/2-1-1/file');
        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy/del/1/1-1/1-1-1/file2');
        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy/del/1/1-1/1-1-1');

        $dir1 = StaticFsDelTest::TEST_DIR . 'copy/del';
        $dir2 = __DIR__ . '/test-dirs/del';

        $this->assertTrue(Fs::validateComplexOutput($out));
        $this->assertEquals(
            [
                'mkdir:' . $dir1  => true,
                'mkdir:' . $dir1 . '/2' => true,
                'mkdir:' . $dir1 . '/2/2-1' => true,
                'mkdir:' . $dir1 . '/2/2-1/2-1-2' => true,
                'mkdir:' . $dir1 . '/2/2-1/2-1-1' => true,
                'mkdir:' . $dir1 . '/1' => true,
                'mkdir:' . $dir1 . '/1/1-1' => true,
                'mkdir:' . $dir1 . '/1/1-1/1-1-1' => true,
                'copy:' . $dir2 . '/1/1-1/1-1-1/file:' . $dir1 . '/1/1-1/1-1-1/file' => true,
                'copy:' . $dir2 . '/1/1-1/1-1-1/file2:' . $dir1 . '/1/1-1/1-1-1/file2' => true,
                'copy:' . $dir2 . '/file:' . $dir1 . '/file' => true,
                'copy:' . $dir2 . '/2/2-1/2-1-1/file:' . $dir1 . '/2/2-1/2-1-1/file' => true,
                'copy:' . $dir2 . '/2/2-1/2-1-2/file:' . $dir1 . '/2/2-1/2-1-2/file' => true,
                'copy:' . $dir2 . '/2/2-1/file:' . $dir1 . '/2/2-1/file' => true,
            ],
            $out
        );
    }

    public function testSingleFile(): void
    {
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy');

        $out = Fs::copy(__DIR__ . '/test-dirs/del/file', StaticFsDelTest::TEST_DIR . 'copy');

        $this->assertTrue(Fs::validateComplexOutput($out));
        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy');
        $this->assertEquals(
            [
                'copy:' . __DIR__ . '/test-dirs/del/file:' . StaticFsDelTest::TEST_DIR . 'copy' => true
            ],
            $out
        );
    }

    public function testSingleFileWithError(): void
    {
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy');
        \shell_exec('    chmod -R 0555 ' . StaticFsDelTest::TEST_DIR . ' > /dev/null 2>&1');

        $out = Fs::copy(__DIR__ . '/test-dirs/del/file', StaticFsDelTest::TEST_DIR . 'copy');

        $this->assertFalse(Fs::validateComplexOutput($out));
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy');
        $this->assertEquals(
            [
                'copy:' . __DIR__ . '/test-dirs/del/file:' . StaticFsDelTest::TEST_DIR . 'copy'
                    => 'copy(' . StaticFsDelTest::TEST_DIR . 'copy' . '): failed to open stream: Permission denied'
            ],
            $out
        );
    }

    public function testSingleFileExists(): void
    {
        $this->assertFileNotExists(StaticFsDelTest::TEST_DIR . 'copy');
        \file_put_contents(StaticFsDelTest::TEST_DIR . 'copy', 'test');
        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy');
        $this->assertEquals('test', \file_get_contents(StaticFsDelTest::TEST_DIR . 'copy'));

        $out = Fs::copy(__DIR__ . '/test-dirs/del/file', StaticFsDelTest::TEST_DIR . 'copy');

        $this->assertTrue(Fs::validateComplexOutput($out));
        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy');
        $this->assertEquals(
            [
                'copy:' . __DIR__ . '/test-dirs/del/file:' . StaticFsDelTest::TEST_DIR . 'copy' => true
            ],
            $out
        );

        $this->assertFileExists(StaticFsDelTest::TEST_DIR . 'copy');
        $this->assertEquals('', \file_get_contents(StaticFsDelTest::TEST_DIR . 'copy'));
    }

    public function testCopyWhenSourceDontExists(): void
    {
        $out = Fs::copy('din-not-exists', StaticFsDelTest::TEST_DIR . 'copy');
        $this->assertEmpty($out);
        $this->assertFalse(Fs::validateComplexOutput($out));
    }

    public function testCopyWithEvents(): void
    {
        $exceptionsExecutions = 0;
        $afterExecutions = 0;
        $beforeExecutions = 0;
        $exceptionsContentExecutions = 0;
        $beforePathsExecutions = 0;

        $eventDispatcher = new EventDispatcher;
        $eventDispatcher->setEventConfiguration([
            FsInterface::COPY_CREATE_PATH_EXCEPTION => [
                'object' => 'BlueEvent\Event\BaseEvent',
                'listeners' => [
                    function () use (&$exceptionsExecutions) {
                        $exceptionsExecutions++;
                    },
                ],
            ],
            FsInterface::COPY_PATH_CONTENT_EXCEPTION => [
                'object' => 'BlueEvent\Event\BaseEvent',
                'listeners' => [
                    function () use (&$exceptionsContentExecutions) {
                        $exceptionsContentExecutions++;
                    },
                ],
            ],
            FsInterface::COPY_PATH_CONTENT_BEFORE => [
                'object' => 'BlueEvent\Event\BaseEvent',
                'listeners' => [
                    function () use (&$beforeExecutions) {
                        $beforeExecutions++;
                    },
                ],
            ],
            FsInterface::COPY_PATH_CONTENT_AFTER => [
                'object' => 'BlueEvent\Event\BaseEvent',
                'listeners' => [
                    function () use (&$afterExecutions) {
                        $afterExecutions++;
                    },
                ],
            ],
            FsInterface::COPY_PATHS_BEFORE => [
                'object' => 'BlueEvent\Event\BaseEvent',
                'listeners' => [
                    function () use (&$beforePathsExecutions) {
                        $beforePathsExecutions++;
                    },
                ],
            ],
        ]);

        Fs::configureEventHandler($eventDispatcher);

        $this->testCopyWithError();

        $this->assertEquals(9, $exceptionsExecutions);
        $this->assertEquals(1, $afterExecutions);
        $this->assertEquals(1, $beforeExecutions);
        $this->assertEquals(6, $exceptionsContentExecutions);
        $this->assertEquals(1, $beforePathsExecutions);
    }

    public function tearDown(): void
    {
        $this->setUp();
    }
}
