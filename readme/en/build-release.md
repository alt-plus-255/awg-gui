# Build production release

**Languages:** [Русский](../ru/build-release.md) | [English](build-release.md) | [README](../../README.en.md)

From the repository root (requires Docker and curl):

```bash
./build.sh
# or explicitly:
./build.sh --version 1.0.0 --arch amd64
```

`build.sh`:

- builds Docker images from `src/`;
- exports them to `dist/awg-gui-VERSION-ARCH.run` (self-extracting bundle);
- writes `dist/awg-gui-VERSION-ARCH.run.sha256`.

Publish the `.run` and `.sha256` files to **GitHub Releases** for tag `vVERSION`.

Commit `dist/install.sh` and `dist/uninstall.sh` to git — users invoke them via the `wget` one-liner (see [install.md](install.md)).

More about the prod bundle: [dist/README.md](../../dist/README.md).
