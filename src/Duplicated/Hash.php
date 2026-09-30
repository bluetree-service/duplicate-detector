<?php

[$session, $chunk, $thread, $delay] = $argv;
$redis = null;

try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6378);
    $verboseContent = '';
    $hashes = [];
    $done = 0;

    usleep($delay * 1000);

    while ($file = $redis->lPop("$session-paths")) {
        //@todo    if ($this->input->getOption('skip-empty') && filesize($file) === 0) {
        //@todo    if ($this->input->getOption('check-by-name')) {
        $redis->incr("$session-hashes-processed");
        $done++;

        try {
            if ($chunk) {
                $content = file_get_contents($file, false, null, 0, $chunk);
                $hash = hash('sha3-256', $content);
            } else {
                $hash = hash_file('sha3-256', $file);
            }
        } catch (Throwable $exception) {
            $redis->sAdd("$session-errors", $exception->getMessage());
            echo json_encode(['status' => ['done' => $done . $verboseContent]], JSON_THROW_ON_ERROR);
            continue;
        }

        echo json_encode(['status' => ['done' => $done . $verboseContent]], JSON_THROW_ON_ERROR);

        $hashes = $redis->hGet("$session-hashes", "thread-$thread");

        if ($hashes) {
            $hashes = json_decode($hashes, true);
        }

        $hashes[$hash][] = $file;

        $redis->hSet("$session-hashes", "thread-$thread", json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    echo '{"status": {"message": "ok ' . $done . $verboseContent . '"}}';
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    $redis?->sAdd("$session-errors", $exception->getMessage());
    echo "{\"status\":\"error\",\"message\": \"$message\"}";
}

usleep($delay * 1000);
