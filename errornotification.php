<?php
/*
 * @license GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
**/
defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Table\Table;
use Joomla\Database\ParameterType;
use PHPMailer\PHPMailer\Exception as phpMailerException;

class PlgSystemErrornotification extends CMSPlugin
{
	/**
	 * Application object
	 *
	 * @var    \Joomla\CMS\Application\CMSApplication
	 * @since  4.0.0
	 */
	protected $app;

	/**
	 * Database driver
	 *
	 * @var    \Joomla\Database\DatabaseInterface
	 * @since  4.0.0
	 */
	protected $db;

	/**
	 * Load plugin language files automatically
	 *
	 * @var    boolean
	 * @since  3.6.3
	 */
	protected $autoloadLanguage = true;

	/**
	 * The update check and notification email code is triggered after the page has fully rendered.
	 *
	 * @return  void
	 *
	 * @since   3.5
	 */
	public function onBeforeRespond()
	{

		$doc = $this->app->getDocument();

		if (!$doc instanceof \Joomla\CMS\Document\ErrorDocument)
		{
			return;
		}

		if ($this->app->isClient('administrator') && $this->params->get('ignore_administrator'))
		{
			return;
		}

		if ($this->ignoreError($doc->error->getCode()))
		{
			return;
		}

		// Let's find out the email addresses to notify
		$superUsers = $this->getSuperUsersWhoToBeNotified();

		if (empty($superUsers))
		{
			return;
		}

		$substitutions = [
			'sitename' => $this->app->get('sitename'),
			'url'      => Uri::getInstance()->toString(),
			'code'     => $doc->error->getCode(),
			'message'  => htmlspecialchars($doc->error->getMessage(), ENT_QUOTES, 'UTF-8'),
			'line'     => $doc->error->getLine(),
			'file'     => $doc->error->getFile(),
			'trace'    => $doc->error->getTraceAsString()
		];

		$language = $this->getLanguage();

		// Send the emails to the Super Users
		foreach ($superUsers as $superUser)
		{
			try
			{
				$mailer = new MailTemplate('plg_system_errornotification.mail', $language->getTag());
				$mailer->addRecipient($superUser->email);
				$mailer->addTemplateData($substitutions);
				$mailer->send();
			}
			catch (MailDisabledException | phpMailerException $exception)
			{
				try
				{
					Log::add(Text::_($exception->getMessage()), Log::WARNING, 'jerror');
				}
				catch (\RuntimeException $exception)
				{
					$this->app->enqueueMessage(Text::_($exception->errorMessage()), 'warning');
				}
			}
		}
	}

	private function ignoreError(int $errorCode): bool
	{
		$ignore_error = $this->params->get('ignore_error');

		if (empty($ignore_error)) return false;

		$ignoreErrorCodes = explode(',', $ignore_error);

		foreach ($ignoreErrorCodes as $ignoreErrorCode)
		{
			if ((int) $ignoreErrorCode === $errorCode)
			{
				return true;
			}
		}

		return false;
	}

	private function getSuperUsersWhoToBeNotified()
	{
		$db    = $this->db;
		$email = $this->params->get('email');

		// Convert the email list to an array
		if (!empty($email))
		{
			$temp = explode(',', $email);

			foreach ($temp as $entry)
			{
				$emails[] = trim($entry);
			}

			$emails = array_unique($emails);
		}

		// Get a list of groups which have Super User privileges
		$ret = [];

		try
		{
			$rootId    = Table::getInstance('Asset')->getRootId();
			$rules     = Access::getAssetRules($rootId)->getData();
			$rawGroups = $rules['core.admin']->getData();
			$groups    = [];

			if (empty($rawGroups))
			{
				return $ret;
			}

			foreach ($rawGroups as $g => $enabled)
			{
				if ($enabled)
				{
					$groups[] = $g;
				}
			}

			if (empty($groups))
			{
				return $ret;
			}
		}
		catch (Exception $exc)
		{
			return $ret;
		}

		// Get the user IDs of users belonging to the SA groups
		try
		{
			$query = $db->getQuery(true)
				->select($db->quoteName('user_id'))
				->from($db->quoteName('#__user_usergroup_map'))
				->whereIn($db->quoteName('group_id'), $groups);

			$db->setQuery($query);
			$userIDs = $db->loadColumn(0);

			if (empty($userIDs))
			{
				return $ret;
			}
		}
		catch (Exception $exc)
		{
			return $ret;
		}

		// Get the user information for the Super Administrator users
		try
		{
			$query = $db->getQuery(true)
				->select($db->quoteName(['id', 'username', 'email']))
				->from($db->quoteName('#__users'))
				->whereIn($db->quoteName('id'), $userIDs)
				->where($db->quoteName('block') . ' = 0')
				->where($db->quoteName('sendEmail') . ' = 1');

			if (!empty($emails))
			{
				$lowerCaseEmails = array_map('strtolower', $emails);
				$query->whereIn('LOWER(' . $db->quoteName('email') . ')', $lowerCaseEmails, ParameterType::STRING);
			}

			$db->setQuery($query);
			$ret = $db->loadObjectList();
		}
		catch (Exception $exc)
		{
			return $ret;
		}

		$user = $this->app->getIdentity();
		if ($user->id && $user->authorise('core.admin') && !empty($ret))
		{
			$ret = array_filter($ret, function ($superUser) use ($user) {
				return $superUser->id !== $user->id;
			});
		}

		return $ret;
	}

	protected function getLanguage(): \Joomla\CMS\Language\Language
	{
		/*
		 * Load the appropriate language. We try to load English (UK), the current user's language and the forced
		 * language preference, in this order. This ensures that we'll never end up with untranslated strings in the
		 * error email which would make Joomla! seem bad. So, please, if you don't fully understand what the
		 * following code does DO NOT TOUCH IT. It makes the difference between a hobbyist CMS and a professional
		 * solution!
		 */
		$language = $this->app->getLanguage();
		$language->load('plg_system_errornotification', JPATH_ADMINISTRATOR, 'en-GB', true, true);
		$language->load('plg_system_errornotification', JPATH_ADMINISTRATOR, null, true, false);

		// Then try loading the preferred (forced) language
		$forcedLanguage = $this->params->get('language_override', '');

		if (!empty($forcedLanguage))
		{
			$language->load('plg_system_errornotification', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
		}

		return $language;
	}

}
