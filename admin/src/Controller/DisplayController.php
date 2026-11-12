<?php
namespace Cybersalt\Component\TalkbackMigrate\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

class DisplayController extends BaseController
{
    protected $default_view = 'migrate';

    /**
     * Actions that change data. These only run from a POST form carrying a valid form token.
     */
    protected $writeActions = ['migrate', 'undo', 'save_overrides', 'save_review'];

    public function display($cachable = false, $urlparams = [])
    {
        $action = $this->input->getCmd('action', '');

        if (in_array($action, $this->writeActions, true)) {
            if ($this->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
                $this->setRedirect(
                    Route::_('index.php?option=com_talkbackmigrate', false),
                    Text::_('JINVALID_TOKEN_NOTICE'),
                    'error'
                );

                return $this;
            }
        }

        return parent::display($cachable, $urlparams);
    }
}
