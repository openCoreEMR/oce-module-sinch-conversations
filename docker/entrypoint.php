#!/usr/bin/env php
<?php
/**
 * Simple OpenEMR Development Entrypoint
 *
 * Just runs the installer (once) and starts Apache - no complex setup
 * Avoids the slow chown operations in the standard openemr.sh entrypoint
 */

echo "==> Simple OpenEMR Development Entrypoint\n";

// Generate SSL certificates if they don't exist
if (!file_exists('/etc/ssl/certs/webserver.cert.pem')) {
    echo "==> Generating SSL certificates...\n";
    passthru('sh /var/www/localhost/htdocs/ssl.sh');
}

// Check if OpenEMR is already configured
$sqlconfPath = '/var/www/localhost/htdocs/openemr/sites/default/sqlconf.php';
$isConfigured = false;

if (file_exists($sqlconfPath)) {
    require_once $sqlconfPath;
    $isConfigured = isset($config) && $config == 1;
}

// Also check if the database already exists (partial installation)
$mysqlHost = getenv('MYSQL_HOST') ?: 'localhost';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$mysqlRoot = getenv('MYSQL_ROOT_USER') ?: 'root';
$mysqlRootPass = getenv('MYSQL_ROOT_PASS') ?: '';
$dbName = getenv('MYSQL_DATABASE') ?: 'openemr';

$databaseExists = false;
try {
    $pdo = new PDO(
        "mysql:host={$mysqlHost};port={$mysqlPort}",
        $mysqlRoot,
        $mysqlRootPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $result = $pdo->query("SHOW DATABASES LIKE '{$dbName}'");
    $databaseExists = $result->rowCount() > 0;
    if ($databaseExists) {
        echo "==> Warning: Database '{$dbName}' already exists from partial installation\n";
    }
} catch (Exception $e) {
    echo "==> Warning: Could not check if database exists: " . $e->getMessage() . "\n";
}

if (!$isConfigured && !$databaseExists) {
    echo "==> OpenEMR not configured, running installer...\n";

    // Set up install settings from environment variables
    $installSettings = [
        'iuser'      => getenv('OE_USER') ?: 'admin',
        'iuname'     => getenv('OE_USER') ?: 'Administrator',
        'iuserpass'  => getenv('OE_PASS') ?: 'pass',
        'igroup'     => 'Default',
        'server'     => getenv('MYSQL_HOST') ?: 'localhost',
        'loginhost'  => '%',  // Allow connections from any host in Docker
        'port'       => getenv('MYSQL_PORT') ?: '3306',
        'root'       => getenv('MYSQL_ROOT_USER') ?: 'root',
        'rootpass'   => getenv('MYSQL_ROOT_PASS') ?: '',
        'login'      => getenv('MYSQL_USER') ?: 'openemr',
        'pass'       => getenv('MYSQL_PASS') ?: 'openemr',
        'dbname'     => getenv('MYSQL_DATABASE') ?: 'openemr',
        'collate'    => 'utf8mb4_general_ci',
        'site'       => 'default',
    ];

    echo "==> Database settings: server={$installSettings['server']}, port={$installSettings['port']}, root={$installSettings['root']}, rootpass=" . (empty($installSettings['rootpass']) ? '(empty)' : '***') . "\n";

    // Test MySQL connection
    echo "==> Testing MySQL connection...\n";
    try {
        $testPdo = new PDO(
            "mysql:host={$installSettings['server']};port={$installSettings['port']}",
            $installSettings['root'],
            $installSettings['rootpass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "==> MySQL connection successful!\n";
    } catch (Exception $e) {
        echo "ERROR: MySQL connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Load OpenEMR installer class directly (not InstallerAuto.php which hard-codes localhost)
    require_once '/var/www/localhost/htdocs/openemr/vendor/autoload.php';
    require_once '/var/www/localhost/htdocs/openemr/library/classes/Installer.class.php';

    // Run the installer
    $installer = new Installer($installSettings);
    if (!$installer->quick_install()) {
        echo "ERROR: " . $installer->error_message . "\n";
        exit(1);
    }

    echo "==> OpenEMR installation complete!\n";

    // Apply global settings
    try {
        $pdo = new PDO(
            "mysql:host={$installSettings['server']};port={$installSettings['port']};dbname={$installSettings['dbname']}",
            $installSettings['login'],
            $installSettings['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        if (getenv('OPENEMR_SETTING_rest_api') === '1') {
            $pdo->exec("UPDATE globals SET gl_value = 1 WHERE gl_name = 'rest_api'");
            echo "==> Enabled REST API\n";
        }

        if (getenv('OPENEMR_SETTING_rest_fhir_api') === '1') {
            $pdo->exec("UPDATE globals SET gl_value = 1 WHERE gl_name = 'rest_fhir_api'");
            echo "==> Enabled FHIR API\n";
        }
    } catch (Exception $e) {
        echo "Warning: Could not set global settings: " . $e->getMessage() . "\n";
    }
} else {
    if ($isConfigured) {
        echo "==> OpenEMR already configured, skipping installation\n";
    } else {
        echo "==> ERROR: Database exists but OpenEMR not configured - partial installation detected\n";
        echo "==> Please run: docker compose down -v && docker compose up -d\n";
        exit(1);
    }
}

// Set proper permissions on sites directory for Apache
echo "==> Setting permissions on sites directory...\n";
passthru('chown -R apache:apache /var/www/localhost/htdocs/openemr/sites');
passthru('chmod -R 755 /var/www/localhost/htdocs/openemr/sites');

echo "==> Starting Apache...\n";
// Apache will be started by the shell wrapper via exec
