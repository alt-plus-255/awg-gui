<?php

namespace App\Services\Telegram;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\ResolverConnection;
use App\Models\Setting;
use App\Models\VpnClient;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\PeerStatsSyncService;
use App\Services\AmneziaWg\Versions\AwgVersionRegistry;
use App\Services\AmneziaWg\VpnUriService;
use App\Services\Resolver\ResolverService;
use App\Services\Resolver\SingBoxOutboundParser;
use App\Services\System\HostMetricsService;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

class TelegramUpdateRouter
{
    private const ONLINE_LIST_LIMIT = 8;

    public function __construct(
        private TelegramSettings $settings,
        private TelegramBotClient $bot,
        private TelegramConversationStore $conversations,
        private AmneziaWgService $awg,
        private AwgVersionRegistry $versions,
        private VpnUriService $vpnUri,
        private ResolverService $resolver,
        private SingBoxOutboundParser $outboundParser,
        private PeerStatsSyncService $statsSync,
        private HostMetricsService $hostMetrics,
    ) {}

    public function handle(array $update): void
    {
        App::setLocale($this->settings->language());

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    private function handleMessage(array $message): void
    {
        $userId = $message['from']['id'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (! $this->settings->isAdmin($userId)) {
            if ($chatId !== null && str_starts_with($text, '/start')) {
                $this->bot->sendMessage($chatId, __('telegram.forbidden'));
            }

            return;
        }

        if ($chatId === null) {
            return;
        }

        if ($text === '') {
            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->conversations->clear($chatId);
            $this->showHome($chatId, null);

            return;
        }

        $conv = $this->conversations->get($chatId);
        if ($conv !== null) {
            $this->handleWizardText($chatId, $text, $conv);

            return;
        }

        // Any unknown message — show main menu
        $this->showHome($chatId, null);
    }

    private function handleCallback(array $callback): void
    {
        $userId = $callback['from']['id'] ?? null;
        $callbackId = (string) ($callback['id'] ?? '');

        if (! $this->settings->isAdmin($userId)) {
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, __('telegram.forbidden'), true);
            }

            return;
        }

        if ($callbackId !== '') {
            $this->bot->answerCallbackQuery($callbackId);
        }

        $message = $callback['message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $messageId = isset($message['message_id']) ? (int) $message['message_id'] : null;
        if ($chatId === null || $messageId === null) {
            return;
        }

        $data = (string) ($callback['data'] ?? '');
        if ($data === '') {
            return;
        }

        try {
            $this->routeCallback($chatId, $messageId, $data);
        } catch (ValidationException $e) {
            $this->showError($chatId, $messageId, $this->firstValidationMessage($e));
        } catch (\Throwable $e) {
            $this->showError($chatId, $messageId, $e->getMessage());
        }
    }

    private function routeCallback(int|string $chatId, int $messageId, string $data): void
    {
        if ($data === 'm:home') {
            $this->conversations->clear($chatId);
            $this->showHome($chatId, $messageId);

            return;
        }

        if ($data === 'm:cfg') {
            $this->showConfigsList($chatId, $messageId);

            return;
        }

        if ($data === 'm:conn') {
            $this->showConnectionsList($chatId, $messageId);

            return;
        }

        if ($data === 'm:res') {
            $this->showResolverList($chatId, $messageId);

            return;
        }

        if ($data === 'm:notif') {
            $this->showNotifications($chatId, $messageId);

            return;
        }

        if ($data === 'm:refresh') {
            $this->showHome($chatId, $messageId, __('telegram.refresh_done'), syncFromDocker: true);

            return;
        }

        if ($data === 'cfg:new') {
            $this->conversations->put($chatId, 'cfg_new.name');
            $this->show($chatId, $messageId, __('telegram.config_wizard_name'), $this->backHomeKeyboard());

            return;
        }

        if (preg_match('#^cfg:(\d+)$#', $data, $m)) {
            $this->showConfigDetail($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^cfg:en:(\d+)$#', $data, $m)) {
            $this->showConfigEnableConfirm($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^cfg:enok:(\d+)$#', $data, $m)) {
            $this->toggleConfigEnabled($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^cfg:del:(\d+)$#', $data, $m)) {
            $this->showConfigDeleteConfirm($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^cfg:delok:(\d+)$#', $data, $m)) {
            $this->deleteConfig($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^cfg:peers:(\d+)$#', $data, $m)) {
            $this->showPeersList($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^cfg:edit:(\d+)$#', $data, $m)) {
            $this->showConfigEditMenu($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^cfg:edn:(\d+)$#', $data, $m)) {
            $id = (int) $m[1];
            $this->conversations->put($chatId, 'cfg_edit.name', ['config_id' => $id]);
            $this->show($chatId, $messageId, __('telegram.config_edit_name_prompt'), $this->backConfigKeyboard($id));

            return;
        }

        if (preg_match('#^cfg:edp:(\d+)$#', $data, $m)) {
            $id = (int) $m[1];
            $this->conversations->put($chatId, 'cfg_edit.port', ['config_id' => $id]);
            $this->show($chatId, $messageId, __('telegram.config_edit_port_prompt'), $this->backConfigKeyboard($id));

            return;
        }

        if (preg_match('#^cfg:edd:(\d+)$#', $data, $m)) {
            $id = (int) $m[1];
            $this->conversations->put($chatId, 'cfg_edit.dns', ['config_id' => $id]);
            $this->show($chatId, $messageId, __('telegram.config_edit_dns_prompt'), $this->backConfigKeyboard($id));

            return;
        }

        if (preg_match('#^cfg:type:(server|virtual_network)$#', $data, $m)) {
            $this->finishConfigCreate($chatId, $messageId, $m[1]);

            return;
        }

        if (preg_match('#^peer:(\d+):(\d+)$#', $data, $m)) {
            $this->showPeerDetail($chatId, $messageId, (int) $m[1], (int) $m[2]);

            return;
        }

        if (preg_match('#^peer:en:(\d+):(\d+)$#', $data, $m)) {
            $this->showPeerEnableConfirm($chatId, $messageId, (int) $m[1], (int) $m[2]);

            return;
        }

        if (preg_match('#^peer:enok:(\d+):(\d+)$#', $data, $m)) {
            $this->togglePeerEnabled($chatId, $messageId, (int) $m[1], (int) $m[2]);

            return;
        }

        if (preg_match('#^peer:del:(\d+):(\d+)$#', $data, $m)) {
            $this->showPeerDeleteConfirm($chatId, $messageId, (int) $m[1], (int) $m[2]);

            return;
        }

        if (preg_match('#^peer:delok:(\d+):(\d+)$#', $data, $m)) {
            $this->deletePeer($chatId, $messageId, (int) $m[1], (int) $m[2]);

            return;
        }

        if (preg_match('#^peer:new:(\d+)$#', $data, $m)) {
            $configId = (int) $m[1];
            $this->conversations->put($chatId, 'peer_new.name', ['config_id' => $configId]);
            $this->show($chatId, $messageId, __('telegram.peer_wizard_name'), $this->backPeersKeyboard($configId));

            return;
        }

        if (preg_match('#^peer:uri:(\d+):(\d+)$#', $data, $m)) {
            $this->sendPeerVpnUri($chatId, (int) $m[1], (int) $m[2]);

            return;
        }

        if (preg_match('#^res:(\d+)$#', $data, $m)) {
            $this->showResolverDetail($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^res:en:(\d+)$#', $data, $m)) {
            $this->showResolverEnableConfirm($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^res:enok:(\d+)$#', $data, $m)) {
            $this->toggleResolver($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^res:list:(\d+):(.+)$#', $data, $m)) {
            $this->toggleResolverList($chatId, $messageId, (int) $m[1], $m[2]);

            return;
        }

        if (preg_match('#^res:conn:(\d+):(\d+)$#', $data, $m)) {
            $this->setResolverConnection($chatId, $messageId, (int) $m[1], (int) $m[2]);

            return;
        }

        if (preg_match('#^conn:(\d+)$#', $data, $m)) {
            $this->showConnectionDetail($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^conn:en:(\d+)$#', $data, $m)) {
            $this->toggleConnectionEnabled($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^conn:del:(\d+)$#', $data, $m)) {
            $this->showConnectionDeleteConfirm($chatId, $messageId, (int) $m[1]);

            return;
        }

        if (preg_match('#^conn:delok:(\d+)$#', $data, $m)) {
            $this->deleteConnection($chatId, $messageId, (int) $m[1]);

            return;
        }

        if ($data === 'conn:new') {
            $this->conversations->put($chatId, 'conn_new.name');
            $this->show($chatId, $messageId, __('telegram.connection_wizard_name'), $this->backConnectionsKeyboard());

            return;
        }

        if ($data === 'notif:en') {
            $this->showNotificationsEnableConfirm($chatId, $messageId);

            return;
        }

        if ($data === 'notif:enok') {
            $this->toggleNotifications($chatId, $messageId);

            return;
        }

        if ($data === 'notif:daily:en') {
            $this->showDailyReportEnableConfirm($chatId, $messageId);

            return;
        }

        if ($data === 'notif:daily:enok') {
            $this->toggleDailyReport($chatId, $messageId);
        }
    }

    /**
     * @param  array{step: string, data: array<string, mixed>}  $conv
     */
    private function handleWizardText(int|string $chatId, string $text, array $conv): void
    {
        try {
            match ($conv['step']) {
                'cfg_new.name' => $this->wizardConfigName($chatId, $text),
                'cfg_edit.name' => $this->wizardConfigEditName($chatId, $text, $conv['data']),
                'cfg_edit.port' => $this->wizardConfigEditPort($chatId, $text, $conv['data']),
                'cfg_edit.dns' => $this->wizardConfigEditDns($chatId, $text, $conv['data']),
                'peer_new.name' => $this->wizardPeerName($chatId, $text, $conv['data']),
                'conn_new.name' => $this->wizardConnectionName($chatId, $text),
                'conn_new.url' => $this->wizardConnectionUrl($chatId, $text, $conv['data']),
                default => $this->conversations->clear($chatId),
            };
        } catch (ValidationException $e) {
            $this->bot->sendMessage($chatId, __('telegram.error_generic', [
                'message' => $this->esc($this->firstValidationMessage($e)),
            ]));
        } catch (\Throwable $e) {
            $this->bot->sendMessage($chatId, __('telegram.error_generic', [
                'message' => $this->esc($e->getMessage()),
            ]));
        }
    }

    private function wizardConfigName(int|string $chatId, string $text): void
    {
        $name = $this->validateName($text);
        $this->conversations->put($chatId, 'cfg_new.type', ['name' => $name]);

        $rows = TelegramKeyboard::chunk([
            TelegramKeyboard::btn(__('telegram.config_type_server'), 'cfg:type:server'),
            TelegramKeyboard::btn(__('telegram.config_type_vn'), 'cfg:type:virtual_network'),
        ], 1);

        $this->bot->sendMessage($chatId, __('telegram.config_wizard_type'), [
            'reply_markup' => TelegramKeyboard::inline($rows),
        ]);
    }

    private function finishConfigCreate(int|string $chatId, int $messageId, string $type): void
    {
        $conv = $this->conversations->get($chatId);
        if ($conv === null || $conv['step'] !== 'cfg_new.type') {
            return;
        }

        $name = (string) ($conv['data']['name'] ?? '');
        if ($name === '') {
            $this->conversations->clear($chatId);

            return;
        }

        $config = $this->createConfig($name, $type);
        $this->conversations->clear($chatId);
        $this->showConfigDetail(
            $chatId,
            $messageId,
            $config->id,
            __('telegram.config_created', ['name' => $this->esc($config->name)])."\n\n"
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wizardConfigEditName(int|string $chatId, string $text, array $data): void
    {
        $config = $this->findConfig((int) ($data['config_id'] ?? 0));
        if (! $config) {
            $this->conversations->clear($chatId);

            return;
        }

        $config->name = $this->validateName($text);
        $config->save();
        $this->awg->applyConfig();
        $this->conversations->clear($chatId);
        $this->bot->sendMessage($chatId, __('telegram.config_updated'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wizardConfigEditPort(int|string $chatId, string $text, array $data): void
    {
        $config = $this->findConfig((int) ($data['config_id'] ?? 0));
        if (! $config) {
            $this->conversations->clear($chatId);

            return;
        }

        $port = filter_var(trim($text), FILTER_VALIDATE_INT);
        if ($port === false || $port < AmneziaWgService::PORT_MIN || $port > AmneziaWgService::PORT_MAX) {
            throw ValidationException::withMessages(['port' => [__('telegram.error_invalid_port')]]);
        }

        if (AwgConfig::query()->where('listen_port', $port)->where('id', '!=', $config->id)->exists()) {
            throw ValidationException::withMessages(['port' => [__('telegram.error_port_taken')]]);
        }

        $config->listen_port = $port;
        $config->save();
        $this->awg->applyConfig();
        $this->conversations->clear($chatId);
        $this->bot->sendMessage($chatId, __('telegram.config_updated'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wizardConfigEditDns(int|string $chatId, string $text, array $data): void
    {
        $config = $this->findConfig((int) ($data['config_id'] ?? 0));
        if (! $config) {
            $this->conversations->clear($chatId);

            return;
        }

        $dns = trim($text);
        if ($dns === '' || strlen($dns) > 255) {
            throw ValidationException::withMessages(['dns' => [__('telegram.error_invalid_name')]]);
        }

        $config->peer_dns = $dns;
        $config->save();
        $this->awg->applyConfig();
        $this->conversations->clear($chatId);
        $this->bot->sendMessage($chatId, __('telegram.config_updated'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wizardPeerName(int|string $chatId, string $text, array $data): void
    {
        $config = $this->findConfig((int) ($data['config_id'] ?? 0));
        if (! $config) {
            $this->conversations->clear($chatId);

            return;
        }

        $name = $this->validateName($text);
        $client = VpnClient::query()->create(['name' => $name]);
        $this->attachPeer($config, $client);
        $this->conversations->clear($chatId);
        $this->bot->sendMessage($chatId, __('telegram.peer_created', ['name' => $this->esc($name)]));
    }

    private function wizardConnectionName(int|string $chatId, string $text): void
    {
        $name = $this->validateName($text, 128);
        $this->conversations->put($chatId, 'conn_new.url', ['name' => $name]);
        $this->bot->sendMessage($chatId, __('telegram.connection_wizard_url'), [
            'reply_markup' => $this->backConnectionsKeyboard(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wizardConnectionUrl(int|string $chatId, string $text, array $data): void
    {
        $name = (string) ($data['name'] ?? '');
        if ($name === '') {
            $this->conversations->clear($chatId);

            return;
        }

        $shareUrl = trim($text);
        if ($shareUrl === '') {
            throw ValidationException::withMessages(['share_url' => [__('telegram.error_invalid_url')]]);
        }

        try {
            $outbound = $this->outboundParser->fromRequest('url', $shareUrl, null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'share_url' => [$this->firstValidationMessage($e)],
            ]);
        }

        ResolverConnection::query()->create([
            'name' => $name,
            'kind' => ResolverConnection::KIND_PROXY,
            'config_type' => 'url',
            'share_url' => $shareUrl,
            'outbound' => $outbound,
            'enabled' => true,
            'ping_check_interval_min' => 5,
        ]);

        try {
            $this->resolver->apply(refreshSubscriptions: false);
        } catch (\Throwable) {
            // status will show error
        }
        $this->conversations->clear($chatId);
        $this->bot->sendMessage($chatId, __('telegram.connection_created', ['name' => $this->esc($name)]));
    }

    private function showHome(int|string $chatId, ?int $messageId, ?string $prefix = null, bool $syncFromDocker = false): void
    {
        $parts = [];
        if ($prefix) {
            $parts[] = $prefix;
        }
        $parts[] = __('telegram.home_title');
        $parts[] = $this->formatDashboardSummary($syncFromDocker);
        $parts[] = __('telegram.welcome');

        $text = implode("\n\n", array_filter($parts, fn ($p) => $p !== ''));
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.menu_configs'), 'm:cfg'),
                TelegramKeyboard::btn(__('telegram.menu_connections'), 'm:conn'),
            ],
            [
                TelegramKeyboard::btn(__('telegram.menu_resolver'), 'm:res'),
                TelegramKeyboard::btn(__('telegram.menu_notifications'), 'm:notif'),
            ],
            [TelegramKeyboard::btn(__('telegram.menu_refresh'), 'm:refresh')],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function formatDashboardSummary(bool $syncFromDocker = false): string
    {
        $na = __('telegram.dashboard_na');

        if ($syncFromDocker) {
            try {
                $this->statsSync->refreshFromDocker();
            } catch (\Throwable) {
                // keep DB snapshot
            }
        }

        $peers = [];
        try {
            $peers = $this->statsSync->peersFromDb()['peers'] ?? [];
        } catch (\Throwable) {
            $peers = [];
        }
        if (! is_array($peers)) {
            $peers = [];
        }

        $onlinePeers = collect($peers)->where('online', true)->values();
        $clientsTotal = VpnClient::query()->count();
        $enabled = AwgConfigPeer::query()->where('enabled', true)->count();
        $online = $onlinePeers->count();

        $awgStatus = $na;
        $endpoint = $na;
        try {
            $awgStatus = $this->awg->isContainerRunning()
                ? __('telegram.dashboard_awg_up')
                : __('telegram.dashboard_awg_down');
            $endpoint = (string) ($this->awg->endpointStatus()['endpoint'] ?? $na);
            if ($endpoint === '') {
                $endpoint = $na;
            }
        } catch (\Throwable) {
            // leave n/a
        }

        $cpu = $na;
        $ram = $na;
        $disk = $na;
        try {
            $host = $this->hostMetrics->collect();
            $cpu = $this->formatPercent($host['cpu']['percent'] ?? null);
            $ram = $this->formatPercent($host['memory']['percent'] ?? null);
            $disk = $this->formatPercent($host['disk']['percent'] ?? null);
        } catch (\Throwable) {
            // leave n/a
        }

        $lines = [
            __('telegram.dashboard_awg', [
                'status' => $awgStatus,
                'endpoint' => $this->esc($endpoint),
            ]),
            __('telegram.dashboard_summary', [
                'peers' => $clientsTotal,
                'enabled' => $enabled,
                'online' => $online,
            ]),
            __('telegram.dashboard_host', [
                'cpu' => $cpu,
                'ram' => $ram,
                'disk' => $disk,
            ]),
        ];

        if ($onlinePeers->isNotEmpty()) {
            $lines[] = __('telegram.dashboard_online_title');
            foreach ($onlinePeers->take(self::ONLINE_LIST_LIMIT) as $peer) {
                $name = trim((string) ($peer['name'] ?? ''));
                if ($name === '') {
                    $name = $na;
                }
                $config = trim((string) ($peer['config_name'] ?? ''));
                if ($config === '') {
                    $config = $na;
                }
                $lines[] = __('telegram.dashboard_online_line', [
                    'name' => $this->esc($name),
                    'config' => $this->esc($config),
                ]);
            }
            $more = $onlinePeers->count() - self::ONLINE_LIST_LIMIT;
            if ($more > 0) {
                $lines[] = __('telegram.dashboard_online_more', ['count' => $more]);
            }
        }

        return implode("\n", $lines);
    }

    private function formatPercent(mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('telegram.dashboard_na');
        }

        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.').'%';
    }

    private function showConfigsList(int|string $chatId, int $messageId): void
    {
        $configs = AwgConfig::query()->orderBy('id')->get();
        $text = __('telegram.configs_title');

        if ($configs->isEmpty()) {
            $text .= "\n\n".__('telegram.configs_empty');
        }

        $buttons = [TelegramKeyboard::btn(__('telegram.create'), 'cfg:new')];
        foreach ($configs as $config) {
            $status = $config->enabled ? '✅' : '⏸';
            $buttons[] = TelegramKeyboard::btn($status.' '.$config->name, 'cfg:'.$config->id);
        }
        $buttons[] = TelegramKeyboard::btn(__('telegram.menu_home'), 'm:home');

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline(TelegramKeyboard::chunk($buttons, 1)));
    }

    private function showConfigDetail(int|string $chatId, int $messageId, int $configId, string $prefix = ''): void
    {
        $config = $this->findConfig($configId);
        if (! $config) {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        $text = $prefix.$this->formatConfigDetail($config);
        $toggleLabel = $config->enabled
            ? __('telegram.config_disable')
            : __('telegram.config_enable');
        $rows = [
            [
                TelegramKeyboard::btn($toggleLabel, 'cfg:en:'.$configId),
                TelegramKeyboard::btn(__('telegram.peers'), 'cfg:peers:'.$configId),
            ],
            [
                TelegramKeyboard::btn(__('telegram.edit'), 'cfg:edit:'.$configId),
                TelegramKeyboard::btn(__('telegram.delete'), 'cfg:del:'.$configId),
            ],
            [TelegramKeyboard::btn(__('telegram.menu_back'), 'm:cfg')],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function showConfigEditMenu(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config) {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        $text = __('telegram.config_edit_title', ['name' => $this->esc($config->name)]);
        $rows = TelegramKeyboard::chunk([
            TelegramKeyboard::btn(__('telegram.config_edit_name'), 'cfg:edn:'.$configId),
            TelegramKeyboard::btn(__('telegram.config_edit_port'), 'cfg:edp:'.$configId),
            TelegramKeyboard::btn(__('telegram.config_edit_dns'), 'cfg:edd:'.$configId),
            TelegramKeyboard::btn(__('telegram.menu_back'), 'cfg:'.$configId),
        ], 1);

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function showConfigDeleteConfirm(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config) {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        $text = __('telegram.config_delete_confirm', ['name' => $this->esc($config->name)]);
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.yes'), 'cfg:delok:'.$configId),
                TelegramKeyboard::btn(__('telegram.no'), 'cfg:'.$configId),
            ],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function showConfigEnableConfirm(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config) {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        $key = $config->enabled ? 'telegram.config_disable_confirm' : 'telegram.config_enable_confirm';
        $text = __($key, ['name' => $this->esc($config->name)]);
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.yes'), 'cfg:enok:'.$configId),
                TelegramKeyboard::btn(__('telegram.no'), 'cfg:'.$configId),
            ],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function toggleConfigEnabled(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config) {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        $config->enabled = ! $config->enabled;
        if (($config->type === 'virtual_network') && $config->resolver_enabled) {
            $config->resolver_enabled = false;
            $config->connection_id = null;
            $config->resolver_last_error = null;
        }
        $config->save();
        $this->awg->applyConfig();
        $this->showConfigDetail($chatId, $messageId, $configId);
    }

    private function deleteConfig(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config) {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        if (AwgConfig::query()->count() <= 1) {
            $this->showError($chatId, $messageId, __('telegram.cannot_delete_last_config'));

            return;
        }

        $config->delete();
        $this->awg->applyConfig();
        $this->showConfigsList($chatId, $messageId);
        $this->show($chatId, null, __('telegram.config_deleted'));
    }

    private function showPeersList(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config) {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        $this->awg->primeConfigPeerCache($config);
        $peers = AwgConfigPeer::query()
            ->where('awg_config_id', $configId)
            ->with('client')
            ->orderBy('id')
            ->get();

        $text = __('telegram.peers_title', ['name' => $this->esc($config->name)]);
        if ($peers->isEmpty()) {
            $text .= "\n\n".__('telegram.peers_empty');
        }

        $buttons = [TelegramKeyboard::btn(__('telegram.create'), 'peer:new:'.$configId)];
        foreach ($peers as $peer) {
            $name = $peer->client?->name ?? '#'.$peer->vpn_client_id;
            $status = $peer->enabled ? '✅' : '⏸';
            $buttons[] = TelegramKeyboard::btn($status.' '.$name, 'peer:'.$configId.':'.$peer->vpn_client_id);
        }
        $buttons[] = TelegramKeyboard::btn(__('telegram.menu_back'), 'cfg:'.$configId);

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline(TelegramKeyboard::chunk($buttons, 1)));
    }

    private function showPeerDetail(int|string $chatId, int $messageId, int $configId, int $clientId): void
    {
        $membership = $this->findMembership($configId, $clientId);
        if (! $membership) {
            $this->showError($chatId, $messageId, __('telegram.peer_not_found'));

            return;
        }

        $name = $membership->client?->name ?? '#'.$clientId;
        $text = __('telegram.peer_detail', [
            'name' => $this->esc($name),
            'address' => $this->esc((string) ($membership->address ?? '—')),
            'status' => $this->statusLabel((bool) $membership->enabled),
        ]);

        $rows = [
            [
                TelegramKeyboard::btn(
                    $membership->enabled ? __('telegram.config_disable') : __('telegram.config_enable'),
                    'peer:en:'.$configId.':'.$clientId
                ),
                TelegramKeyboard::btn(__('telegram.vpn_uri'), 'peer:uri:'.$configId.':'.$clientId),
            ],
            [TelegramKeyboard::btn(__('telegram.delete'), 'peer:del:'.$configId.':'.$clientId)],
            [TelegramKeyboard::btn(__('telegram.menu_back'), 'cfg:peers:'.$configId)],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function showPeerDeleteConfirm(int|string $chatId, int $messageId, int $configId, int $clientId): void
    {
        $membership = $this->findMembership($configId, $clientId);
        if (! $membership) {
            $this->showError($chatId, $messageId, __('telegram.peer_not_found'));

            return;
        }

        $name = $membership->client?->name ?? '#'.$clientId;
        $text = __('telegram.peer_delete_confirm', ['name' => $this->esc($name)]);
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.yes'), 'peer:delok:'.$configId.':'.$clientId),
                TelegramKeyboard::btn(__('telegram.no'), 'peer:'.$configId.':'.$clientId),
            ],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function showPeerEnableConfirm(int|string $chatId, int $messageId, int $configId, int $clientId): void
    {
        $membership = $this->findMembership($configId, $clientId);
        if (! $membership) {
            $this->showError($chatId, $messageId, __('telegram.peer_not_found'));

            return;
        }

        $name = $membership->client?->name ?? '#'.$clientId;
        $key = $membership->enabled ? 'telegram.peer_disable_confirm' : 'telegram.peer_enable_confirm';
        $text = __($key, ['name' => $this->esc($name)]);
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.yes'), 'peer:enok:'.$configId.':'.$clientId),
                TelegramKeyboard::btn(__('telegram.no'), 'peer:'.$configId.':'.$clientId),
            ],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function togglePeerEnabled(int|string $chatId, int $messageId, int $configId, int $clientId): void
    {
        $config = $this->findConfig($configId);
        $membership = $this->findMembership($configId, $clientId);
        if (! $config || ! $membership) {
            $this->showError($chatId, $messageId, __('telegram.peer_not_found'));

            return;
        }

        $membership->enabled = ! $membership->enabled;
        $membership->save();
        $this->awg->applyConfig($config, withResolver: false);
        $this->showPeerDetail($chatId, $messageId, $configId, $clientId);
    }

    private function deletePeer(int|string $chatId, int $messageId, int $configId, int $clientId): void
    {
        $config = $this->findConfig($configId);
        if (! $config) {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        AwgConfigPeer::query()
            ->where('awg_config_id', $configId)
            ->where('vpn_client_id', $clientId)
            ->delete();

        $this->pruneExcludedClientId($config, $clientId);
        $this->pruneClientFromZones($config, $clientId);
        $this->awg->applyConfig($config, withResolver: false);
        $this->showPeersList($chatId, $messageId, $configId);
    }

    private function sendPeerVpnUri(int|string $chatId, int $configId, int $clientId): void
    {
        $membership = $this->findMembership($configId, $clientId);
        if (! $membership) {
            $this->bot->sendMessage($chatId, __('telegram.peer_not_found'));

            return;
        }

        $membership->loadMissing(['config', 'client']);
        $name = $membership->client?->name ?? '#'.$clientId;
        $uri = $this->vpnUri->buildFromMembership($membership);

        $this->bot->sendMessage($chatId, __('telegram.peer_uri_caption', ['name' => $this->esc($name)])."\n\n<code>".$this->esc($uri).'</code>');
    }

    private function showResolverList(int|string $chatId, int $messageId): void
    {
        $configs = AwgConfig::query()
            ->where('type', 'server')
            ->orderBy('id')
            ->get();

        $text = __('telegram.resolver_title');
        if ($configs->isEmpty()) {
            $text .= "\n\n".__('telegram.resolver_empty');
        }

        $buttons = [];
        foreach ($configs as $config) {
            $flag = $config->resolver_enabled ? '✓' : '✗';
            $buttons[] = TelegramKeyboard::btn($flag.' '.$config->name, 'res:'.$config->id);
        }
        $buttons[] = TelegramKeyboard::btn(__('telegram.menu_home'), 'm:home');

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline(TelegramKeyboard::chunk($buttons, 1)));
    }

    private function showResolverDetail(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config || $config->type !== 'server') {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        $connectionName = $config->connection_id
            ? (ResolverConnection::query()->find($config->connection_id)?->name ?? '#'.$config->connection_id)
            : __('telegram.resolver_no_connection');

        $lists = array_values($config->community_lists ?? []);
        $listLabels = array_map(fn (string $tag) => $this->listLabel($tag), $lists);

        $text = __('telegram.resolver_detail', [
            'name' => $this->esc($config->name),
            'status' => $this->statusLabel((bool) $config->resolver_enabled),
            'connection' => $this->esc((string) $connectionName),
            'lists' => $this->esc($listLabels !== [] ? implode(', ', $listLabels) : '—'),
        ]);

        $buttons = [
            TelegramKeyboard::btn(
                $config->resolver_enabled ? __('telegram.config_disable') : __('telegram.config_enable'),
                'res:en:'.$configId
            ),
            TelegramKeyboard::btn(__('telegram.resolver_pick_connection'), 'res:conn:'.$configId.':0'),
        ];

        foreach (ResolverService::COMMUNITY_LISTS as $tag) {
            $mark = in_array($tag, $lists, true) ? '✓ ' : '';
            $buttons[] = TelegramKeyboard::btn($mark.$this->listLabel($tag), 'res:list:'.$configId.':'.$tag);
        }

        $buttons[] = TelegramKeyboard::btn(__('telegram.menu_back'), 'm:res');

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline(TelegramKeyboard::chunk($buttons, 1)));
    }

    private function showResolverEnableConfirm(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config || $config->type !== 'server') {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        $key = $config->resolver_enabled
            ? 'telegram.resolver_disable_confirm'
            : 'telegram.resolver_enable_confirm';
        $text = __($key, ['name' => $this->esc($config->name)]);
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.yes'), 'res:enok:'.$configId),
                TelegramKeyboard::btn(__('telegram.no'), 'res:'.$configId),
            ],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function toggleResolver(int|string $chatId, int $messageId, int $configId): void
    {
        $config = $this->findConfig($configId);
        if (! $config || $config->type !== 'server') {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        if ($config->resolver_enabled) {
            $config->resolver_enabled = false;
            $config->resolver_last_error = null;
            $config->save();
            $this->awg->applyConfig($config, refreshSubscriptions: false);
            $this->showResolverDetail($chatId, $messageId, $configId);

            return;
        }

        $this->resolver->assertCanEnable($config);

        $normalized = $this->resolver->normalizeLists(
            $config->community_lists ?? [],
            $config->user_domains ?? [],
            $config->user_subnets ?? [],
        );

        if ($normalized['community_lists'] === [] && $normalized['user_domains'] === [] && $normalized['user_subnets'] === []) {
            throw ValidationException::withMessages([
                'community_lists' => [__('telegram.resolver_no_lists')],
            ]);
        }

        $conn = $this->resolver->assertConnectionSelected($config, $config->connection_id);

        $config->resolver_enabled = true;
        $config->connection_id = $conn->id;
        $config->community_lists = $normalized['community_lists'];
        $config->user_domains = $normalized['user_domains'];
        $config->user_subnets = $normalized['user_subnets'];
        $config->save();
        $this->awg->applyConfig($config, refreshSubscriptions: false);

        $this->showResolverDetail($chatId, $messageId, $configId);
    }

    private function toggleResolverList(int|string $chatId, int $messageId, int $configId, string $tag): void
    {
        $config = $this->findConfig($configId);
        if (! $config || $config->type !== 'server') {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        if (! in_array($tag, ResolverService::COMMUNITY_LISTS, true)) {
            return;
        }

        $lists = array_values($config->community_lists ?? []);
        if (in_array($tag, $lists, true)) {
            $lists = array_values(array_diff($lists, [$tag]));
        } else {
            if (in_array($tag, ResolverService::MUTUALLY_EXCLUSIVE, true)) {
                $lists = array_values(array_diff($lists, ResolverService::MUTUALLY_EXCLUSIVE));
            }
            $lists[] = $tag;
        }

        $normalized = $this->resolver->normalizeLists($lists, $config->user_domains ?? [], $config->user_subnets ?? []);
        $config->community_lists = $normalized['community_lists'];
        $config->user_domains = $normalized['user_domains'];
        $config->user_subnets = $normalized['user_subnets'];
        $config->save();
        $this->awg->applyConfig($config, refreshSubscriptions: false);

        $this->showResolverDetail($chatId, $messageId, $configId);
    }

    private function setResolverConnection(int|string $chatId, int $messageId, int $configId, int $connId): void
    {
        $config = $this->findConfig($configId);
        if (! $config || $config->type !== 'server') {
            $this->showError($chatId, $messageId, __('telegram.config_not_found'));

            return;
        }

        if ($connId === 0) {
            $connections = ResolverConnection::query()->where('enabled', true)->orderBy('id')->get();
            $text = __('telegram.resolver_pick_connection');
            $buttons = [];
            foreach ($connections as $conn) {
                $mark = (int) $config->connection_id === (int) $conn->id ? '✓ ' : '';
                $buttons[] = TelegramKeyboard::btn($mark.$conn->name, 'res:conn:'.$configId.':'.$conn->id);
            }
            $buttons[] = TelegramKeyboard::btn(__('telegram.menu_back'), 'res:'.$configId);

            $this->show($chatId, $messageId, $text, TelegramKeyboard::inline(TelegramKeyboard::chunk($buttons, 1)));

            return;
        }

        $conn = $this->resolver->assertConnectionSelected($config, $connId);
        $config->connection_id = $conn->id;

        if ($config->resolver_enabled) {
            $normalized = $this->resolver->normalizeLists(
                $config->community_lists ?? [],
                $config->user_domains ?? [],
                $config->user_subnets ?? [],
            );
            $config->community_lists = $normalized['community_lists'];
            $config->user_domains = $normalized['user_domains'];
            $config->user_subnets = $normalized['user_subnets'];
        }

        $config->save();
        $this->awg->applyConfig($config, refreshSubscriptions: false);
        $this->showResolverDetail($chatId, $messageId, $configId);
    }

    private function showConnectionsList(int|string $chatId, int $messageId): void
    {
        $connections = ResolverConnection::query()->orderBy('id')->get();
        $text = __('telegram.connections_title');
        if ($connections->isEmpty()) {
            $text .= "\n\n".__('telegram.connections_empty');
        }

        $buttons = [TelegramKeyboard::btn(__('telegram.create'), 'conn:new')];
        foreach ($connections as $conn) {
            $status = $conn->enabled ? '✅' : '⏸';
            $buttons[] = TelegramKeyboard::btn($status.' '.$conn->name, 'conn:'.$conn->id);
        }
        $buttons[] = TelegramKeyboard::btn(__('telegram.menu_home'), 'm:home');

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline(TelegramKeyboard::chunk($buttons, 1)));
    }

    private function showConnectionDetail(int|string $chatId, int $messageId, int $connectionId): void
    {
        $conn = ResolverConnection::query()->find($connectionId);
        if (! $conn) {
            $this->showError($chatId, $messageId, __('telegram.connection_not_found'));

            return;
        }

        $text = __('telegram.connection_detail', [
            'name' => $this->esc($conn->name),
            'type' => $this->esc(__('telegram.connection_type_proxy')),
            'status' => $this->statusLabel((bool) $conn->enabled),
        ]);

        $rows = [
            [
                TelegramKeyboard::btn($conn->enabled ? __('telegram.config_disable') : __('telegram.config_enable'), 'conn:en:'.$connectionId),
                TelegramKeyboard::btn(__('telegram.delete'), 'conn:del:'.$connectionId),
            ],
            [TelegramKeyboard::btn(__('telegram.menu_back'), 'm:conn')],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function showConnectionDeleteConfirm(int|string $chatId, int $messageId, int $connectionId): void
    {
        $conn = ResolverConnection::query()->find($connectionId);
        if (! $conn) {
            $this->showError($chatId, $messageId, __('telegram.connection_not_found'));

            return;
        }

        $text = __('telegram.connection_delete_confirm', ['name' => $this->esc($conn->name)]);
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.yes'), 'conn:delok:'.$connectionId),
                TelegramKeyboard::btn(__('telegram.no'), 'conn:'.$connectionId),
            ],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function toggleConnectionEnabled(int|string $chatId, int $messageId, int $connectionId): void
    {
        $conn = ResolverConnection::query()->find($connectionId);
        if (! $conn) {
            $this->showError($chatId, $messageId, __('telegram.connection_not_found'));

            return;
        }

        $conn->enabled = ! $conn->enabled;
        $conn->save();
        try {
            $this->resolver->apply(refreshSubscriptions: false);
        } catch (\Throwable) {
            // status will show error
        }
        $this->showConnectionDetail($chatId, $messageId, $connectionId);
    }

    private function deleteConnection(int|string $chatId, int $messageId, int $connectionId): void
    {
        $conn = ResolverConnection::query()->find($connectionId);
        if (! $conn) {
            $this->showError($chatId, $messageId, __('telegram.connection_not_found'));

            return;
        }

        $refs = $conn->configs()->count();
        if ($refs > 0) {
            $this->showError($chatId, $messageId, __('telegram.connection_in_use', ['refs' => $refs]));

            return;
        }

        $conn->delete();
        try {
            $this->resolver->apply(refreshSubscriptions: false);
        } catch (\Throwable) {
            // status will show error
        }
        $this->showConnectionsList($chatId, $messageId);
    }

    private function showNotifications(int|string $chatId, int $messageId): void
    {
        $peerEnabled = $this->settings->notificationsEnabled();
        $dailyEnabled = $this->settings->dailyReportEnabled();
        $text = __('telegram.notifications_title')."\n\n"
            .__('telegram.notifications_status', [
                'status' => $this->statusLabel($peerEnabled),
            ])."\n"
            .__('telegram.daily_report_status', [
                'status' => $this->statusLabel($dailyEnabled),
            ]);

        $rows = [
            [TelegramKeyboard::btn(
                $peerEnabled
                    ? __('telegram.notifications_peer_disable')
                    : __('telegram.notifications_peer_enable'),
                'notif:en'
            )],
            [TelegramKeyboard::btn(
                $dailyEnabled
                    ? __('telegram.daily_report_disable')
                    : __('telegram.daily_report_enable'),
                'notif:daily:en'
            )],
            [TelegramKeyboard::btn(__('telegram.menu_home'), 'm:home')],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function showNotificationsEnableConfirm(int|string $chatId, int $messageId): void
    {
        $key = $this->settings->notificationsEnabled()
            ? 'telegram.notifications_disable_confirm'
            : 'telegram.notifications_enable_confirm';
        $text = __($key);
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.yes'), 'notif:enok'),
                TelegramKeyboard::btn(__('telegram.no'), 'm:notif'),
            ],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function toggleNotifications(int|string $chatId, int $messageId): void
    {
        $next = ! $this->settings->notificationsEnabled();
        Setting::setValue('telegram_notifications_enabled', $next ? '1' : '0');
        $this->showNotifications($chatId, $messageId);
    }

    private function showDailyReportEnableConfirm(int|string $chatId, int $messageId): void
    {
        $key = $this->settings->dailyReportEnabled()
            ? 'telegram.daily_report_disable_confirm'
            : 'telegram.daily_report_enable_confirm';
        $text = __($key);
        $rows = [
            [
                TelegramKeyboard::btn(__('telegram.yes'), 'notif:daily:enok'),
                TelegramKeyboard::btn(__('telegram.no'), 'm:notif'),
            ],
        ];

        $this->show($chatId, $messageId, $text, TelegramKeyboard::inline($rows));
    }

    private function toggleDailyReport(int|string $chatId, int $messageId): void
    {
        $next = ! $this->settings->dailyReportEnabled();
        Setting::setValue('telegram_daily_report_enabled', $next ? '1' : '0');
        $this->showNotifications($chatId, $messageId);
    }

    private function createConfig(string $name, string $type): AwgConfig
    {
        $protocolVersion = $this->versions->latest();
        $keys = $this->awg->generateKeyPair();
        $junk = $this->awg->generateJunkParams($protocolVersion);
        $defaults = $this->awg->defaultConfigAttributes();

        $subnet = $defaults['internal_subnet'];
        $this->assertSubnetAvailable($subnet);
        $serverAddress = $defaults['server_address'];
        if (preg_match('#^(\d+\.\d+\.\d+)\.(\d+)/(\d+)$#', $subnet, $m)) {
            $serverAddress = $m[1].'.1/'.$m[3];
        }

        $iface = $this->awg->allocateIface();
        $port = $this->awg->nextFreeListenPort();
        if (AwgConfig::query()->where('listen_port', $port)->exists()) {
            throw ValidationException::withMessages([
                'listen_port' => [__('telegram.error_port_taken')],
            ]);
        }

        $config = AwgConfig::query()->create(array_merge($junk, [
            'name' => $name,
            'type' => $type,
            'protocol_version' => $protocolVersion,
            'vn_policy' => 'allow_all',
            'iface' => $iface,
            'listen_port' => $port,
            'internal_subnet' => $subnet,
            'server_address' => $serverAddress,
            'server_private_key' => $keys['private'],
            'server_public_key' => $keys['public'],
            'peer_dns' => $defaults['peer_dns'],
            'client_allowed_ips' => $defaults['client_allowed_ips'],
            'persistent_keepalive' => $defaults['persistent_keepalive'],
            'enabled' => true,
        ]));

        $this->awg->applyConfig();

        return $config;
    }

    private function attachPeer(AwgConfig $config, VpnClient $client): AwgConfigPeer
    {
        if (AwgConfigPeer::query()->where('awg_config_id', $config->id)->where('vpn_client_id', $client->id)->exists()) {
            throw ValidationException::withMessages([
                'vpn_client_id' => [__('telegram.peer_already_bound')],
            ]);
        }

        $keys = $this->awg->generateKeyPair();

        $membership = AwgConfigPeer::query()->create([
            'awg_config_id' => $config->id,
            'vpn_client_id' => $client->id,
            'enabled' => true,
            'private_key' => $keys['private'],
            'public_key' => $keys['public'],
            'preshared_key' => $this->awg->generatePresharedKey(),
            'address' => $this->awg->nextClientAddress($config),
            'extra_allowed_ips' => [],
            'excluded_client_ids' => [],
            'exclusions_mutual' => false,
            'keepalive' => null,
        ]);

        $this->awg->ensurePeerKeys($membership);
        $membership->refresh();
        $this->awg->applyConfig($config, withResolver: false);

        return $membership;
    }

    private function formatConfigDetail(AwgConfig $config): string
    {
        $type = $config->type === 'virtual_network'
            ? __('telegram.config_type_vn')
            : __('telegram.config_type_server');

        return __('telegram.config_detail', [
            'name' => $this->esc($config->name),
            'type' => $this->esc($type),
            'port' => (string) $config->listen_port,
            'dns' => $this->esc((string) ($config->peer_dns ?? '—')),
            'status' => $this->statusLabel((bool) $config->enabled),
        ]);
    }

    private function listLabel(string $tag): string
    {
        $key = 'telegram.list_'.$tag;
        $label = __($key);

        return $label !== $key ? $label : $tag;
    }

    private function statusLabel(bool $enabled): string
    {
        return $enabled ? __('telegram.on') : __('telegram.off');
    }

    private function validateName(string $text, int $max = 64): string
    {
        $name = trim($text);
        if ($name === '' || mb_strlen($name) > $max) {
            throw ValidationException::withMessages(['name' => [__('telegram.error_invalid_name')]]);
        }

        return $name;
    }

    private function findConfig(int $id): ?AwgConfig
    {
        return $id > 0 ? AwgConfig::query()->find($id) : null;
    }

    private function findMembership(int $configId, int $clientId): ?AwgConfigPeer
    {
        return AwgConfigPeer::query()
            ->where('awg_config_id', $configId)
            ->where('vpn_client_id', $clientId)
            ->with('client')
            ->first();
    }

    private function assertSubnetAvailable(string $subnet, ?int $ignoreId = null): void
    {
        $key = $this->normalizeSubnetKey($subnet);
        if ($key === null) {
            throw ValidationException::withMessages([
                'internal_subnet' => [__('configs.invalid_internal_subnet')],
            ]);
        }

        $query = AwgConfig::query()->orderBy('id');
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        foreach ($query->pluck('internal_subnet') as $existing) {
            if ($this->normalizeSubnetKey((string) $existing) === $key) {
                throw ValidationException::withMessages([
                    'internal_subnet' => [__('configs.subnet_taken')],
                ]);
            }
        }
    }

    private function normalizeSubnetKey(string $subnet): ?string
    {
        if (! preg_match('#^([^/\s]+)/(\d{1,2})$#', trim($subnet), $m)) {
            return null;
        }

        $mask = (int) $m[2];
        if ($mask < 0 || $mask > 32) {
            return null;
        }

        $long = ip2long($m[1]);
        if ($long === false) {
            return null;
        }

        $maskBits = $mask === 0 ? 0 : (-1 << (32 - $mask));
        $network = $long & $maskBits;

        return long2ip($network).'/'.$mask;
    }

    private function pruneExcludedClientId(AwgConfig $config, int $clientId): void
    {
        $memberships = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->whereNotNull('excluded_client_ids')
            ->get();

        foreach ($memberships as $membership) {
            $excluded = array_map('intval', $membership->excluded_client_ids ?? []);
            if (in_array($clientId, $excluded, true)) {
                $membership->excluded_client_ids = array_values(array_diff($excluded, [$clientId]));
                $membership->save();
            }
        }
    }

    private function pruneClientFromZones(AwgConfig $config, int $clientId): void
    {
        $vnZones = $config->vn_zones;
        if (! is_array($vnZones) || empty($vnZones['rules'])) {
            return;
        }

        $changed = false;
        $rules = [];
        foreach ($vnZones['rules'] as $rule) {
            $src = array_map('intval', $rule['src_client_ids'] ?? []);
            $dest = array_map('intval', $rule['dest_client_ids'] ?? []);
            if (in_array($clientId, $src, true) || in_array($clientId, $dest, true)) {
                $changed = true;
                $src = array_values(array_diff($src, [$clientId]));
                $dest = array_values(array_diff($dest, [$clientId]));
            }
            if ($src && $dest) {
                $rules[] = ['src_client_ids' => $src, 'dest_client_ids' => $dest];
            }
        }

        if ($changed) {
            $vnZones['rules'] = $rules;
            $config->vn_zones = $vnZones;
            $config->save();
        }
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function backHomeKeyboard(): array
    {
        return TelegramKeyboard::inline([[TelegramKeyboard::btn(__('telegram.menu_home'), 'm:home')]]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function backConfigKeyboard(int $configId): array
    {
        return TelegramKeyboard::inline([[TelegramKeyboard::btn(__('telegram.menu_back'), 'cfg:'.$configId)]]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function backPeersKeyboard(int $configId): array
    {
        return TelegramKeyboard::inline([[TelegramKeyboard::btn(__('telegram.menu_back'), 'cfg:peers:'.$configId)]]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function backConnectionsKeyboard(): array
    {
        return TelegramKeyboard::inline([[TelegramKeyboard::btn(__('telegram.menu_back'), 'm:conn')]]);
    }

    private function show(int|string $chatId, ?int $messageId, string $text, ?array $keyboard = null): void
    {
        $extra = [];
        if ($keyboard !== null) {
            $extra['reply_markup'] = $keyboard;
        }

        if ($messageId !== null) {
            $result = $this->bot->editMessageText($chatId, $messageId, $text, $extra);
            if (! ($result['ok'] ?? false)) {
                $this->bot->sendMessage($chatId, $text, $extra);
            }

            return;
        }

        $this->bot->sendMessage($chatId, $text, $extra);
    }

    private function showError(int|string $chatId, ?int $messageId, string $message): void
    {
        $this->show($chatId, $messageId, __('telegram.error_generic', ['message' => $this->esc($message)]));
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        $messages = $e->errors();
        foreach ($messages as $fieldMessages) {
            if (is_array($fieldMessages) && isset($fieldMessages[0])) {
                return (string) $fieldMessages[0];
            }
        }

        return __('telegram.error_generic', ['message' => 'validation']);
    }
}
