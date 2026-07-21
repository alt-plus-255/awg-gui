# dist/ — production install

## В git (коммитятся)

| Файл | Назначение |
|------|------------|
| `install.sh` | Online-установка: `sudo bash <(wget -O - .../dist/install.sh)` |
| `uninstall.sh` | Online-удаление |

## Не в git (GitHub Releases)

| Файл | Назначение |
|------|------------|
| `awg-gui-VERSION-ARCH.run` | Self-extracting bundle (~1 GB), собирается `./build.sh` |
| `awg-gui-VERSION-ARCH.run.sha256` | Checksum |

`.run` слишком большой для git. `install.sh` скачивает его с Releases через GitHub API.

## Сборка

```bash
./build.sh
# → dist/awg-gui-1.0.0-amd64.run
# опубликовать .run + .sha256 в GitHub Release для тега v1.0.0
```
