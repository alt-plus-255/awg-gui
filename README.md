# AmneziaWG GUI (awggui)

**Языки / Languages:** [Русский](README.md) | [English](README.en.md)

AmneziaWG 2.0 VPN-сервер с Laravel 12 API и админ-панелью на Quasar Vue. Все сервисы работают в Docker-контейнерах с префиксом `awggui`.

**Лицензия:** [GPL-3.0-or-later](LICENSE) · сторонние компоненты: [NOTICE.md](NOTICE.md)

## Требования

- Root / sudo на Linux
- KVM (или хост с `/dev/net/tun`)
- Поддерживаемые ОС для автоустановки Docker: Ubuntu, Debian, Fedora, CentOS/RHEL/Rocky/Alma
- **Docker** и **curl** устанавливаются автоматически, если отсутствуют ([документация Docker Engine](https://docs.docker.com/engine/install/))

## Установка

### Быстрая установка (production)

Скачивает готовый release-bundle из GitHub Releases и разворачивает панель. Исходники, `node_modules` и локальная сборка образов **не нужны**.

Замените `YOUR_ORG/awg-gui` на свой репозиторий:

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/install.sh)
```

Без интерактива (порт панели **8877**, при существующей установке — режим обновления):

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/install.sh) --yes
```

Конкретная версия:

```bash
sudo AWG_GUI_VERSION=1.0.0 bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/install.sh) --yes
```

### Удаление (production)

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/uninstall.sh)
```

Также удалить локальные Docker-образы awggui:

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/uninstall.sh) --yes --images
```

Удалить и каталог установки `/opt/awg-gui`:

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/uninstall.sh) --yes --images --purge
```

### Установка из исходников (разработка)

Клонируйте репозиторий и используйте скрипты в корне — они **собирают образы локально**:

```bash
git clone https://github.com/YOUR_ORG/awg-gui.git
cd awg-gui
sudo ./awg-gui-install.sh
# или без интерактива (порт панели 8877, upgrade при существующей установке):
sudo ./awg-gui-install.sh --yes
```

Dev-скрипт установки:

- при необходимости скачивает tarball **sing-box** в `src/awg/vendor/` (для образа AWG);
- спрашивает параметры (если не `--yes`):
  - порт панели (по умолчанию **8877**);
  - UDP-порт AmneziaWG / `AWG_PORT` (по умолчанию **51820**);
  - endpoint сервера (публичный IP/DNS);
  - внутренняя подсеть / `INTERNAL_SUBNET` (по умолчанию **10.66.66.0/24**);
  - DNS для клиентов / `PEER_DNS` (по умолчанию **1.1.1.1**);
  - AllowedIPs / `ALLOWED_IPS` (по умолчанию **0.0.0.0/0, ::/0**);
- копирует `src/.env.example` → `src/.env` и заполняет значения, включая случайные **`DB_PASSWORD`**, **`APP_KEY`** и пароль admin.

### Повторная установка

Если обнаружены контейнеры `awggui-*` или `src/.env` с `DB_PASSWORD`, скрипт предложит:

1. **Прервать** — рекомендуется перед чистой установкой выполнить uninstall;
2. **Обновить** — сохранить `.env`, volumes, данные БД и AWG; пересобрать образы и выполнить миграции.

С флагом `--yes` выбирается режим **обновления** автоматически.

В конце установки выводится справка по CLI и блок с учётными данными (URL, порт, `admin`, сгенерированный пароль).

## Удаление (разработка)

```bash
sudo ./awg-gui-uninstall.sh
sudo ./awg-gui-uninstall.sh --yes --images   # также удалить локальные образы
```

Останавливает и удаляет контейнеры и volumes `awggui`, отключает systemd `awg-gui.service`, удаляет `/usr/local/bin/awg-gui` и `/etc/awg-gui`. Пути берутся из `/etc/awg-gui/awg-gui.conf`, если файл существует.

**Не удаляет:** Docker Engine, файлы репозитория и `src/.env`.

## Сборка production release

Из корня репозитория (нужны Docker и curl):

```bash
./build.sh
# или явно:
./build.sh --version 1.0.0 --arch amd64
```

Скрипт `build.sh`:

- собирает Docker-образы из `src/`;
- экспортирует их в `dist/awg-gui-VERSION-ARCH.run` (self-extracting bundle);
- создаёт `dist/awg-gui-VERSION-ARCH.run.sha256`.

Опубликуйте `.run` и `.sha256` в **GitHub Releases** для тега `vVERSION`. Файлы `dist/install.sh` и `dist/uninstall.sh` коммитятся в git — пользователи вызывают их через `wget` one-liner (см. выше).

## CLI управления (`awg-gui`)

Устанавливается в `/usr/local/bin/awg-gui`. Конфиг: `/etc/awg-gui/awg-gui.conf`.

| Команда | Описание |
|---------|----------|
| `awg-gui help` | Справка |
| `awg-gui status` | Статус сервисов compose |
| `awg-gui ensure-up` | Если контейнеры остановлены — `compose up -d` |
| `awg-gui restart awg` | Перезапуск контейнера AmneziaWG |
| `awg-gui restart panel` | Перезапуск Caddy + Laravel app |
| `awg-gui restart all` | Перезапуск всех сервисов |
| `awg-gui password` | Сгенерировать случайный пароль admin |
| `awg-gui password --random` | То же явно |
| `awg-gui password --password=Secret` | Задать свой пароль admin |
| `awg-gui 2fa status` | Статус 2FA |
| `awg-gui 2fa disable` | Отключить 2FA (восстановление доступа) |
| `awg-gui endpoint` | Показать публичный VPN endpoint (IP/DNS и UDP-порт) |
| `awg-gui endpoint IP [PORT]` | Задать IP/hostname и при необходимости UDP-порт |
| `awg-gui endpoint --auto` | Сбросить endpoint на автоопределение |
| `awg-gui endpoint PORT` | Изменить только UDP-порт AWG (51820–51839) |

### Публичный VPN endpoint

В клиентских конфигах используется `Endpoint = <публичный IP>:<UDP-порт>`. После установки можно просмотреть или изменить из shell:

```bash
# Текущие значения
sudo awg-gui endpoint

# Только IP или DNS
sudo awg-gui endpoint 203.0.113.10
sudo awg-gui endpoint vpn.example.com

# IP/DNS и UDP-порт AWG (51820–51839)
sudo awg-gui endpoint 203.0.113.10 51821

# Только UDP-порт AWG
sudo awg-gui endpoint 51821

# Автоопределение публичного endpoint
sudo awg-gui endpoint --auto
```

Изменения сохраняются в БД, `src/.env` (`SERVER_ENDPOINT`, `AWG_PORT`) и `/etc/awg-gui/webhook.conf`. При смене UDP-порта AmneziaWG перезапускается автоматически. После изменения endpoint переэкспортируйте или заново импортируйте клиентские `.conf` / QR-коды.

### Пароль администратора

После установки скрипт выводит одноразовый случайный пароль. Изменить позже:

```bash
# Случайный пароль (20 символов, выводится в терминал)
sudo awg-gui password
sudo awg-gui password --random

# Свой пароль
sudo awg-gui password --password='MyStr0ng!Pass'
```

Команда обновляет пользователя `admin` в БД Laravel. Используйте одинарные кавычки, если пароль содержит символы shell (`$`, `` ` ``, `!`, `&` и т.д.).

### Служба автозапуска

`awg-gui.service` выполняет `awg-gui ensure-up` после старта Docker при загрузке системы.

```bash
systemctl status awg-gui
journalctl -u awg-gui -e
```

## Webhook сбоев

В **Settings** задайте **Оповещение о сбое (ссылка на эндпоинт)**. При сбое загрузки (Docker недоступен / compose fail / unhealthy services) CLI отправляет JSON через `curl` на этот URL. URL дублируется в `/etc/awg-gui/webhook.conf`, чтобы уведомления работали даже когда Laravel недоступен.

### JSON schema `1.0`

```json
{
  "schema_version": "1.0",
  "event": "awg_gui.failure",
  "severity": "error",
  "source": "awg-gui",
  "project": "awggui",
  "hostname": "vpn.example.com",
  "timestamp": "2026-07-15T10:58:00Z",
  "code": "docker_unavailable",
  "message": "Docker daemon did not become ready within timeout",
  "panel_url": "http://203.0.113.10:8877",
  "details": {
    "attempt": 1,
    "services": ["caddy", "app", "db", "awg"],
    "stderr": "..."
  }
}
```

Стабильные значения `code`: `docker_unavailable`, `compose_up_failed`, `service_unhealthy`, `awg_gui.test`.

Контракт: `POST`, `Content-Type: application/json`.

## Структура проекта

```
awg-gui-install.sh
awg-gui-uninstall.sh
build.sh
VERSION
LICENSE
NOTICE.md
README.md
README.en.md
dist/
  install.sh          # production online installer (wget one-liner)
  uninstall.sh        # production online uninstaller
src/
  docker-compose.yml
  .env.example
  bin/awg-gui
  systemd/awg-gui.service
  scripts/
    build-dist.sh
    release/          # prod bundle templates
  caddy/
  awg/
  backend/     # Laravel 12
  frontend/    # Quasar (Vite)
```

Имя compose-проекта: **`awggui`**. Контейнеры: `awggui-caddy`, `awggui-app`, `awggui-db`, `awggui-awg`.

## Настройки панели

В **Settings** можно изменить endpoint панели, порт и webhook сбоев.

Интерфейсы AWG, пиры, ключи и AllowedIPs управляются на странице **Конфиги и пиры**.

### Резолвер (точечный обход)

Страница **Резолвер** включает режим split-tunnel по доменам для конфигов типа **Сервер** (не VN):

- списки сообщества из [allow-domains](https://github.com/itdoginfo/allow-domains) скачиваются на диск (`rulesets/*.srs`) и подключаются в sing-box как локальные;
- свои домены и подсети;
- на сервере — sing-box FakeIP; в клиентском `.conf` — `DNS = gateway` и `AllowedIPs = <subnet>, 198.18.0.0/15`;
- остальной трафик (Speedtest, сайты вне списков) идёт через SIM/Wi‑Fi — это ожидаемо.

**AmneziaWG (iPhone и Android):** после включения или изменения резолвера **удалите сервер и заново импортируйте** QR/`.conf`. В конфиге должны быть `DNS = gateway` и `AllowedIPs` с `198.18.0.0/15`. Без переимпорта списки (Telegram, YouTube, Meta…) не заработают.

На iPhone отключите iCloud Private Relay на время проверки. На Android отключите Private DNS / DoH; при сбоях Telegram очистите кэш приложения. Не проверяйте «весь VPN» через Speedtest — откройте сайт из списков. Кнопка **Диагностика** проверяет sing-box, наличие `.srs` на диске и DNS → FakeIP для каждого включённого списка.

### sing-box vendor

Установщик скачивает tarball sing-box автоматически (версия из `src/awg/Dockerfile`). Ручная загрузка — fallback:

```bash
mkdir -p src/awg/vendor
curl -fsSL -o src/awg/vendor/sing-box-1.12.12-linux-amd64.tar.gz \
  https://github.com/SagerNet/sing-box/releases/download/v1.12.12/sing-box-1.12.12-linux-amd64.tar.gz
```

Для ARM замените `amd64` на `arm64` или `armv7`.

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
