# Резолвер (точечный обход)

**Языки:** [Русский](resolver.md) | [English](../en/resolver.md) | [README](../../README.md)

Страница **Резолвер** включает режим split-tunnel по доменам для конфигов типа **Сервер** (не для виртуальных сетей).

## Как это работает

- списки сообщества из [allow-domains](https://github.com/itdoginfo/allow-domains) скачиваются на диск (`rulesets/*.srs`) и подключаются в sing-box как локальные;
- можно добавить свои домены и подсети;
- на сервере — sing-box FakeIP; в клиентском `.conf` — `DNS = gateway` и `AllowedIPs = <subnet>, 198.18.0.0/15`;
- остальной трафик (Speedtest, сайты вне списков) идёт через SIM/Wi‑Fi — это ожидаемо.

## AmneziaWG: переимпорт конфига

**iPhone и Android:** после включения или изменения резолвера **удалите сервер и заново импортируйте** QR/`.conf`. В конфиге должны быть:

```
DNS = gateway
AllowedIPs = <subnet>, 198.18.0.0/15
```

Без переимпорта списки (Telegram, YouTube, Meta…) не заработают.

## Диагностика и типичные проблемы

- **iPhone:** отключите iCloud Private Relay на время проверки.
- **Android:** отключите Private DNS / DoH; при сбоях Telegram очистите кэш приложения.
- Не проверяйте «весь VPN» через Speedtest — откройте сайт из списков.
- Кнопка **Диагностика** проверяет sing-box, наличие `.srs` на диске и DNS → FakeIP для каждого включённого списка.

## sing-box в образе AWG

Резолвер использует [sing-box](https://github.com/SagerNet/sing-box) внутри контейнера AWG. В production-сборке sing-box уже включён в образ; при dev-сборке tarball скачивается установщиком — см. [install.md](install.md#sing-box-vendor-только-dev-сборка).

Подробности лицензии и брендинга sing-box — в [README](../../README.md#sing-box-и-брендинг) и [NOTICE.md](../../NOTICE.md).
