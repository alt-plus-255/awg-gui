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

В интерактивном режиме сначала спрашивается **язык** (по умолчанию русский; можно выбрать English). Статусы установки выводятся на выбранном языке.

Без интерактива (порт панели **8877**, при существующей установке — режим обновления; kernel-модуль ставится по умолчанию; язык **ru**):

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo bash -s -- --yes
```

Английский язык сообщений без интерактива:

```bash
curl -fsSL .../dist/install.sh | sudo bash -s -- --yes --lang=en
# или: AWG_GUI_LANG=en
```

Пропустить kernel-модуль AmneziaWG (останется userspace `amneziawg-go`):

```bash
curl -fsSL .../dist/install.sh | sudo bash -s -- --yes --no-awg-kernel
# или: AWG_GUI_SKIP_KERNEL=1
```

Если модуль/пакет уже установлен на хосте, инсталлер **пропускает** повторную установку и выставляет `AWG_KERNEL_WANTED=1`.

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
# язык сообщений: --lang=en или AWG_GUI_LANG=en (по умолчанию ru)
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
  - **kernel-модуль AmneziaWG** (по умолчанию **Y**) — нужен для YouTube/Instagram ABR при полном туннеле + резолвере (всё равно нужен рабочий QUIC или устойчивый TCP-path; см. [resolver.md](resolver.md)); при ошибке установка продолжается на userspace. Уже установленный модуль пропускается. См. [amneziawg-linux-kernel-module](https://github.com/amnezia-vpn/amneziawg-linux-kernel-module). Только официальные пакеты Amnezia; панель не принимает произвольные команды с хоста.
- копирует `src/.env.example` → `src/.env` и заполняет значения, включая случайные **`DB_PASSWORD`**, **`APP_KEY`** и пароль admin.

Позже модуль можно установить или удалить в панели: **Настройки → Панель → Kernel-модуль AmneziaWG** (статус: модуль загружен, пакет установлен, datapath AWG kernel/userspace).

### Повторная установка / обновление

Если остался только `src/.env` без контейнеров (например после uninstall) — выполняется **чистая установка** с новыми случайными паролями.

Если обнаружены контейнеры `awggui-*`, скрипт предложит:

1. **Прервать** — рекомендуется перед чистой установкой выполнить [uninstall](uninstall.md);
2. **Обновить** — сохранить `.env`, volumes, данные БД и AWG; пересобрать образы и выполнить миграции.

С флагом `--yes` выбирается режим **обновления** автоматически.

В конце установки выводится справка по CLI и блок с учётными данными (URL, порт, `admin`, сгенерированный пароль).

### sing-box vendor (только dev-сборка)

Установщик из исходников скачивает tarball sing-box автоматически (версия из `src/awg/Dockerfile`). Ручная загрузка — fallback:

```bash
mkdir -p src/awg/vendor
curl -fsSL -o src/awg/vendor/sing-box-1.13.14-linux-amd64.tar.gz \
  https://github.com/SagerNet/sing-box/releases/download/v1.13.14/sing-box-1.13.14-linux-amd64.tar.gz
```

Для ARM замените `amd64` на `arm64` или `armv7`.
