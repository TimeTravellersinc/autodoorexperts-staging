<?php
chdir('/var/www/html');
require_once 'wp-load.php';
require_once 'wp-content/themes/ado-modern/inc/ado-portal/ado-quote-integration.php';

$payload = [
    'result' => [
        'doors' => [
            [
                'door_id' => 'door_test',
                'door_number' => '1',
                'items' => [
                    [
                        'raw' => 'UNMATCHABLE MODEL 9999',
                        'catalog' => '',
                        'desc' => 'Unmatched description',
                        'qty' => 1,
                    ],
                ],
            ],
        ],
    ],
];

$integration = ADO_Quote_Integration::instance();
$result = $integration->create_quote_from_payload(1, $payload, [
    'debug' => true,
    'scope_path' => '/var/www/html/wp-content/uploads/failure-scope.json',
]);

echo wp_json_encode($result, JSON_PRETTY_PRINT);
