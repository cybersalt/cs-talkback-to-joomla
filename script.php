<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Installer\InstallerAdapter;

class Com_TalkbackmigrateInstallerScript
{
    public function postflight(string $type, InstallerAdapter $adapter): void
    {
        $this->showPostInstallMessage($type);
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
