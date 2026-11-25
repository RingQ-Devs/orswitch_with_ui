<?php
// OpenRings demo UI - settings. Copy to config.local.php to override without
// touching this file (config.local.php is read after this one if it exists).
return [
    // The switch's web portal / JSON API (the <web> listen address in orswitch.xml).
    'switch_url' => 'https://127.0.0.1:8443',

    // Demo only: the administrator account from orswitch.xml, used by the
    // dashboard, extensions, queues and IVR pages. CHANGE and keep it private.
    'admin_user' => 'admin',
    'admin_pass' => 'change-me',

    // The company (domain) to manage, e.g. the SIP domain of your sample extension.
    'domain' => 'example.com',

    // The switch uses a self-signed certificate by default.
    'verify_tls' => false,

    // WebRTC page: the path on THIS web server that is proxied to the switch's
    // /api/ws (see apache-proxy.conf). The browser must reach the switch
    // through the same host name as this demo, otherwise it refuses the socket.
    'ws_path' => '/orws',

    // Where the demo's users and login failures are kept. Default: demo-ui/data
    // (protected by .htaccess on Apache). Better: a folder outside the web root.
    // 'data_dir' => 'C:/openrings-demo-data',

    // true = no switch needed: pages show sample data (nothing is saved to a switch).
    'mock' => false,
];
