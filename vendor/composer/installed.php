<?php return array(
    'root' => array(
        'name' => 'wpelevator/update-pilot',
        'pretty_version' => 'dev-main',
        'version' => 'dev-main',
        'reference' => 'ed520e8a22acd224003ad337764756a06128a662',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(
            0 => '0.1.x-dev',
        ),
        'dev' => false,
    ),
    'versions' => array(
        'composer/installers' => array(
            'pretty_version' => 'v1.12.0',
            'version' => '1.12.0.0',
            'reference' => 'd20a64ed3c94748397ff5973488761b22f6d3f19',
            'type' => 'composer-plugin',
            'install_path' => __DIR__ . '/./installers',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'roundcube/plugin-installer' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'shama/baton' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'wpelevator/update-pilot' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'ed520e8a22acd224003ad337764756a06128a662',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(
                0 => '0.1.x-dev',
            ),
            'dev_requirement' => false,
        ),
    ),
);
