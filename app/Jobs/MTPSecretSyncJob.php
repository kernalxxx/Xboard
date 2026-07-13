<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class MTPSecretSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACTION_SYNC_LIMITS = 'sync_limits';
    public const ACTION_ROTATE = 'rotate';
    private const NO_EXPIRES = '0';

    public function __construct(
        private readonly string $label,
        private readonly ?int $ips = null,
        private readonly ?string $expires = null,
        private readonly string $action = self::ACTION_SYNC_LIMITS,
    ) {
        $this->onQueue('mtp_sync');
    }

    public static function dispatchLimitsForUserId(int $userId, int $ips, ?string $expires = null): void
    {
        self::dispatchLimits(self::labelForUserId($userId), $ips, $expires);
    }

    public static function dispatchRotate(string $label): void
    {
        self::dispatch($label, null, null, self::ACTION_ROTATE);
    }

    public static function dispatchRotateForUserId(int $userId): void
    {
        self::dispatchRotate(self::labelForUserId($userId));
    }

    public static function labelForUserId(int $userId): string
    {
        return sprintf('UID_%05d', $userId);
    }

    public static function expiresFromTimestamp(int $timestamp): string
    {
        return date('Y-m-d', $timestamp + (3 * 86400));
    }

    private static function dispatchLimits(string $label, int $ips, ?string $expires = null): void
    {
        self::dispatch($label, $ips, $expires, self::ACTION_SYNC_LIMITS);
    }

    public function handle(): void
    {
        $sshCommand = $this->mtpSshCommand();
        $remoteCommands = $this->remoteCommands();
        $command = array_merge($sshCommand, [$this->remoteScript($remoteCommands)]);
        $result = Process::run($command);

        if ($result->successful()) {
            Log::info('mtp command succeeded', [
                'remote_commands' => $remoteCommands,
                'label' => $this->label,
                'ips' => $this->ips,
                'expires' => $this->expires,
            ]);
            return;
        }

        Log::error('mtp command failed', [
            'remote_commands' => $remoteCommands,
            'label' => $this->label,
            'ips' => $this->ips,
            'expires' => $this->expires,
            'exit_code' => $result->exitCode(),
            'error_output' => $result->errorOutput(),
        ]);
    }

    private function remoteCommands(): array
    {
        if ($this->action === self::ACTION_ROTATE) {
            return [
                ['mtproxymax', 'secret', 'rotate', $this->label],
            ];
        }

        $expires = $this->expires ?? self::NO_EXPIRES;
        $setLimitsCommand = ['mtproxymax', 'secret', 'setlimits', $this->label, '0', (string) $this->ips, '0', $expires];

        return [
            ['mtproxymax', 'secret', 'add', $this->label],
            $setLimitsCommand,
        ];
    }

    private function remoteScript(array $remoteCommands): string
    {
        return implode(' ; ', array_map(
            fn(array $command) => $this->remoteCommandToString($command),
            $remoteCommands
        ));
    }

    private function remoteCommandToString(array $command): string
    {
        return implode(' ', array_map(
            fn(string $argument) => escapeshellarg($argument),
            $command
        ));
    }

    private function mtpSshCommand(): array
    {
        $host = (string) config('mtp.ssh.host');
        $port = (string) config('mtp.ssh.port');
        $user = (string) config('mtp.ssh.user');
        $keyPath = $this->expandHomePath((string) config('mtp.ssh.key_path'));

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
