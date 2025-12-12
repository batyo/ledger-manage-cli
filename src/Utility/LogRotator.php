<?php

namespace App\Utility;

class LogRotator
{
    /**
     * 必要に応じてログをローテーションする
     *
     * @param string $logFile
     * @param int $maxSize バイト
     * @throws \RuntimeException
     */
    public static function rotateIfNeeded(string $logFile, int $maxSize): void
    {
        if (!file_exists($logFile)) {
            return;
        }
        clearstatcache(true, $logFile);
        $size = filesize($logFile);
        if ($size === false || $size < $maxSize) {
            return;
        }

        $dateSuffix = date('Ymd_His');;
        $rotatedFile = $logFile . '.' . $dateSuffix;

        if (!rename($logFile, $rotatedFile)) {
            throw new \RuntimeException("Failed to rotate log file {$logFile}");
        }
    }

    /**
     * ローテーションを行った上で安全にログを書き込む
     *
     * @param string $msg
     * @param string $logFile
     * @param int $maxSize
     * @throws \RuntimeException
     */
    public static function safeWrite(string $msg, string $logFile, int $maxSize): void
    {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create log directory: {$dir}");
            }
        }

        self::rotateIfNeeded($logFile, $maxSize);

        error_log($msg, 3, $logFile);
    }
}
