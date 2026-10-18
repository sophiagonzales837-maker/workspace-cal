<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

$criteria_module_dir = __DIR__;
require '../shared/criteria_admin.php';
