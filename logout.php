<?php
/**
 * Sign out. POST-only with a CSRF token: a plain GET link would let any other site
 * log a user out by embedding <img src=".../logout">.
 */
require __DIR__ . '/inc/bootstrap.php';

if (!sleek_is_post()) sleek_redirect(current_user() ? 'dashboard' : 'login');

csrf_verify();
sleek_logout();
sleek_redirect('login');
