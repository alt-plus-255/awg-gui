<?php

namespace Tests\Unit;

use App\Services\Docker\DockerRuntime;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DockerRuntimeTest extends TestCase
{
    public function test_container_running_parses_inspect_output(): void
    {
        Process::fake([
            'docker inspect -f {{.State.Running}} awggui-awg' => Process::result(output: "true\n"),
        ]);

        $runtime = new DockerRuntime;

        $this->assertTrue($runtime->containerRunning('awggui-awg'));
    }

    public function test_exec_builds_docker_exec_command(): void
    {
        Process::fake([
            '*' => Process::result(output: 'ok'),
        ]);

        $runtime = new DockerRuntime;
        $runtime->exec('awggui-awg', ['awg', 'genkey'], timeout: 10);

        Process::assertRan(function ($process) {
            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            return str_contains($command, 'docker exec awggui-awg awg genkey');
        });
    }

    public function test_exec_interactive_passes_stdin(): void
    {
        Process::fake([
            '*' => Process::result(output: 'pubkey'),
        ]);

        $runtime = new DockerRuntime;
        $runtime->execInteractive('awggui-awg', ['awg', 'pubkey'], timeout: 10, input: "private\n");

        Process::assertRan(function ($process) {
            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            return str_contains($command, 'docker exec -i awggui-awg awg pubkey');
        });
    }

    public function test_start_does_not_pass_null_timeout(): void
    {
        Process::fake([
            '*' => Process::result(output: '{"delay":42}'),
        ]);

        $runtime = new DockerRuntime;
        $invoked = $runtime->start(['exec', 'awggui-awg', 'curl', '-sS', 'http://127.0.0.1/'], timeout: 30);
        $invoked->wait();

        Process::assertRan(function ($process) {
            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            return str_contains($command, 'docker exec awggui-awg curl');
        });
    }
}
