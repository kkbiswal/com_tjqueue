<?php
/**
 * @package    Techjoomla.CLI
 * @author     Techjoomla <extensions@techjoomla.com>
 * @copyright  Copyright (c) 2009-2019 TechJoomla. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

use TJQueue\Admin\TJQueueConsume;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Application\CliApplication;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Factory;

define('_JEXEC', 1);
define('JPATH_BASE', dirname(__DIR__));

// Load system defines
if (file_exists(JPATH_BASE . '/defines.php'))
{
	require_once JPATH_BASE . '/defines.php';
}

if (!defined('_JDEFINES'))
{
	require_once JPATH_BASE . '/includes/defines.php';
}


// Check for presence of vendor dependencies not included in the git repository
if (!file_exists(JPATH_LIBRARIES . '/vendor/autoload.php') || !is_dir(JPATH_ROOT . '/media/vendor')) {
    echo file_get_contents(JPATH_ROOT . '/templates/system/build_incomplete.html');
    exit;
}

require_once JPATH_BASE . '/includes/framework.php';

// Boot the DI container
$container = \Joomla\CMS\Factory::getContainer();

// Alias the session service keys to the web session service as that is the primary session backend for this application
// In addition to aliasing "common" service keys, we also create aliases for the PHP classes to ensure autowiring objects is supported.  This includes aliases for aliased class names, and the keys for aliased class names should be considered deprecated to be removed when the class name alias is removed as well.
$container->alias('session.web', 'session.web.site')
    ->alias('session', 'session.web.site')
    ->alias('JSession', 'session.web.site')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')
    ->alias(\Joomla\Session\Session::class, 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');

// Instantiate the application.
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);

// Set the application as global app
\Joomla\CMS\Factory::$application = $app;

$app->createExtensionNamespaceMap();

// Get the framework.
require_once JPATH_LIBRARIES . '/bootstrap.php';

// Bootstrap the CMS libraries.
// require_once JPATH_LIBRARIES . '/cms.php';

// Load the configuration
require_once JPATH_CONFIGURATION . '/configuration.php';

jimport('tjqueueconsume', JPATH_SITE . '/administrator/components/com_tjqueue/libraries');

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';


//~ $app = Factory::getContainer()->get(\Joomla\CMS\Application\SiteApplication::class);
//~ $app->initialise();

/**
 * TjQueue
 *
 * @package     Techjoomla.CLI
 * @subpackage  Tjqueue
 * @since       0.0.1
 */
class TJQueue extends JApplicationCli
{
	private $message = null;

	private $options;

	/**
	 * Class  constructor.
	 *
	 * @since   0.0.1
	 */
	public function __construct()
	{
		parent::__construct();

		$shortopts  = "";

		// Support topic with option -t value
		$shortopts .= "t:";

		// Support limit with option -n value
		$shortopts .= "n:";

		// Support set timeout with option -s value
		$shortopts .= "s:";

		$longopts  = array(
			"topic:",  // Long option to read topic  --topic="value"
			"n:",      // Long option to read count  --n="value"
			"timeout:" // Long option to read timeout in milliseconds  --timeout="value"
		);

		$argv                   = getopt($shortopts, $longopts);

		$this->options          = new stdClass;
		$this->options->topic   = array_key_exists('t', $argv) ? $argv['t'] : (array_key_exists('topic', $argv) ?  $argv['topic'] : null);
		$this->options->limit   = array_key_exists('n', $argv) ? $argv['n'] : 50;
		$this->options->timeout = array_key_exists('s', $argv) ? $argv['s'] : (array_key_exists('timeout', $argv) ?  $argv['timeout'] : 2000);
	}

	/**
	 * Load an object or array into the application configuration object.
	 *
	 * @param   mixed  $data  Either an array or object to be loaded into the configuration object.
	 *
	 * @return  CliApplication  Instance of $this to allow chaining.
	 *
	 * @since   1.7.0
	 */
	public function loadConfiguration($data)
	{
		// Load the data into the configuration object.
		if (is_array($data))
		{
			$this->config->loadArray($data);
		}
		elseif (is_object($data))
		{
			$this->config->loadObject($data);
		}

		return $this;
	}

	/**
	 * Entry point for the script
	 *
	 * @return  void
	 *
	 * @since   3.8.6
	 */
	// public function doExecute()
	// {

	// }
	public function getName()
	{

	}

	/**
	 * Method to execute script
	 *
	 * @return  void.
	 *
	 * @since 1.0
	 */
	public function doExecute()
	{
		PluginHelper::importPlugin('system');
		Joomla\CMS\Factory::getApplication()->triggerEvent('onAfterInitialise');

		//PluginHelper::importPlugin('system');

		$log['success'] = 1;
		$log['message'] = 'Started: Running queue cron';
		self::writeLog($log);

		// If topic name not set in first argument
		if (!$this->options->topic)
		{
			$log['success'] = 0;
			$log['message'] = 'Error-Topic name not found to process.';

			self::writeLog($log);
			exit;
		}

		$TJQueueConsume = new TJQueueConsume($this->options->topic, $this->options->timeout);

		$i = 0;

		while ($i++ < $this->options->limit)
		{
			$this->message = $TJQueueConsume->receive();

			if ($this->message == null)
			{
				$log['success'] = 1;
				$log['message'] = 'Done: No message available in queue to process';
				self::writeLog($log);
				exit;
			}

			$client  = $this->message->getProperty('client');

			if (empty($client))
			{
				$log['success'] = 0;
				$log['message'] = 'Error- Invalid client- client value should not be blank';
				self::writeLog($log);
				continue;
			}

			$res     = explode('.', $client);

			// Get plugin name to call
			$plugin  = $res[0];

			// Get class name
			$class   = $res[1];

			$filePath = JPATH_SITE . '/plugins/tjqueue/' . $plugin . '/consumers/' . $class . '.php';

			if (!file_exists($filePath))
			{
				$log['success'] = 0;
				$log['message'] = "Error 404- Consumer class file doesn't exist:" . $filePath;
				self::writeLog($log);
				continue;
			}

			$mailcatcherFilePath = JPATH_SITE . '/plugins/system/mailcatcher/mailer.php';

			if (!file_exists($mailcatcherFilePath))
			{
				$log['success'] = 0;
				$log['message'] = "Error 404- mailcatcher class file doesn't exist:" . $mailcatcherFilePath;
				self::writeLog($log);
				continue;
			}
			

			try
			{
				require_once $mailcatcherFilePath;
				require_once $filePath;

				// Prepare class Name
				$className = 'Tjqueue' . ucfirst($plugin) . ucfirst($class);

				if (!class_exists($className))
				{
					$log['success'] = 0;
					$log['message'] = $className . ' class not found';
					self::writeLog($log);

					continue;
				}

				$obj    = new $className;
				$result = $obj->consume($this->message);
				$TJQueueConsume->acknowledge($result);

				if ($result)
				{
					$log['success'] = 1;
					$log['message'] = 'Message consumed successfully';
				}
				else
				{
					$log['success'] = 0;
					$log['message'] = 'Message consumption failed';
				}

				self::writeLog($log);
			}
			catch (Exception $e)
			{
				$log['success'] = 0;
				$log['message'] = $e->getMessage();
				self::writeLog($log);
			}
		}
	}

	/**
	 * Plugin method with the same name as the event will be called automatically.
	 *
	 * @param   array  $data  Result data
	 *
	 * @return  array.
	 *
	 * @since 0.0.1
	 */
	public function writeLog($data)
	{
		$client = $this->message ? $this->message->getProperty('client') : null;
		$messageId = $this->message ? $this->message->getMessageId() : null;

		$this->out($data['message']);

		// Add to log
		$logFields = [
			"messageId" => $messageId,
			"message" => $data['message'],
			"topic" => $this->options->topic,
			"client" => $client
		];

		// Convert logFields to string implode by pipe(|)
		$logMessage = implode(' | ', array_map(
				function ($v, $k)
				{
					if (is_array($v))
					{
						return $k . '[]: ' . implode('&' . $k . '[]: ', $v);
					}
					else
					{
						return $k . ': ' . $v;
					}
				},
				$logFields,
				array_keys($logFields)
			)
		);

		Log::addLogger(
			array (
			'text_file'         => 'tjqueue_log_' . date("j.n.Y") . '.log.php',
			'text_entry_format' => '{DATETIME} | {PRIORITY} | {MESSAGE}'
			),
			Log::ALL,
			array($category = 'tjlogs')
		);

		$priority = $data['success'] == 1 ? Log::INFO : Log::ERROR;
		Log::add($logMessage, $priority, $category = 'tjlogs');
	}
}

JApplicationCli::getInstance('TJQueue')->loadConfiguration($argv);
JApplicationCli::getInstance('TJQueue')->execute();
