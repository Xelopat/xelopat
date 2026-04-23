<?php
declare(strict_types=1);

const SITE_NAME = 'Xelopat';

const AUTH_SESSION_KEY = 'site_auth_user';
const CSRF_SESSION_KEY = 'site_csrf_token';

const USERS_FILE = __DIR__ . '/../.private/users.json';

const USERNAME_RE = '/^[a-zA-Z0-9._-]{3,32}$/';
const PASSWORD_MIN_LEN = 8;

// Публичные урлы (без логина)
const PUBLIC_PATHS = [
    '/auth/login.php',
    '/auth/register.php',
    '/auth/logout.php',
];
