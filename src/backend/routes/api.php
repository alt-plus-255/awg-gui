<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\DiagnosticsController;
use App\Http\Controllers\Api\ResolverConnectionController;
use App\Http\Controllers\Api\ResolverController;
use App\Http\Controllers\Api\ResolverCustomListController;
use App\Http\Controllers\Api\ResolverSettingsController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\WsTokenController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
Route::get('/login/status', [AuthController::class, 'loginStatus'])->middleware('throttle:60,1');
Route::get('/login/captcha', [AuthController::class, 'captcha'])->middleware('throttle:30,1');
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/me', [AuthController::class, 'me']);

Route::get('/2fa/status', [TwoFactorController::class, 'status']);
Route::post('/2fa/setup', [TwoFactorController::class, 'setup']);
Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm']);
Route::delete('/2fa', [TwoFactorController::class, 'destroy']);

Route::get('/system/status', [SystemController::class, 'status']);
Route::get('/system/processes', [SystemController::class, 'processes']);
Route::post('/system/restart-awg', [SystemController::class, 'restartAwg']);
Route::post('/system/restart-all', [SystemController::class, 'restartAll']);

Route::get('/diagnostics/status', [DiagnosticsController::class, 'status']);
Route::post('/diagnostics/run', [DiagnosticsController::class, 'run']);
Route::get('/diagnostics/configs/sing-box', [DiagnosticsController::class, 'singBoxConfig']);
Route::get('/diagnostics/configs/awg', [DiagnosticsController::class, 'awgConfigs']);

Route::get('/ws/token', [WsTokenController::class, 'show']);

Route::get('/stats', [StatsController::class, 'index']);
Route::post('/stats/refresh', [StatsController::class, 'refresh']);
Route::get('/stats/live', [StatsController::class, 'live']);

Route::get('/clients', [ClientController::class, 'index']);
Route::post('/clients', [ClientController::class, 'store']);
Route::put('/clients/{client}', [ClientController::class, 'update']);
Route::delete('/clients/{client}', [ClientController::class, 'destroy']);

Route::get('/configs', [ConfigController::class, 'index']);
Route::post('/configs', [ConfigController::class, 'store']);
Route::get('/configs/{config}', [ConfigController::class, 'show']);
Route::put('/configs/{config}', [ConfigController::class, 'update']);
Route::delete('/configs/{config}', [ConfigController::class, 'destroy']);
Route::get('/configs/{config}/server-config', [ConfigController::class, 'serverConfig']);
Route::post('/configs/{config}/regenerate-server-keys', [ConfigController::class, 'regenerateServerKeys']);
Route::post('/configs/{config}/regenerate-junk', [ConfigController::class, 'regenerateJunk']);
Route::post('/configs/{config}/reveal-server-key', [ConfigController::class, 'revealServerKey']);
Route::post('/configs/{config}/restart-awg', [ConfigController::class, 'restart']);
Route::put('/configs/{config}/zones', [ConfigController::class, 'updateZones']);

Route::get('/configs/{config}/peers', [ConfigController::class, 'peers']);
Route::get('/configs/{config}/links', [ConfigController::class, 'links']);
Route::post('/configs/{config}/peers', [ConfigController::class, 'attachPeer']);
Route::put('/configs/{config}/peers/{client}', [ConfigController::class, 'updatePeer']);
Route::delete('/configs/{config}/peers/{client}', [ConfigController::class, 'detachPeer']);
Route::get('/configs/{config}/peers/{client}/config', [ConfigController::class, 'peerConfig']);
Route::get('/configs/{config}/peers/{client}/vpn-uri', [ConfigController::class, 'peerVpnUri']);
Route::get('/configs/{config}/peers/{client}/qr', [ConfigController::class, 'peerQr']);
Route::post('/configs/{config}/peers/{client}/regenerate-keys', [ConfigController::class, 'regeneratePeerKeys']);
Route::post('/configs/{config}/peers/{client}/regenerate-psk', [ConfigController::class, 'regeneratePeerPsk']);
Route::post('/configs/{config}/peers/{client}/reveal-keys', [ConfigController::class, 'revealPeerKeys']);

Route::get('/resolver', [ResolverController::class, 'show']);
Route::put('/resolver/configs/{config}', [ResolverController::class, 'updateConfig']);
Route::post('/resolver/refresh', [ResolverController::class, 'refresh']);
Route::get('/resolver/diagnose', [ResolverController::class, 'diagnose']);

Route::get('/resolver/settings', [ResolverSettingsController::class, 'show']);
Route::put('/resolver/settings', [ResolverSettingsController::class, 'update']);
Route::post('/resolver/settings/sync-lists', [ResolverSettingsController::class, 'syncAll']);
Route::post('/resolver/settings/sync-lists/{tag}', [ResolverSettingsController::class, 'syncOne']);

Route::get('/resolver/custom-lists', [ResolverCustomListController::class, 'index']);
Route::post('/resolver/custom-lists', [ResolverCustomListController::class, 'store']);
Route::put('/resolver/custom-lists/{customList}', [ResolverCustomListController::class, 'update']);
Route::delete('/resolver/custom-lists/{customList}', [ResolverCustomListController::class, 'destroy']);

Route::get('/resolver/connections', [ResolverConnectionController::class, 'index']);
Route::post('/resolver/ping-probe/warmup', [ResolverConnectionController::class, 'warmupPingProbe']);
Route::post('/resolver/ping-probe/restart', [ResolverConnectionController::class, 'restartPingProbe']);
Route::post('/resolver/connections/parse-subscription', [ResolverConnectionController::class, 'parseSubscription']);
Route::post('/resolver/connections/ping-subscription', [ResolverConnectionController::class, 'pingSubscription']);
Route::post('/resolver/connections/ping-subscription-stream', [ResolverConnectionController::class, 'pingSubscriptionStream']);
Route::post('/resolver/connections/ping-subscription-node', [ResolverConnectionController::class, 'pingSubscriptionNode']);
Route::post('/resolver/connections', [ResolverConnectionController::class, 'store']);
Route::post('/resolver/connections/{connection}/ping-subscription', [ResolverConnectionController::class, 'pingConnectionSubscription']);
Route::post('/resolver/connections/{connection}/ping-subscription-stream', [ResolverConnectionController::class, 'pingConnectionSubscriptionStream']);
Route::post('/resolver/connections/{connection}/ping-subscription-node', [ResolverConnectionController::class, 'pingConnectionSubscriptionNode']);
Route::post('/resolver/connections/{connection}/sync-best-pick', [ResolverConnectionController::class, 'syncBestPick']);
Route::post('/resolver/connections/{connection}/test', [ResolverConnectionController::class, 'test']);
Route::put('/resolver/connections/{connection}', [ResolverConnectionController::class, 'update']);
Route::delete('/resolver/connections/{connection}', [ResolverConnectionController::class, 'destroy']);

Route::get('/settings', [SettingsController::class, 'show']);
Route::put('/settings', [SettingsController::class, 'update']);
Route::post('/settings/restart-awg', [SettingsController::class, 'restartAwg']);
Route::post('/settings/test-webhook', [SettingsController::class, 'testWebhook']);
Route::post('/settings/ssl/issue/start', [SettingsController::class, 'sslIssueStart']);
Route::post('/settings/ssl/issue/complete', [SettingsController::class, 'sslIssueComplete']);
Route::post('/settings/ssl/recover', [SettingsController::class, 'sslRecover']);
Route::post('/settings/ssl/disable', [SettingsController::class, 'sslDisable']);
Route::post('/settings/ssl/abort', [SettingsController::class, 'sslAbort']);
