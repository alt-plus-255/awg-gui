package i18n

import "strings"

func init() {
	for k, v := range kernelEN {
		messages["en"][k] = v
	}
	for k, v := range kernelRU {
		messages["ru"][k] = v
	}
}

var kernelEN = map[string]string{
	"settings.panel_ops_unavailable":     "Panel operations service is unavailable",
	"settings.ssl_unavailable":           "HTTPS certificate service is unavailable",
	"settings.awg_kernel_started_install":  "Kernel module install has started.",
	"settings.awg_kernel_started_uninstall": "Kernel module uninstall has started.",
	"settings.awg_kernel_started_recover":  "AWG kernel datapath recovery has started.",

	"kernel.msg_recovering":              "Recovering AmneziaWG kernel datapath...",
	"kernel.msg_installing":              "Installing AmneziaWG kernel module...",
	"kernel.msg_removing":                "Removing AmneziaWG kernel module...",
	"kernel.msg_blacklisted":             "blacklist-amneziawg.conf present — module will not load after reboot (userspace fallback). Remove /etc/modprobe.d/blacklist-amneziawg.conf or re-run Install kernel module.",
	"kernel.msg_userspace_despite_pkg":   "Package installed, but AWG datapath is userspace (module not loaded or amneziawg-go still running). Run: modprobe amneziawg && docker restart awggui-awg",
	"kernel.msg_install_finished":        "Kernel module install finished.",
	"kernel.msg_recover_finished":        "AWG kernel datapath recovery finished.",
	"kernel.msg_uninstall_finished":      "Kernel module uninstall finished.",
	"kernel.msg_recovered_kernel":        "AWG recovered to kernel datapath",
	"kernel.msg_container_starting":      "Kernel module loaded; AWG container still starting — refresh status in a minute",
	"kernel.msg_userspace_setconf_failed": "Module loaded but AWG still on userspace (awg-quick/setconf failed — check dmesg for amneziawg oops)",
	"kernel.msg_pkg_not_loaded":          "Package installed but module not loaded — check modprobe/dkms build",
	"kernel.msg_not_installed":           "Kernel module not installed — run install first",
	"kernel.msg_unsupported_os":          "Unsupported OS for AmneziaWG kernel module (need Ubuntu/Debian/RHEL family)",
	"kernel.msg_installed_not_loaded":    "AmneziaWG kernel module installed; module not loaded yet — reboot or modprobe amneziawg",
	"kernel.msg_installed_container_starting": "AmneziaWG kernel module installed; AWG container still starting — refresh status in a minute",
	"kernel.msg_installed_kernel":        "AmneziaWG kernel module installed; AWG using kernel datapath",
	"kernel.msg_installed_userspace":     "Kernel module loaded but AWG still on userspace (awg-quick/setconf failed — check dmesg for amneziawg oops). AWG will retry kernel after backoff or use stable userspace.",
	"kernel.msg_unsupported_os_purge":    "Unsupported OS; cannot purge packages safely",
	"kernel.msg_removed_userspace":       "AmneziaWG kernel module removed; AWG will use userspace fallback",
	"kernel.msg_helper_failed_prefix":    "Kernel helper exited with error:",
}

var kernelRU = map[string]string{
	"settings.panel_ops_unavailable":     "Служба операций панели недоступна",
	"settings.ssl_unavailable":           "Служба HTTPS-сертификатов недоступна",
	"settings.awg_kernel_started_install":  "Запущена установка kernel-модуля.",
	"settings.awg_kernel_started_uninstall": "Запущено удаление kernel-модуля.",
	"settings.awg_kernel_started_recover":  "Запущено восстановление kernel datapath AWG.",

	"kernel.msg_recovering":              "Восстановление kernel datapath AmneziaWG…",
	"kernel.msg_installing":              "Установка kernel-модуля AmneziaWG…",
	"kernel.msg_removing":                "Удаление kernel-модуля AmneziaWG…",
	"kernel.msg_blacklisted":             "Обнаружен blacklist-amneziawg.conf — модуль не загрузится после перезагрузки (userspace). Удалите /etc/modprobe.d/blacklist-amneziawg.conf или нажмите «Восстановить ядро».",
	"kernel.msg_userspace_despite_pkg":   "Пакет установлен, но AWG на userspace (модуль не загружен или всё ещё работает amneziawg-go). Выполните: modprobe amneziawg && docker restart awggui-awg",
	"kernel.msg_install_finished":        "Установка kernel-модуля завершена.",
	"kernel.msg_recover_finished":        "Восстановление kernel datapath AWG завершено.",
	"kernel.msg_uninstall_finished":      "Удаление kernel-модуля завершено.",
	"kernel.msg_recovered_kernel":        "AWG переведён на kernel datapath",
	"kernel.msg_container_starting":      "Модуль загружен; контейнер AWG ещё запускается — обновите статус через минуту",
	"kernel.msg_userspace_setconf_failed": "Модуль загружен, но AWG на userspace (awg-quick/setconf не удался — проверьте dmesg на oops amneziawg)",
	"kernel.msg_pkg_not_loaded":          "Пакет установлен, но модуль не загружен — проверьте modprobe/DKMS",
	"kernel.msg_not_installed":           "Kernel-модуль не установлен — сначала выполните установку",
	"kernel.msg_unsupported_os":          "Неподдерживаемая ОС для kernel-модуля AmneziaWG (нужен Ubuntu/Debian/RHEL)",
	"kernel.msg_installed_not_loaded":    "Kernel-модуль AmneziaWG установлен; модуль ещё не загружен — перезагрузите или modprobe amneziawg",
	"kernel.msg_installed_container_starting": "Kernel-модуль AmneziaWG установлен; контейнер AWG ещё запускается — обновите статус через минуту",
	"kernel.msg_installed_kernel":        "Kernel-модуль AmneziaWG установлен; AWG использует kernel datapath",
	"kernel.msg_installed_userspace":     "Модуль загружен, но AWG на userspace (awg-quick/setconf не удался — проверьте dmesg). AWG повторит попытку kernel или останется на userspace.",
	"kernel.msg_unsupported_os_purge":    "Неподдерживаемая ОС; безопасное удаление пакетов невозможно",
	"kernel.msg_removed_userspace":       "Kernel-модуль AmneziaWG удалён; AWG перейдёт на userspace",
	"kernel.msg_helper_failed_prefix":    "Ошибка host-скрипта kernel-модуля:",
}

var kernelMessageKeys = map[string]string{
	"Recovering AmneziaWG kernel datapath...":                                                                                "kernel.msg_recovering",
	"Installing AmneziaWG kernel module...":                                                                                  "kernel.msg_installing",
	"Removing AmneziaWG kernel module...":                                                                                    "kernel.msg_removing",
	"blacklist-amneziawg.conf present — module will not load after reboot (userspace fallback). Remove /etc/modprobe.d/blacklist-amneziawg.conf or re-run Install kernel module.": "kernel.msg_blacklisted",
	"Package installed, but AWG datapath is userspace (module not loaded or amneziawg-go still running). Run: modprobe amneziawg && docker restart awggui-awg": "kernel.msg_userspace_despite_pkg",
	"Kernel module install finished.":                                                                                        "kernel.msg_install_finished",
	"AWG kernel datapath recovery finished.":                                                                                 "kernel.msg_recover_finished",
	"Kernel module uninstall finished.":                                                                                      "kernel.msg_uninstall_finished",
	"Kernel module install has started.":                                                                                     "settings.awg_kernel_started_install",
	"Kernel module uninstall has started.":                                                                                   "settings.awg_kernel_started_uninstall",
	"AWG kernel datapath recovery has started.":                                                                              "settings.awg_kernel_started_recover",
	"AWG recovered to kernel datapath":                                                                                        "kernel.msg_recovered_kernel",
	"Kernel module loaded; AWG container still starting — refresh status in a minute":                                          "kernel.msg_container_starting",
	"Module loaded but AWG still on userspace (awg-quick/setconf failed — check dmesg for amneziawg oops)":                  "kernel.msg_userspace_setconf_failed",
	"Package installed but module not loaded — check modprobe/dkms build":                                                      "kernel.msg_pkg_not_loaded",
	"Kernel module not installed — run install first":                                                                        "kernel.msg_not_installed",
	"Unsupported OS for AmneziaWG kernel module (need Ubuntu/Debian/RHEL family)":                                              "kernel.msg_unsupported_os",
	"AmneziaWG kernel module installed; module not loaded yet — reboot or modprobe amneziawg":                                  "kernel.msg_installed_not_loaded",
	"AmneziaWG kernel module installed; AWG container still starting — refresh status in a minute":                           "kernel.msg_installed_container_starting",
	"AmneziaWG kernel module installed; AWG using kernel datapath":                                                           "kernel.msg_installed_kernel",
	"Kernel module loaded but AWG still on userspace (awg-quick/setconf failed — check dmesg for amneziawg oops). AWG will retry kernel after backoff or use stable userspace.": "kernel.msg_installed_userspace",
	"Unsupported OS; cannot purge packages safely":                                                                           "kernel.msg_unsupported_os_purge",
	"AmneziaWG kernel module removed; AWG will use userspace fallback":                                                       "kernel.msg_removed_userspace",
	"panel-ops unavailable":                                                                                                  "settings.panel_ops_unavailable",
}

// LocalizeKernelMessage translates known kernel/panel-ops status strings.
func LocalizeKernelMessage(locale, msg string) string {
	msg = strings.TrimSpace(msg)
	if msg == "" {
		return msg
	}
	if key, ok := kernelMessageKeys[msg]; ok {
		return T(locale, key)
	}
	if strings.HasPrefix(msg, "Kernel helper exited with error:") {
		return T(locale, "kernel.msg_helper_failed_prefix") + strings.TrimPrefix(msg, "Kernel helper exited with error:")
	}
	return msg
}

// LocalizeAWGKernelPayload translates user-visible strings in an AWG kernel status payload.
func LocalizeAWGKernelPayload(locale string, data map[string]any) {
	if data == nil {
		return
	}
	if s, ok := data["message"].(string); ok && s != "" {
		data["message"] = LocalizeKernelMessage(locale, s)
	}
	op, ok := data["op"].(map[string]any)
	if !ok {
		return
	}
	if s, ok := op["message"].(string); ok && s != "" {
		op["message"] = LocalizeKernelMessage(locale, s)
	}
}
