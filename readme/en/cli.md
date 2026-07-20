# Management CLI (`awg-gui`)

**Languages:** [Русский](../ru/cli.md) | [English](cli.md) | [README](../../README.en.md)

Installed to `/usr/local/bin/awg-gui`. Config: `/etc/awg-gui/awg-gui.conf`.

| Command | Description |
|---------|-------------|
| `awg-gui help` | Show help |
| `awg-gui status` | Show compose service status |
| `awg-gui ensure-up` | If containers are down, `compose up -d` |
| `awg-gui restart awg` | Restart AmneziaWG container |
| `awg-gui restart panel` | Restart Caddy + Laravel app |
| `awg-gui restart all` | Restart all project services |
| `awg-gui password` | Generate a random admin password and print it |
| `awg-gui password --random` | Same as above (explicit) |
| `awg-gui password --password=Secret` | Set admin password to the given value |
| `awg-gui 2fa status` | Show whether 2FA is enabled |
| `awg-gui 2fa disable` | Disable 2FA for admin (recovery) |
| `awg-gui endpoint` | Show public VPN endpoint (IP/DNS and AWG UDP port) |
| `awg-gui endpoint IP [PORT]` | Set public IP/hostname and optionally AWG UDP port |
| `awg-gui endpoint --auto` | Reset endpoint to auto-detect |
| `awg-gui endpoint PORT` | Change AWG UDP port only (51820–51839) |

## Public VPN endpoint

Client configs use `Endpoint = <public IP>:<UDP port>`. After install you can view or change it from the shell:

```bash
# Current values
sudo awg-gui endpoint

# Public IP or DNS only
sudo awg-gui endpoint 203.0.113.10
sudo awg-gui endpoint vpn.example.com

# IP/DNS and AWG UDP port (51820–51839)
sudo awg-gui endpoint 203.0.113.10 51821

# AWG UDP port only
sudo awg-gui endpoint 51821

# Auto-detect public endpoint
sudo awg-gui endpoint --auto
```

Changes are saved to the database, `.env` (`SERVER_ENDPOINT`, `AWG_PORT`) and `/etc/awg-gui/webhook.conf`. When the UDP port changes, AmneziaWG is restarted automatically. Re-export or re-import client `.conf` / QR codes after changing the endpoint so devices pick up the new `Endpoint` line.

## Admin password

After install the installer prints a one-time random password. To change it later:

```bash
# Random password (20 characters, printed to the terminal)
sudo awg-gui password
sudo awg-gui password --random

# Your own password
sudo awg-gui password --password='MyStr0ng!Pass'
```

The command updates the `admin` user in the Laravel database and prints the new value. Use single quotes if the password contains shell metacharacters (`$`, `` ` ``, `!`, `&`, etc.).

## Boot service

`awg-gui.service` runs `awg-gui ensure-up` after Docker on system boot.

```bash
systemctl status awg-gui
journalctl -u awg-gui -e
```
