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

        // Default to use pipes for STDIN and STDERR.
        $descr = array(
          0 => array(
            'pipe',
            'r'
          ),
          1 => $output,
          2 => array(
            'pipe',
            'w'
          )
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

        // On error, extract message from STDERR and throw an exception.
        if ($exit_code != 0) {
            $msg = stream_get_contents($pipes[2]);
            $this->cleanup($pipes, $process);
            if ($this->log) {
                $this->log->error('Process exited with non-zero code.', [
                  'exit_code' => $exit_code,
                  'stderr' => $msg,
                ]);
            }
            throw new \RuntimeException($msg, 500);
        }

        // Return a function that streams the output.
        return function () use ($pipes, $process, $output) {
            rewind($output);
            fpassthru($output);
            ob_flush();
            flush();
            $this->cleanup($pipes, $process);
        };
    }

    protected function cleanup($pipes, $process)
    {
        // Close STDERR
        fclose($pipes[2]);

        foreach ($this->toClose as $to_close) {
          fclose($to_close);
        }

        // Close the process
        proc_close($process);
    }
}
