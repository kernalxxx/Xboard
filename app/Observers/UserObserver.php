<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TrafficResetService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class UserObserver
{
  public bool $afterCommit = true;

  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
  }

  public function updated(User $user): void
  {
    // With $afterCommit = true, isDirty() is always false after commit.
    // Use wasChanged() to detect what was actually modified.
    $syncFields = ['group_id', 'uuid', 'speed_limit', 'device_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'plan_id'];
    $needsSync = $user->wasChanged($syncFields);
    $oldGroupId = $user->wasChanged('group_id') ? $user->getOriginal('group_id') : null;

    if ($user->wasChanged('expired_at')) {
      $this->callExpiredAtChangedHook($user);
    }

    if ($user->wasChanged(['plan_id', 'expired_at'])) {
      $this->recalculateNextResetAt($user);
    }

    if ($needsSync) {
      NodeUserSyncJob::dispatch($user->id, 'updated', $oldGroupId);
    }
  }

  public function created(User $user): void
  {
    $this->callExpiredAtChangedHook($user);

    $this->recalculateNextResetAt($user);
    NodeUserSyncJob::dispatch($user->id, 'created');
  }

  public function deleted(User $user): void
  {
    if ($user->group_id) {
      NodeUserSyncJob::dispatch($user->id, 'deleted', $user->group_id);
    }
  }

  /**
   * 根据当前用户状态重新计算 next_reset_at
   */
  private function recalculateNextResetAt(User $user): void
  {
    $user->refresh();
    User::withoutEvents(function () use ($user) {
      $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
      $user->next_reset_at = $nextResetTime?->timestamp;
      $user->save();
    });
  }

  private function callExpiredAtChangedHook(User $user): void
  {
    if ($user->expired_at === null) {
      return;
    }

    $label = sprintf('UID_%05d', $user->id);
    $ips = (int) ($user->device_limit ?? 0);
    $expires = date('Y-m-d', $user->expired_at + (3 * 86400));

    HookManager::call('user.subscription.expiry.changed', [
      'label' => $label,
      'ips' => $ips,
      'expires' => $expires,
    ]);

    $this->syncMtproxyMaxSecret($label, $ips, $expires);
  }

  private function syncMtproxyMaxSecret(string $label, int $ips, string $expires): void
  {
    $sshCommand = $this->mtproxyMaxSshCommand();

    $remoteCommands = [
      ['mtproxymax', 'secret', 'add', $label],
      ['mtproxymax', 'secret', 'setlimits', $label, '0', (string) $ips, '0', $expires],
    ];

    foreach ($remoteCommands as $remoteCommand) {
      $command = array_merge($sshCommand, $remoteCommand);
      $result = Process::run($command);

      if ($result->successful()) {
        Log::info('mtproxymax command succeeded', [
          'remote_command' => $remoteCommand,
          'label' => $label,
          'ips' => $ips,
          'expires' => $expires,
        ]);
        continue;
      }

      Log::error('mtproxymax command failed', [
        'remote_command' => $remoteCommand,
        'label' => $label,
        'ips' => $ips,
        'expires' => $expires,
        'exit_code' => $result->exitCode(),
        'error_output' => $result->errorOutput(),
      ]);
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
