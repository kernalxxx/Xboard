<?php

namespace App\Http\Controllers\V1\Client;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Protocols\General;
use App\Jobs\MTPSecretSyncJob;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class ClientController extends Controller
{
    /**
     * Protocol prefix mapping for server names
     */
    private const PROTOCOL_PREFIXES = [
        'hysteria' => [
            1 => '[Hy]',
            2 => '[Hy2]'
        ],
        'vless' => '[vless]',
        'shadowsocks' => '[ss]',
        'vmess' => '[vmess]',
        'trojan' => '[trojan]',
        'tuic' => '[tuic]',
        'socks' => '[socks]',
        'anytls' => '[anytls]'
    ];


    public function subscribe(Request $request)
    {
        HookManager::call('client.subscribe.before');
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'flag' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $userService = new UserService();

        if (!$userService->isAvailable($user)) {
            HookManager::call('client.subscribe.unavailable');
            return response('', 403, ['Content-Type' => 'text/plain']);
        }

        $clientInfo = $this->getClientInfo($request);
        if (($clientInfo['name'] ?? null) === 'telegram') {
            return $this->telegramSubscribeLink($user);
        }

        return $this->doSubscribe($request, $user, null, $clientInfo);
    }

    public function doSubscribe(Request $request, $user, $servers = null, array $clientInfo = [])
    {
        if ($servers === null) {
            $servers = ServerService::getAvailableServers($user);
            $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
        }

        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));

        $protocolClassName = app('protocols.manager')->matchProtocolClassName($clientInfo['flag'])
            ?? General::class;

        $serversFiltered = $this->filterServers(
            servers: $servers,
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );

        $this->setSubscribeInfoToServers($serversFiltered, $user, count($servers) - count($serversFiltered));
        $serversFiltered = $this->addPrefixToServerName($serversFiltered);

        // Instantiate the protocol class with filtered servers and client info
        $protocolInstance = app()->make($protocolClassName, [
            'user' => $user,
            'servers' => $serversFiltered,
            'clientName' => $clientInfo['name'] ?? null,
            'clientVersion' => $clientInfo['version'] ?? null,
            'userAgent' => $clientInfo['flag'] ?? null
        ]);

        return $protocolInstance->handle();
    }

    /**
     * Parses the input string for requested server types.
     */
    private function parseRequestedTypes(?string $typeInputString): array
    {
        if (blank($typeInputString) || $typeInputString === 'all') {
            return Server::VALID_TYPES;
        }

        $requested = collect(preg_split('/[|,｜]+/', $typeInputString))
            ->map(fn($type) => trim($type))
            ->filter() // Remove empty strings that might result from multiple delimiters
            ->all();

        return array_values(array_intersect($requested, Server::VALID_TYPES));
    }

    /**
     * Parses the input string for filter keywords.
     */
    private function parseFilterKeywords(?string $filterInputString): ?array
    {
        if (blank($filterInputString) || mb_strlen($filterInputString) > 20) {
            return null;
        }

        return collect(preg_split('/[|,｜]+/', $filterInputString))
            ->map(fn($keyword) => trim($keyword))
            ->filter() // Remove empty strings
            ->all();
    }

    /**
     * Filters servers based on allowed types and keywords.
     */
    private function filterServers(array $servers, array $allowedTypes, ?array $filterKeywords): array
    {
        return collect($servers)->filter(function ($server) use ($allowedTypes, $filterKeywords) {
            // Condition 1: Server type must be in the list of allowed types
            if ($allowedTypes && !in_array($server['type'], $allowedTypes)) {
                return false; // Filter out (don't keep)
            }

            // Condition 2: If filterKeywords are provided, at least one keyword must match
            if (!empty($filterKeywords)) { // Check if $filterKeywords is not empty
                $keywordMatch = collect($filterKeywords)->contains(function ($keyword) use ($server) {
                    return stripos($server['name'], $keyword) !== false
                        || in_array($keyword, $server['tags'] ?? []);
                });
                if (!$keywordMatch) {
                    return false; // Filter out if no keywords match
                }
            }
            // Keep the server if its type is allowed AND (no filter keywords OR at least one keyword matched)
            return true;
        })->values()->all();
    }

    private function getClientInfo(Request $request): array
    {
        $requestFlag = strtolower(trim((string) $request->input('flag', '')));
        $userAgent = strtolower((string) $request->header('User-Agent', ''));

        if ($requestFlag === 'telegram') {
            return $this->telegramClientInfo();
        }

        $flag = $requestFlag ?: $userAgent;

        $clientName = null;
        $clientVersion = null;

        if (preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/', $flag, $matches)) {
            $potentialName = strtolower($matches[1]);
            $clientVersion = preg_replace('/^v/', '', $matches[2]);

            if (in_array($potentialName, app('protocols.flags'))) {
                $clientName = $potentialName;
            }
        }

        if (!$clientName) {
            $flags = collect(app('protocols.flags'))->sortByDesc(fn($f) => strlen($f))->values()->all();
            foreach ($flags as $name) {
                if (stripos($flag, $name) !== false) {
                    $clientName = $name;
                    if (!$clientVersion) {
                        $pattern = '/' . preg_quote($name, '/') . '[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/i';
                        if (preg_match($pattern, $flag, $vMatches)) {
                            $clientVersion = preg_replace('/^v/', '', $vMatches[1]);
                        }
                    }
                    break;
                }
            }
        }

        if (!$clientVersion) {
            if (preg_match('/\/v?(\d+(?:\.\d+){0,2})/', $flag, $matches)) {
                $clientVersion = $matches[1];
            }
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion
        ];
    }

    private function telegramClientInfo(): array
    {
        return [
            'flag' => 'telegram',
            'name' => 'telegram',
            'version' => null,
        ];
    }

    private function telegramSubscribeLink($user)
    {
        $label = MTPSecretSyncJob::labelForUserId($user->id);
        $secret = $this->resolveMTPSecret($label);

        return redirect()->away($this->buildMTPRedirectUrl($secret), 301);
    }

    private function resolveMTPSecret(string $label): string
    {
        $command = $this->buildMtpSshCommand(sprintf('mtproxymax secret link %s', $label));
        $result = Process::run($command);

        if (!$result->successful()) {
            throw new ApiException('Telegram proxy link generation failed');
        }

        $output = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', trim($result->output()));
        if (!preg_match('/secret=([^&\s]+)/', $output, $matches)) {
            throw new ApiException('Telegram proxy secret not found');
        }

        return $matches[1];
    }

    private function buildMTPRedirectUrl(string $secret): string
    {
        $serverHost = (string) config('mtp.server.host');
        $serverPort = (string) config('mtp.server.port');

        if (blank($serverHost) || blank($serverPort)) {
            throw new ApiException('MTP server is not configured');
        }

        return 'tg://proxy?' . http_build_query([
            'server' => $serverHost,
            'port' => $serverPort,
            'secret' => $secret,
        ]);
    }

    private function buildMtpSshCommand(string $remoteCommand): array
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
            $remoteCommand,
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

    private function setSubscribeInfoToServers(&$servers, $user, $rejectServerCount = 0)
    {
        if (!isset($servers[0]))
            return;
        if ($rejectServerCount > 0) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "过滤掉{$rejectServerCount}条线路",
            ]));
        }
        if (!(int) admin_setting('show_info_to_server_enable', 0))
            return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : __('长期有效');
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }

    private function addPrefixToServerName(array $servers): array
    {
        if (!admin_setting('show_protocol_to_server_enable', false)) {
            return $servers;
        }
        return collect($servers)
            ->map(function (array $server): array {
                $server['name'] = $this->getPrefixedServerName($server);
                return $server;
            })
            ->all();
    }

    private function getPrefixedServerName(array $server): string
    {
        $type = $server['type'] ?? '';
        if (!isset(self::PROTOCOL_PREFIXES[$type])) {
            return $server['name'] ?? '';
        }
        $prefix = is_array(self::PROTOCOL_PREFIXES[$type])
            ? self::PROTOCOL_PREFIXES[$type][$server['protocol_settings']['version'] ?? 1] ?? ''
            : self::PROTOCOL_PREFIXES[$type];
        return $prefix . ($server['name'] ?? '');
    }
}
