<?php

namespace App\Services\Docker;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

class DockerRuntime
{
    /** @param list<string> $args Docker CLI args after the `docker` binary. */
    public function run(array $args, ?int $timeout = null, ?string $input = null): ProcessResult
    {
        $command = $this->command($args);
        $process = $this->pending($timeout);

        if ($input !== null) {
            return $process->input($input)->run($command);
        }

        return $process->run($command);
    }

    /** @param list<string> $args */
    public function start(array $args, ?string $input = null, ?int $timeout = null): \Illuminate\Process\InvokedProcess
    {
        $command = $this->command($args);
        $process = $timeout !== null ? Process::timeout($timeout) : Process::forever();

        if ($input !== null) {
            return $process->input($input)->start($command);
        }

        return $process->start($command);
    }

    public function containerRunning(string $name): bool
    {
        $result = $this->run(['inspect', '-f', '{{.State.Running}}', $name]);

        return $result->successful() && trim($result->output()) === 'true';
    }

    /** @param list<string> $command Command inside the container. */
    public function exec(string $container, array $command, ?int $timeout = null, ?string $input = null): ProcessResult
    {
        return $this->run(array_merge(['exec', $container], $command), $timeout, $input);
    }

    /** @param list<string> $command Command inside the container. */
    public function execInteractive(string $container, array $command, ?int $timeout = null, ?string $input = null): ProcessResult
    {
        return $this->run(array_merge(['exec', '-i', $container], $command), $timeout, $input);
    }

    /** @param list<string> $command Command inside the container. */
    public function execDetached(string $container, array $command): ProcessResult
    {
        return $this->run(array_merge(['exec', '-d', $container], $command));
    }

    public function restart(string $container, ?int $timeout = null): ProcessResult
    {
        return $this->run(['restart', $container], $timeout);
    }

    public function logs(string $container, int $tail = 80, ?int $timeout = null): ProcessResult
    {
        return $this->run(['logs', '--tail', (string) $tail, $container], $timeout);
    }

    public function stats(?int $timeout = null): ProcessResult
    {
        return $this->run(['stats', '--no-stream', '--format', '{{json .}}'], $timeout);
    }

    /** @param list<string> $args */
    private function command(array $args): array
    {
        return array_merge(['docker'], $args);
    }

    private function pending(?int $timeout): PendingProcess
    {
        return $timeout !== null ? Process::timeout($timeout) : Process::timeout(60);
    }
}
