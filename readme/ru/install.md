# Установка

**Языки:** [Русский](install.md) | [English](../en/install.md) | [README](../../README.md)

## Требования

- Root / sudo на Linux
- KVM (или хост с `/dev/net/tun`)
- Поддерживаемые ОС для автоустановки Docker: Ubuntu, Debian, Fedora, CentOS/RHEL/Rocky/Alma
- **Docker** и **curl** устанавливаются автоматически, если отсутствуют ([документация Docker Engine](https://docs.docker.com/engine/install/))

## Production (рекомендуется)

Скачивает готовый release-bundle из GitHub Releases. Исходники, `node_modules` и локальная сборка образов **не нужны**.

Краткая команда — в [README](../../README.md#быстрая-установка-production).

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo bash
```

Без интерактива (порт панели **8877**, при существующей установке — режим обновления):

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo bash -s -- --yes
```

Конкретная версия:

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo AWG_GUI_VERSION=1.0.0 bash -s -- --yes
```

Если `curl` недоступен:

```bash
wget --no-config -O /tmp/awg-gui-install.sh https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh
sudo bash /tmp/awg-gui-install.sh --yes
```

Установка распаковывает bundle в `/opt/awg-gui`, загружает Docker-образы и запускает панель.

Подробнее об удалении: [uninstall.md](uninstall.md).

## Установка из исходников (разработка)

Клонируйте репозиторий и используйте скрипты в корне — они **собирают образы локально**:

```bash
git clone https://github.com/alt-plus-255/awg-gui.git
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

### Повторная установка / обновление

Если обнаружены контейнеры `awggui-*` или `src/.env` с `DB_PASSWORD`, скрипт предложит:

1. **Прервать** — рекомендуется перед чистой установкой выполнить [uninstall](uninstall.md);
2. **Обновить** — сохранить `.env`, volumes, данные БД и AWG; пересобрать образы и выполнить миграции.

С флагом `--yes` выбирается режим **обновления** автоматически.

В конце установки выводится справка по CLI и блок с учётными данными (URL, порт, `admin`, сгенерированный пароль).

### sing-box vendor (только dev-сборка)

Установщик из исходников скачивает tarball sing-box автоматически (версия из `src/awg/Dockerfile`). Ручная загрузка — fallback:

```bash
mkdir -p src/awg/vendor
curl -fsSL -o src/awg/vendor/sing-box-1.12.12-linux-amd64.tar.gz \
  https://github.com/SagerNet/sing-box/releases/download/v1.12.12/sing-box-1.12.12-linux-amd64.tar.gz
```

Для ARM замените `amd64` на `arm64` или `armv7`.
