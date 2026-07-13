<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;

class MtproxyMaxSecretSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $label,
        private readonly int $ips,
        private readonly string $expires,
    ) {
        $this->onQueue('mtproxy_sync');
    }

    public function handle(): void
    {
        $sshCommand = $this->mtproxyMaxSshCommand();

        $remoteCommands = [
            ['mtproxymax', 'secret', 'add', $this->label],
            ['mtproxymax', 'secret', 'setlimits', $this->label, '0', (string) $this->ips, '0', $this->expires],
        ];

        foreach ($remoteCommands as $remoteCommand) {
            $command = array_merge($sshCommand, $remoteCommand);
            Process::run($command);
        }
    }

    private function mtproxyMaxSshCommand(): array
    {
        $host = (string) config('mtproxymax.ssh.host');
        $port = (string) config('mtproxymax.ssh.port');
        $user = (string) config('mtproxymax.ssh.user');
        $keyPath = $this->expandHomePath((string) config('mtproxymax.ssh.key_path'));

        return [
            'ssh',
            '-i',
            $keyPath,
            '-p',
            (string) $port,
            '-o',
            'BatchMode=yes',
            '-o',
            'StrictHostKeyChecking=accept-new',
            '-o',
            'ConnectTimeout=10',
            "{$user}@{$host}",
        ];
    }

    private function expandHomePath(string $path): string
    {
        if (!str_starts_with($path, '~/')) {
            return $path;
        }

        $home = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: ''), '/');
        if ($home === '') {
            return $path;
        }

        return $home . substr($path, 1);
    }
}
