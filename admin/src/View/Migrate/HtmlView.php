<?php
namespace Cybersalt\Component\TalkbackMigrate\Administrator\View\Migrate;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $output = [];
    protected $action = '';
    protected $unmatchedData = [];
    protected $reviewData = [];

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $this->action = $app->getInput()->getCmd('action', '');
        $model = $this->getModel();

        // Handle saving overrides (POST) - from either unmatched or review form
        if (($this->action === 'save_overrides' || $this->action === 'save_review') && $app->getInput()->getMethod() === 'POST') {
            $hrefs = $app->getInput()->post->get('href', [], 'array');
            $articleIds = $app->getInput()->post->get('article_id', [], 'array');
            $returnTo = $this->action === 'save_review' ? 'review' : 'unmatched';

            // Merge with existing overrides so we don't lose previously saved ones
            $overrides = $model->getOverrides();
            foreach ($hrefs as $idx => $href) {
                $articleId = isset($articleIds[$idx]) ? (int) $articleIds[$idx] : 0;
                if ($articleId > 0) {
                    $overrides[$href] = $articleId;
                } else {
                    unset($overrides[$href]);
                }
            }

            $model->saveOverrides($overrides);
            $app->enqueueMessage(Text::sprintf('COM_TALKBACKMIGRATE_OVERRIDES_SAVED_COUNT', count($overrides)), 'success');
            $app->redirect('index.php?option=com_talkbackmigrate&action=' . $returnTo);
            return;
        }

        if ($this->action) {
            switch ($this->action) {
                case 'check':
                    $result = $model->buildMapping();
                    $this->output = $result['log'];
                    break;
                case 'report':
                    $this->output = $model->report();
                    break;
                case 'migrate':
                    $this->output = $model->migrate();
                    break;
                case 'undo':
                    $this->output = $model->undo();
                    break;
                case 'unmatched':
                    $this->unmatchedData = $model->getUnmatched();
                    break;
                case 'review':
                    $this->reviewData = $model->getAllMappings();
                    break;
                case 'diagnose':
                    $href = $app->getInput()->getString('href', '');
                    $this->output = $model->diagnose($href);
                    break;
                case 'search':
                    $searchQuery = $app->getInput()->getString('q', '');
                    if ($searchQuery) {
                        $this->output = $model->search($searchQuery);
                    }
                    break;
            }
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_TALKBACKMIGRATE'), 'database');
    }
}
