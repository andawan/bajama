<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

try {
	\BAJAMA\Core\Audit::log(
		db(),
		'LOGOUT',
		'auth',
		'user',
		\BAJAMA\Core\Auth::userId()
	);
} catch (\Throwable $auditError) {
	error_log(
		'BAJAMA logout audit error: '
		. $auditError->getMessage()
	);
}

\BAJAMA\Core\Auth::logout();

header('Location: login.php');
exit;
