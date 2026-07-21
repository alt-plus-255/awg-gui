# Сборка production release

**Языки:** [Русский](build-release.md) | [English](../en/build-release.md) | [README](../../README.md)

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

Опубликуйте `.run` и `.sha256` в **GitHub Releases** для тега `vVERSION`.

Файлы `dist/install.sh` и `dist/uninstall.sh` коммитятся в git — пользователи вызывают их через `wget` one-liner (см. [install.md](install.md)).

Подробнее о prod-bundle: [dist/README.md](../../dist/README.md).
