<?php
/** Admin sign-out (POST only, with CSRF check). */

require __DIR__ . '/_inc.php';
bv_check_csrf();
unset( $_SESSION['admin_id'], $_SESSION['admin_seen'], $_SESSION['csrf'] );
session_regenerate_id( true );
bv_redirect( 'login.php' );
