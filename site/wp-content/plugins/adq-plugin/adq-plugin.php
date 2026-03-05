<?php
/**
 * Plugin Name: AutoDoor Experts — ADQ Portal MVP
 * Description: Portal MVP core plugin (CPTs, state machines, audit log, seeding) per Blueprint v2.0.
 * Version: 0.2.0
 * Author: AutoDoor Experts
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/includes/class-adq-audit.php';
require_once __DIR__ . '/includes/class-adq-immutability.php';
require_once __DIR__ . '/includes/class-adq-state-machines.php';
require_once __DIR__ . '/includes/class-adq-project-seeder.php';
require_once __DIR__ . '/includes/class-adq-post-types.php';
require_once __DIR__ . '/includes/class-adq-rest.php';

register_activation_hook( __FILE__, static function (): void {
    ADQ_Audit::install();
    ADQ_Post_Types::register_roles_and_caps();
});

add_action( 'plugins_loaded', static function (): void {
    ADQ_Post_Types::init();
    ADQ_Immutability::init();
    ADQ_State_Machines::init();
    ADQ_Rest::init();
});
