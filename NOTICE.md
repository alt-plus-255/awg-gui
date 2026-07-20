# Third-Party Notices

This document lists licenses for software **bundled in awg-gui Docker images**
and major dependencies used to build the project. The **awg-gui** application
source (panel, install scripts, Docker definitions) is licensed under
**GNU General Public License v3.0** — see [LICENSE](LICENSE).

When you distribute release bundles (`.run`) or pre-built images, you must
comply with the licenses of all included components, including offering
corresponding source where required by GPL.

---

## Bundled in `awggui-awg` container

These binaries are compiled or copied into the AWG image during `docker build`
(see `src/awg/Dockerfile`).

| Component | Version (pinned in build) | License | Source |
|-----------|---------------------------|---------|--------|
| [sing-box](https://github.com/SagerNet/sing-box) | 1.12.12 | **GPL-3.0** + [additional terms](https://github.com/SagerNet/sing-box/blob/dev/LICENSE) | https://github.com/SagerNet/sing-box/tree/v1.12.12 |
| [amneziawg-go](https://github.com/amnezia-vpn/amneziawg-go) | latest `master` at build time | **MIT** | https://github.com/amnezia-vpn/amneziawg-go |
| [amneziawg-tools](https://github.com/amnezia-vpn/amneziawg-tools) | latest `master` at build time | **GPL-2.0** | https://github.com/amnezia-vpn/amneziawg-tools |

### sing-box — naming restriction

sing-box is licensed under GPL-3.0 with an **additional clause**:

> In addition, no derivative work may use the name or imply association
> with this application without prior consent.

**awg-gui** is an independent project. It uses sing-box as a backend component
for the resolver feature. This project is **not** affiliated with, endorsed by,
or maintained by the SagerNet / sing-box authors unless explicitly stated
otherwise. Do not use the name “sing-box” in a way that suggests an official
product or fork without permission from the sing-box copyright holder.

---

## Bundled in `awggui-app` container

Built from `src/backend/` (Laravel). Runtime PHP dependencies are installed
via Composer inside the image (`composer install --no-dev`).

| Component | License | Notes |
|-----------|---------|--------|
| [Laravel](https://laravel.com/) | MIT | Framework |
| Other Composer packages | MIT / BSD / Apache-2.0 (per package) | See `src/backend/composer.lock` |

Source for the application layer: this repository (`src/backend/`).

---

## Bundled in `awggui-caddy` container

| Component | License | Notes |
|-----------|---------|--------|
| [Caddy](https://caddyserver.com/) | Apache-2.0 | Base image |
| Quasar / Vue SPA (built at image build) | MIT (dependencies) | See `src/frontend/package-lock.json` |

Source for the frontend: this repository (`src/frontend/`).

---

## Bundled in `awggui-db` container

| Component | Version (production release) | License | Source |
|-----------|------------------------------|---------|--------|
| [MariaDB](https://mariadb.org/) | 11.4 (pinned in release compose) | **GPL-2.0** | https://github.com/MariaDB/server |

Development `docker-compose.yml` may use `mariadb:latest`; production release
build (`src/scripts/release/docker-compose.release.yml`) pins `mariadb:11.4`.

---

## Community rulesets (runtime download)

The resolver may download domain lists from third-party repositories
(e.g. [allow-domains](https://github.com/itdoginfo/allow-domains)). Those
files are **not** shipped in awg-gui releases; licenses apply to the upstream
projects when you enable those lists.

---

## Obtaining source for GPL components

For **awg-gui** itself:

- Source: this Git repository
- Corresponding source for a given release tag `vX.Y.Z` matches commit tagged in GitHub Releases

For **sing-box** (version in your image):

```bash
git clone https://github.com/SagerNet/sing-box.git
cd sing-box
git checkout v1.12.12
```

For **amneziawg-tools**:

```bash
git clone https://github.com/amnezia-vpn/amneziawg-tools.git
```

For **MariaDB 11.4**:

```bash
git clone https://github.com/MariaDB/server.git
# use tag/release matching mariadb:11.4 image
```

When distributing `dist/awg-gui-*.run` bundles, include or link to this
`NOTICE.md`, the full [LICENSE](LICENSE), and ensure recipients can obtain
GPL source for bundled binaries as required by those licenses.

---

## Disclaimer

This file is provided for convenience and is not legal advice. If you
redistribute awg-gui or modified versions, consult a qualified attorney for
compliance with GPL and other applicable licenses.
