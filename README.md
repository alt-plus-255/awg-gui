# AmneziaWG GUI (awggui)

**Языки / Languages:** [Русский](README.md) | [English](README.en.md)

VPN-сервер AmneziaWG (версии протокола **1.0**, **1.5** и **2.0**, по умолчанию **2.0**) с Laravel 12 API и админ-панелью на Quasar Vue. Все сервисы работают в Docker-контейнерах с префиксом `awggui`.

## Кейсы использования

| Схема | Где панель | Суть |
|-------|------------|------|
| **Сервер → клиент** | Зарубежная VDS | Классический VPN: весь трафик с IP сервера |
| **Сервер + резолвер (доступ к РФ)** | Зарубежная VDS | Основной выход — зарубежный IP; `russia_inside` → Подключение (VPN/прокси в РФ) |
| **Сервер-каскад → клиент** | РФ VDS | RU-сегмент с IP провайдера; `russia_outside` и сервисы → зарубежный hop |
| **Сервер виртуальных сетей** | Обычно РФ VDS | N роутеров/клиентов в одной LAN; у каждого роутера своя подсеть |
| **Дом / офис через роутер** | Любая VDS | Роутер как клиент Server-конфига — вся сеть за VPN |
| **Несколько ролей** | Одна VDS | До 20 конфигов: простой VPN, каскад и VN одновременно |

→ [Подробнее: кейсы использования](readme/ru/use-cases.md)

<p align="center">
  <img src="readme/assets/dashboard.png" alt="Дашборд AWG-GUI: ресурсы сервера, пиры и статус подключений" width="720">
  <br><br>
  <img src="readme/assets/connection-graph.png" alt="Граф соединений виртуальных сетей: зоны, пиры и трафик" width="720">
</p>

**Лицензия:** [GPL-3.0-or-later](LICENSE) · сторонние компоненты: [NOTICE.md](NOTICE.md)

## Быстрая установка (production)

Скачивает готовый release-bundle из GitHub Releases и разворачивает панель. Исходники, `node_modules` и локальная сборка образов **не нужны**.

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo bash
```

Без интерактива (порт панели **8877**, при существующей установке — режим обновления; **kernel-модуль AmneziaWG** ставится по умолчанию):

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo bash -s -- --yes
```

Пропустить kernel-модуль (`amneziawg-go` userspace): `--no-awg-kernel` или `AWG_GUI_SKIP_KERNEL=1`. Уже установленный модуль инсталлер не переустанавливает. Управление позже: **Настройки → Панель**.

Конкретная версия:

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo AWG_GUI_VERSION=1.0.0 bash -s -- --yes
```

Если `curl` недоступен, скачайте скрипт и запустите вручную:

```bash
wget --no-config -O /tmp/awg-gui-install.sh https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh
sudo bash /tmp/awg-gui-install.sh --yes
```

## Возможности

### Несколько конфигов AWG

До **20** конфигов AmneziaWG (UDP **51820–51839**): у каждого свой интерфейс, подсеть, порт и **версия протокола** (**1.0** / **1.5** / **2.0** — у разных конфигов версии могут отличаться). Типы **Сервер** (VPN в интернет) и **Виртуальная сеть** (изолированная LAN).

→ [Подробнее: конфиги и пиры](readme/ru/configs-and-peers.md)

### Версии протокола AmneziaWG

При создании конфига выбирается версия **1.0**, **1.5** или **2.0** (по умолчанию — последняя). Версии **несовместимы** между собой; после создания версия **не меняется**. Параметры обфускации, экспорт **`.conf`**, **QR** и ключ **`vpn://`** следуют выбранному профилю.

→ [Подробнее: конфиги и пиры](readme/ru/configs-and-peers.md#версии-протокола-amneziawg)

### Пиры и перепривязка

Пир (`vpn_client`) — отдельная сущность. Можно **привязать** к конфигу, **отвязать** (пир остаётся в панели), **перепривязать** к другому конфигу. Экспорт **`.conf`**, **QR** и **`vpn://`** для клиентов.

Для типа **Сервер** без резолвера: если у пира указаны CIDR в AllowedIPs, в клиентском `.conf` будет адрес интерфейса сервера + эти CIDR (split-tunnel) вместо полного туннеля. При включении резолвера — подтверждение и переход на `0.0.0.0/0`.

→ [Подробнее: конфиги и пиры](readme/ru/configs-and-peers.md)

### Виртуальные LAN

Конфиги типа «Виртуальная сеть»: изолированная подсеть, политики «все видят всех» / «изоляция», зоны доступа, исключения между пирами, **граф связей** с онлайн-статусом и трафиком между пирами.

→ [Подробнее: виртуальные сети](readme/ru/virtual-networks.md)

### Резолвер

Для конфигов типа **Сервер** (не для виртуальных сетей): маршрутизация трафика по доменам и подсетям через sing-box — community-списки ([allow-domains](https://github.com/itdoginfo/allow-domains)), свои домены и CIDR. Точка выхода в интернет — **Подключение**: VLESS / подписка, outbound JSON или **WG/AWG** (удалённый `.conf` AmneziaWG / WireGuard с совпадающей версией протокола).

Резолвер на странице **Резолвер**:

#### Полный туннель на VDS

`AllowedIPs = 0.0.0.0/0, ::/0` · `DNS = gateway`

| Что | Куда идёт |
|-----|-----------|
| **Весь** трафик клиента | На VDS (AmneziaWG-туннель) |
| Домены из списков (Telegram, YouTube, Meta…) | Через выбранное **Подключение** (sing-box FakeIP → outbound) |
| Остальное (2ip.ru, Speedtest, сайты вне списков) | С **IP сервера VDS** |
| IP-CIDR из community-списков | **Полностью** проксируются |

Подходит, когда нужен классический «весь VPN через сервер», но с точечным выходом в интернет для заблокированных ресурсов через отдельное подключение.

Для ABR-видео (YouTube/Instagram) нужен **kernel AmneziaWG** на хосте VDS плюс рабочий QUIC (Block QUIC выкл + UDP-capable outbound) или устойчивый TCP-path. Доставка: TCP FakeIP/list через **NAT REDIRECT** `:1602`, UDP FakeIP через **TPROXY** `:1603` (sing-box **1.13.x**).

**После включения или выключения:** удалите сервер в AmneziaWG и **заново импортируйте** QR/`.conf` — без переимпорта списки не заработают. Если у пиров были кастомные AllowedIPs, при включении резолвера панель покажет диалог: полный туннель заменит split-tunnel.

→ [Подробнее: резолвер, диагностика, переимпорт](readme/ru/resolver.md)

### Telegram-бот

Удалённое управление панелью из Telegram: конфиги, пиры, подключения и резолвер, уведомления online/offline. Доступ только у указанного Admin ID. Режимы **long polling** (с пулом SOCKS/HTTP или подключений резолвера) и **webhook**.

→ [Подробнее: Telegram-бот](readme/ru/telegram.md)

## Документация

| Раздел | Описание |
|--------|----------|
| [Установка](readme/ru/install.md) | Требования, production и dev install, обновление |
| [Удаление](readme/ru/uninstall.md) | Production и dev uninstall |
| [Сборка release](readme/ru/build-release.md) | `./build.sh`, `.run`, GitHub Releases |
| [CLI](readme/ru/cli.md) | `awg-gui`: info, endpoint, password, 2FA, systemd |
| [Webhook](readme/ru/webhook.md) | JSON schema оповещений о сбоях |
| [Telegram-бот](readme/ru/telegram.md) | Настройка, polling/webhook, меню, уведомления, прокси |
| [Кейсы использования](readme/ru/use-cases.md) | Сервер→клиент, резолвер/каскад, VN-роутеры, дом за VPN |
| [Конфиги и пиры](readme/ru/configs-and-peers.md) | Мульти-конфиг, версии протокола, attach/detach, экспорт |
| [Виртуальные сети](readme/ru/virtual-networks.md) | VN, зоны, исключения |
| [Резолвер](readme/ru/resolver.md) | Полный туннель, списки, подключения (в т.ч. WG/AWG), диагностика |
| [Структура проекта](readme/ru/project-structure.md) | Каталоги, Docker-контейнеры |

English: [readme/en/](readme/en/)

## Лицензия

Проект **awg-gui** (исходники панели, скрипты установки, Docker-описания) распространяется под
**[GNU General Public License v3.0 or later](LICENSE)** (GPL-3.0-or-later).

Release-bundle (`.run`) и Docker-образы содержат сторонние программы с **другими**
лицензиями — в том числе **GPL-2.0** (amneziawg-tools) и **GPL-3.0** (sing-box, MariaDB).
Полный список, версии и ссылки на исходники: **[NOTICE.md](NOTICE.md)**.

### sing-box и брендинг

Резолвер использует [sing-box](https://github.com/SagerNet/sing-box) как компонент внутри
контейнера AWG. **awg-gui не является официальным продуктом sing-box / SagerNet.**
У sing-box есть дополнительное условие: производные работы не должны использовать имя
sing-box или создавать впечатление аффилированности без согласия правообладателя.
Подробности — в [NOTICE.md](NOTICE.md).

При распространении `.run` или образов соблюдайте GPL: предоставляйте текст лицензии,
`NOTICE.md` и возможность получить исходный код GPL-компонентов (см. NOTICE.md).
