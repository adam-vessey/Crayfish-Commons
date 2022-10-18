<?php

namespace Islandora\Crayfish\Commons;

use Psr\Log\LoggerInterface;

/**
 * Runs a command streaming data in on stdin and out on stdout.
 *
 * @package Islandora\Crayfish\Commons
 */
class CmdExecuteService
{

    /**
     * @var null|\Psr\Log\LoggerInterface
     */
    protected $log;

    /**
     * @var resource
     */
    protected $output;

    /**
     * Array of resource to be fclose()'d on ::cleanup().
     * @var resource[]
     */
    protected $toClose;

    /**
     * Executor constructor.
     * @param LoggerInterface $log
     */
    public function __construct(LoggerInterface $log = null)
    {
        $this->log = $log;
        $this->toClose = [];
    }

    /**
     * $output getter.
     *
     * @return resource;
     */
    public function getOutputStream()
    {
        return $this->output;
    }

    /**
     * Runs the command
     *
     * @param $cmd
     * @param $data
     *
     * @throws \RuntimeException
     *
     * @return \Closure
     *   Closure that streams the output of the command.
     */
    public function execute($cmd, $data)
    {
        $this->output = $output = fopen("php://temp", 'w+b');
        $this->toClose[] = $output;
        $error = fopen("php://temp", 'w+b');
        $this->toClose[] = $error;

        $descr = array(
          1 => $output,
          2 => $error,
        );

        if (gettype($data) == "resource") {
            // Use our passed resource instead of the pipe.
            $descr[0] = $data;
            $this->toClose[] = $data;
        }

        /**
         * To receive the array of references from proc_open().
         *
         * @var resource[]
         */
        $pipes = [];

        // Start process.
        $cmd = escapeshellcmd($cmd);
        $process = proc_open($cmd, $descr, $pipes);

        $exit_code = proc_close($process);

        // On error, extract message from STDERR and throw an exception.
        if ($exit_code != 0) {
            rewind($error);
            $msg = stream_get_contents($error);
            $this->cleanup();
            if ($this->log) {
                $this->log->error('Process exited with non-zero code.', [
                  'exit_code' => $exit_code,
                  'stderr' => $msg,
                ]);
            }
            throw new \RuntimeException($msg, 500);
        }

        // Return a function that streams the output.
        return function () use ($output) {
            rewind($output);
            fpassthru($output);
            ob_flush();
            flush();
            $this->cleanup();
        };
    }

    protected function cleanup()
    {
        foreach ($this->toClose as $to_close) {
          fclose($to_close);
        }
    }
}
