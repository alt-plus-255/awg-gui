# CLI управления (`awg-gui`)

**Языки:** [Русский](cli.md) | [English](../en/cli.md) | [README](../../README.md)

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

## Публичный VPN endpoint

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

Изменения сохраняются в БД, `.env` (`SERVER_ENDPOINT`, `AWG_PORT`) и `/etc/awg-gui/webhook.conf`. При смене UDP-порта AmneziaWG перезапускается автоматически. После изменения endpoint переэкспортируйте или заново импортируйте клиентские `.conf` / QR-коды.

## Пароль администратора

После установки скрипт выводит одноразовый случайный пароль. Изменить позже:

```bash
# Случайный пароль (20 символов, выводится в терминал)
sudo awg-gui password
sudo awg-gui password --random

# Свой пароль
sudo awg-gui password --password='MyStr0ng!Pass'
```

Команда обновляет пользователя `admin` в БД Laravel. Используйте одинарные кавычки, если пароль содержит символы shell (`$`, `` ` ``, `!`, `&` и т.д.).

## Служба автозапуска

`awg-gui.service` выполняет `awg-gui ensure-up` после старта Docker при загрузке системы.

```bash
systemctl status awg-gui
journalctl -u awg-gui -e
```
