<?php
declare(strict_types=1);
require_once __DIR__ . '/role-page.php';
require_page_role('voter');
require_once __DIR__ . '/sidebar-bootstrap.php';
$sidebarBootstrap = sidebar_bootstrap_json();
