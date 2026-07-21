<?php

return [
    'port_taken' => 'Port is already in use',
    'cannot_delete_last' => 'Cannot delete the last config',
    'peer_already_bound' => 'Peer is already bound to this config',
    'access_rules_vn_only' => 'Access rules are only available for «Virtual network» configs',
    'preshared_key_disabled' => 'PresharedKey is disabled for this peer',
    'server_keys_regenerated' => 'Server keys regenerated. Re-download configs for all peers.',
    'admin_password_invalid' => 'Invalid administrator password',
    'vn_extra_allowed_ips_one' => 'For a virtual network, specify exactly one local subnet (e.g. 192.168.1.0/24)',
    'cannot_exclude_self' => 'Cannot exclude yourself',
    'excluded_not_bound' => 'Some excluded nodes are not bound to this config',
    'peer_cannot_be_src_and_dest' => 'A peer cannot be both source and destination in the same rule',
    'invalid_cidr' => 'Invalid CIDR: :cidr',
    'invalid_ip_in_cidr' => 'Invalid IP in CIDR: :cidr',
    'invalid_internal_subnet' => 'Invalid internal_subnet',
    'subnet_taken' => 'Subnet is already used by another config',
    'no_free_addresses' => 'No free addresses in the subnet',
    'config_limit_reached' => 'Config limit reached (:count)',
    'qr_too_large' => 'Data is too large for a QR code. Copy the config or download the .conf file.',
];
