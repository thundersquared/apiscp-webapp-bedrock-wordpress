<?php

declare(strict_types=1);

namespace sqrd\ApisCP\Webapps\Bedrock;

use Module\Support\Webapps\Traits\PublicRelocatable;
use sqrd\ApisCP\Webapps\Bedrock\Helpers\File;

class Bedrock_Module extends \Wordpress_Module
{
    use PublicRelocatable
    {
        getAppRoot as getAppRootReal;
    }

    public const APP_NAME = 'Bedrock';
    public const PACKAGIST_NAME = 'roots/bedrock';
    public const VERSION_CHECK_URL = 'https://repo.packagist.org/p2/roots/bedrock.json';

    protected function getAppRoot(string $hostname, string $path = ''): ?string
    {
        if (file_exists($tmp = $this->getDocumentRoot($hostname, $path) . '/wp-config.php')) {
            return $tmp;
        }

        return $this->getAppRootReal($hostname, $path);
    }

    protected function getAppRootPath(string $hostname, string $path = ''): ?string
    {
        if ($hostname[0] === '/') {
            if (!($path = realpath($this->domain_fs_path($hostname)))) {
                return null;
            }
            $approot = \dirname($path);
        } else {
            $approot = $this->getAppRoot($hostname, $path);
            if (!$approot) {
                return null;
            }
            $approot = $this->domain_fs_path($approot);
        }

        return $approot;
    }

    /**
     * Install WordPress
     *
     * @param string $hostname domain or subdomain to install WordPress
     * @param string $path optional path under hostname
     * @param array $opts additional install options
     * @return bool
     */
    public function install(string $hostname, string $path = '', array $opts = array()): bool
    {
        if (!$this->mysql_enabled()) {
            return error(
                '%(what)s must be enabled to install %(app)s',
                ['what' => 'MySQL', 'app' => static::APP_NAME]
            );
        }

        if (!$this->php_composer_exists()) {
            return error('composer missing! contact sysadmin');
        }

        // Same situation as with Ghost. We can't install under a path for fear of
        // leaking information
        if ($path) {
            return error('Composer projects may only be installed directly on a subdomain or domain without a child path, e.g. https://domain.com but not https://domain.com/laravel');
        }

        if (!($docroot = $this->getDocumentRoot($hostname, $path))) {
            return error("failed to normalize path for `%s'", $hostname);
        }

        if (!$this->parseInstallOptions($opts, $hostname, $path)) {
            return false;
        }

        $ret = $this->execComposer(
            $docroot,
            'create-project --prefer-dist %(package)s %(docroot)s %(version)s',
            [
                'package' => static::PACKAGIST_NAME,
                'docroot' => $docroot,
                'version' => $opts['version'],
            ]
        );

        if (!$ret['success']) {
            $this->file_delete($docroot, true);

            return error(
                'failed to download roots/bedrock package: %s %s',
                $ret['stderr'],
                $ret['stdout']
            );
        }

        if (null === ($docroot = $this->remapPublic($hostname, $path, 'web/'))) {
            $this->file_delete($this->getDocumentRoot($hostname, $path), true);

            return error(
                "Failed to remap Bedrock to web/, manually remap from `%s' - Bedrock setup is incomplete!",
                $docroot
            );
        }

        $dbCred = DatabaseGenerator::mysql($this->getAuthContext(), $hostname);
        if (!$dbCred->create()) {
            return false;
        }

        if (!$this->generateNewConfiguration($hostname, $docroot, $dbCred)) {
            info('removing temporary files');
            if (!array_get($opts, 'hold')) {
                $this->file_delete($docroot, true);
                $dbCred->rollback();
            }
            return false;
        }

        if (!isset($opts['title'])) {
            $opts['title'] = 'A Random Blog for a Random Reason';
        }

        if (!isset($opts['password'])) {
            $opts['password'] = \Opcenter\Auth\Password::generate();
            info("autogenerated password `%s'", $opts['password']);
        }

        info("setting admin user to `%s'", $this->username);
        // fix situations when installed on global subdomain
        $fqdn = $this->web_normalize_hostname($hostname);
        $opts['url'] = rtrim($fqdn . '/' . $path, '/');
        $args = array(
            'email'    => $opts['email'],
            'mode'     => 'install',
            'url'      => $opts['url'],
            'title'    => $opts['title'],
            'user'     => $opts['user'],
            'password' => $opts['password'],
            'proto'    => !empty($opts['ssl']) ? 'https://' : 'http://',
            'mysqli81' => 'function_exists("mysqli_report") && mysqli_report(0);'
        );
        $ret = $this->execCommand($docroot, 'core %(mode)s --admin_email=%(email)s --skip-email ' .
            '--url=%(proto)s%(url)s --title=%(title)s --admin_user=%(user)s --exec=%(mysqli81)s ' .
            '--admin_password=%(password)s', $args);
        if (!$ret['success']) {
            if (!array_get($opts, 'hold')) {
                $dbCred->rollback();
            }
            return error('failed to create database structure: %s', coalesce($ret['stderr'], $ret['stdout']));
        }

        $this->initializeMeta($docroot, $opts);
        if (!file_exists($this->domain_fs_path() . "/${docroot}/.htaccess")) {
            $this->file_touch("${docroot}/.htaccess");
        }

        $wpcli = Wpcli::instantiateContexted($this->getAuthContext());
        $wpcli->setConfiguration(['apache_modules' => ['mod_rewrite']]);

        $ret = $this->execCommand($docroot, "rewrite structure --hard '/%%postname%%/'");
        if (!$ret['success']) {
            return error('failed to set rewrite structure, error: %s', coalesce($ret['stderr'], $ret['stdout']));
        }

        return false;
    }

    /**
     * Get available versions
     *
     * Used to determine whether an app is eligible for updates
     *
     * @return array|string[]
     */
    public function get_versions(): array
    {
        $key = 'bedrock.versions';

        // Attempt to retrieve cached versions
        $cache = \Cache_Super_Global::spawn();
        if (false !== ($ver = $cache->get($key))) {
            return $ver;
        }

        // Retrieve package information for version check
        $url = self::VERSION_CHECK_URL;
        $context = stream_context_create(['http' => ['timeout' => 5]]);
        $contents = file_get_contents($url, false, $context);
        if (!$contents) {
            return array();
        }
        $versions = json_decode($contents, true);

        // Cleanup before storage
        $versions = array_pop($versions['packages']);
        $versions = array_reverse(array_column($versions, 'version'));
        $cache->set($key, $versions, 43200);

        return $versions;
    }

    /**
     * Location is a valid Bedrock install
     *
     * @param string $hostname or $docroot
     * @param string $path
     * @return bool
     */
    public function valid(string $hostname, string $path = ''): bool
    {
        $approot = $this->getAppRootPath($hostname, $path);

        return false !== $approot &&
            file_exists($approot . '/config/application.php') &&
            is_dir($approot . '/config/environments') &&
            is_dir($approot . '/web/app/plugins');
    }

    public function get_version(string $hostname, string $path = ''): ?string
    {
        if (!$this->valid($hostname, $path)) {
            return null;
        }

        $approot = $this->getAppRootPath($hostname, $path);

        // is composer.json file missing?
        if (!file_exists($approot . '/composer.json')) {
            return null;
        }

        return File::read_json($approot . '/composer.json', 'require.roots/wordpress');
    }

    public function get_environment(string $hostname, string $path = ''): ?string
    {
        $approot = $this->getAppRootPath($hostname, $path);

        // is .env file missing?
        if (!file_exists($approot . '/.env')) {
            return null;
        }

        // Create instance with no adapters besides ArrayAdapter,
        // we just need to peek at it without loading it actually
        $dotenv = Dotenv::create($approot, null, new DotenvFactory([new ArrayAdapter()]));
        $variables = $dotenv->load();

        // Return current WP_ENV value
        return $variables['WP_ENV'];
    }

    public function set_environment(string $hostname, string $path = '', string $environment = 'development˙'): ?bool
    {
        // App root is needed to use internal calls
        $approot = $this->getAppRoot($hostname, $path);

        // Replace .env value
        $ret = $this->pman_run('sed -i \'s/^WP_ENV=.*$/WP_ENV=%(environment)s/g\' %(approot)s', [
            'environment' => $environment,
            'approot' => $approot . '/.env',
        ]);

        return $ret['success'] ? true : error('Failed to update env: %s', $ret['stderr']);
    }

    public function get_environments(string $hostname, string $path = ''): ?array
    {
        // App root is needed to use internal calls
        $approot = $this->getAppRoot($hostname, $path);
        // App root path is needed for PHP direct checks
        $approotpath = $this->getAppRootPath($hostname, $path);

        // is config/environments/ dir missing?
        if (!is_dir($approotpath . '/config/environments/')) {
            return null;
        }

        // Get current active environment
        $active_environment = $this->get_environment($hostname, $path);

        // Scan config/environments/ to extract available environments
        $environments = $this->file_get_directory_contents($approot . '/config/environments/');

        // Collect and clean output checking whether environment matches active one
        return array_map(function ($environment) use ($active_environment) {
            $name = pathinfo($environment['filename'], PATHINFO_FILENAME);

            return [
                'name' => $name,
                'status' => $name === $active_environment,
            ];
        }, $environments);
    }
}
