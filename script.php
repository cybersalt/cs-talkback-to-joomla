<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Installer\InstallerAdapter;

class Com_TalkbackmigrateInstallerScript
{
    protected string $minimumPhp = '8.1';
    protected string $minimumJoomla = '5.0';

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_TALKBACKMIGRATE_ERROR_PHP_VERSION', $this->minimumPhp, PHP_VERSION),
                'error'
            );
            return false;
        }

        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_TALKBACKMIGRATE_ERROR_JOOMLA_VERSION', $this->minimumJoomla, JVERSION),
                'error'
            );
            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): void
    {
        $this->clearAutoloadCache();
        $this->showPostInstallMessage($type);
    }

    protected function clearAutoloadCache(): void
    {
        $cacheFile = JPATH_ADMINISTRATOR . '/cache/autoload_psr4.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    protected function showPostInstallMessage(string $type): void
    {
        $messageKey = $type === 'update'
            ? 'COM_TALKBACKMIGRATE_POSTINSTALL_UPDATED'
            : 'COM_TALKBACKMIGRATE_POSTINSTALL_INSTALLED';
        $url = 'index.php?option=com_talkbackmigrate';

        echo '<div class="card mb-3" style="margin: 20px 0;">'
            . '<div class="card-body">'
            . '<h3 class="card-title">' . Text::_('COM_TALKBACKMIGRATE') . '</h3>'
            . '<p class="card-text">' . Text::_($messageKey) . '</p>'
            . '<a href="' . $url . '" class="btn btn-primary text-white">'
            . Text::_('COM_TALKBACKMIGRATE_POSTINSTALL_OPEN')
            . '</a></div></div>';
    }
}
