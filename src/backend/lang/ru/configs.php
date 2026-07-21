<?php

return [
    'port_taken' => 'Порт уже занят',
    'cannot_delete_last' => 'Нельзя удалить последний конфиг',
    'peer_already_bound' => 'Peer уже привязан к этому конфигу',
    'access_rules_vn_only' => 'Правила доступа доступны только для конфигов типа «Виртуальная сеть»',
    'preshared_key_disabled' => 'PresharedKey выключен для этого peer',
    'server_keys_regenerated' => 'Ключи сервера перегенерированы. Скачайте заново конфиги всех peer.',
    'admin_password_invalid' => 'Неверный пароль администратора',
    'vn_extra_allowed_ips_one' => 'Для виртуальной сети укажите ровно одну локальную подсеть (например 192.168.1.0/24)',
    'cannot_exclude_self' => 'Нельзя исключить самого себя',
    'excluded_not_bound' => 'Некоторые исключаемые узлы не привязаны к этому конфигу',
    'peer_cannot_be_src_and_dest' => 'Пир не может быть одновременно источником и назначением в одном правиле',
    'invalid_cidr' => 'Неверный CIDR: :cidr',
    'invalid_ip_in_cidr' => 'Неверный IP в CIDR: :cidr',
    'invalid_internal_subnet' => 'Неверный internal_subnet',
    'subnet_taken' => 'Подсеть уже занята другим конфигом',
    'no_free_addresses' => 'Нет свободных адресов в подсети',
    'config_limit_reached' => 'Достигнут лимит конфигов (:count)',
    'qr_too_large' => 'Данные слишком большие для QR-кода. Скопируйте конфиг или скачайте .conf.',
];
