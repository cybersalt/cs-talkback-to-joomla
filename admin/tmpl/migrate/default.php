<?php
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;

$baseUrl = Route::_('index.php?option=com_talkbackmigrate');
$db = Factory::getContainer()->get('DatabaseDriver');

// Quick stats
$totalOld = 0;
$totalImported = 0;
try {
    $db->setQuery("SELECT COUNT(*) FROM bt_data WHERE moderate = 0");
    $totalOld = (int) $db->loadResult();
} catch (\Exception $e) {
    // bt_data table might not exist
}
try {
    $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source = 'talkback'");
    $totalImported = (int) $db->loadResult();
} catch (\Exception $e) {
    // table might not exist
}
?>

<div class="row">
    <div class="col-lg-12">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="card-title"><?php echo Text::_('COM_TALKBACKMIGRATE'); ?></h2>
                <p class="card-text"><?php echo Text::_('COM_TALKBACKMIGRATE_INTRO'); ?></p>

                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <div class="badge bg-info text-dark fs-6 p-2">
                        <?php echo Text::sprintf('COM_TALKBACKMIGRATE_STAT_OLD', $totalOld); ?>
                    </div>
                    <div class="badge bg-<?php echo $totalImported > 0 ? 'success' : 'secondary'; ?> fs-6 p-2">
                        <?php echo Text::sprintf('COM_TALKBACKMIGRATE_STAT_IMPORTED', $totalImported); ?>
                    </div>
                </div>

                <form class="mb-3 d-flex gap-2" method="get" action="<?php echo Route::_('index.php'); ?>" style="max-width:500px;">
                    <input type="hidden" name="option" value="com_talkbackmigrate">
                    <input type="hidden" name="action" value="search">
                    <input type="text" name="q" class="form-control"
                           placeholder="<?php echo Text::_('COM_TALKBACKMIGRATE_SEARCH_PLACEHOLDER'); ?>"
                           value="<?php echo htmlspecialchars(Factory::getApplication()->getInput()->getString('q', '')); ?>">
                    <button type="submit" class="btn btn-outline-primary text-nowrap">
                        <span class="icon-search"></span> <?php echo Text::_('COM_TALKBACKMIGRATE_BTN_SEARCH'); ?>
                    </button>
                </form>

                <div class="btn-toolbar gap-2 mb-4 flex-wrap" role="toolbar">
                    <a href="<?php echo $baseUrl . '&action=check'; ?>" class="btn btn-info text-white">
                        <span class="icon-search"></span>
                        <?php echo Text::_('COM_TALKBACKMIGRATE_BTN_CHECK'); ?>
                    </a>
                    <a href="<?php echo $baseUrl . '&action=review'; ?>" class="btn btn-secondary">
                        <span class="icon-pencil-2"></span>
                        <?php echo Text::_('COM_TALKBACKMIGRATE_BTN_REVIEW'); ?>
                    </a>
                    <a href="<?php echo $baseUrl . '&action=unmatched'; ?>" class="btn btn-warning text-dark">
                        <span class="icon-list"></span>
                        <?php echo Text::_('COM_TALKBACKMIGRATE_BTN_UNMATCHED'); ?>
                    </a>
                    <a href="<?php echo $baseUrl . '&action=diagnose'; ?>" class="btn btn-outline-secondary">
                        <span class="icon-health"></span>
                        <?php echo Text::_('COM_TALKBACKMIGRATE_BTN_DIAGNOSE'); ?>
                    </a>
                    <a href="<?php echo $baseUrl . '&action=report'; ?>" class="btn btn-primary">
                        <span class="icon-eye"></span>
                        <?php echo Text::_('COM_TALKBACKMIGRATE_BTN_REPORT'); ?>
                    </a>
                    <a href="<?php echo $baseUrl . '&action=migrate'; ?>" class="btn btn-success text-white"
                       onclick="return confirm('<?php echo Text::_('COM_TALKBACKMIGRATE_CONFIRM_MIGRATE', true); ?>');">
                        <span class="icon-upload"></span>
                        <?php echo Text::_('COM_TALKBACKMIGRATE_BTN_MIGRATE'); ?>
                    </a>
                    <a href="<?php echo $baseUrl . '&action=undo'; ?>" class="btn btn-danger"
                       onclick="return confirm('<?php echo Text::_('COM_TALKBACKMIGRATE_CONFIRM_UNDO', true); ?>');">
                        <span class="icon-undo"></span>
                        <?php echo Text::_('COM_TALKBACKMIGRATE_BTN_UNDO'); ?>
                    </a>
                </div>
            </div>
        </div>

        <?php // === UNMATCHED ASSIGNMENT FORM === ?>
        <?php if ($this->action === 'unmatched') : ?>
        <div class="card mb-3">
            <div class="card-header">
                <strong><?php echo Text::_('COM_TALKBACKMIGRATE_UNMATCHED_TITLE'); ?></strong>
            </div>
            <div class="card-body">
                <?php if (empty($this->unmatchedData)) : ?>
                    <div class="alert alert-success">
                        <?php echo Text::_('COM_TALKBACKMIGRATE_ALL_MATCHED'); ?>
                    </div>
                <?php else : ?>
                    <p><?php echo Text::_('COM_TALKBACKMIGRATE_UNMATCHED_INSTRUCTIONS'); ?></p>
                    <form method="post" action="<?php echo Route::_('index.php?option=com_talkbackmigrate&action=save_overrides'); ?>">
                        <?php foreach ($this->unmatchedData as $idx => $item) : ?>
                        <div class="card mb-3">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>
                                    <strong><?php echo htmlspecialchars($item['href']); ?></strong>
                                    <span class="badge bg-secondary"><?php echo $item['count']; ?> <?php echo Text::_('COM_TALKBACKMIGRATE_COMMENTS'); ?></span>
                                </span>
                                <div class="d-flex align-items-center gap-2">
                                    <label class="mb-0 text-nowrap"><?php echo Text::_('COM_TALKBACKMIGRATE_ASSIGN_ARTICLE_ID'); ?></label>
                                    <input type="hidden" name="href[<?php echo $idx; ?>]" value="<?php echo htmlspecialchars($item['href']); ?>">
                                    <input type="number" name="article_id[<?php echo $idx; ?>]"
                                           value="<?php echo (int) $item['override_id']; ?>"
                                           class="form-control form-control-sm" style="width: 100px;"
                                           min="0" placeholder="0">
                                </div>
                            </div>
                            <div class="card-body p-2">
                                <table class="table table-sm table-striped mb-0" style="font-size: 12px;">
                                    <thead>
                                        <tr>
                                            <th style="width:50px;">ID</th>
                                            <th style="width:90px;"><?php echo Text::_('COM_TALKBACKMIGRATE_DATE'); ?></th>
                                            <th style="width:120px;"><?php echo Text::_('COM_TALKBACKMIGRATE_AUTHOR'); ?></th>
                                            <th><?php echo Text::_('COM_TALKBACKMIGRATE_COMMENT_TEXT'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($item['samples'] as $sample) : ?>
                                        <tr>
                                            <td><?php echo $sample['id']; ?></td>
                                            <td><?php echo $sample['date']; ?></td>
                                            <td><?php echo htmlspecialchars($sample['author']); ?></td>
                                            <td><?php echo $sample['text']; ?>...</td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if ($item['count'] > 5) : ?>
                                        <tr>
                                            <td colspan="4" class="text-muted text-center">
                                                ... <?php echo Text::sprintf('COM_TALKBACKMIGRATE_AND_MORE', $item['count'] - 5); ?>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <input type="hidden" name="<?php echo Session::getFormToken(); ?>" value="1">
                        <button type="submit" class="btn btn-primary">
                            <span class="icon-save"></span>
                            <?php echo Text::_('COM_TALKBACKMIGRATE_SAVE_ASSIGNMENTS'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php // === REVIEW ALL MAPPINGS === ?>
        <?php if ($this->action === 'review' && !empty($this->reviewData)) : ?>
        <div class="card mb-3">
            <div class="card-header">
                <strong><?php echo Text::_('COM_TALKBACKMIGRATE_REVIEW_TITLE'); ?></strong>
            </div>
            <div class="card-body">
                <p><?php echo Text::_('COM_TALKBACKMIGRATE_REVIEW_INSTRUCTIONS'); ?></p>
                <form method="post" action="<?php echo Route::_('index.php?option=com_talkbackmigrate&action=save_review'); ?>">
                    <?php foreach ($this->reviewData as $idx => $item) :
                        $badgeClass = 'bg-secondary';
                        $badgeText = 'NONE';
                        if ($item['match_type'] === 'auto') {
                            $badgeClass = 'bg-success';
                            $badgeText = 'AUTO';
                        } elseif ($item['match_type'] === 'override') {
                            $badgeClass = 'bg-primary';
                            $badgeText = 'MANUAL';
                        }
                    ?>
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span>
                                <span class="badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                                <strong><?php echo htmlspecialchars($item['href']); ?></strong>
                                <span class="badge bg-secondary"><?php echo $item['count']; ?> <?php echo Text::_('COM_TALKBACKMIGRATE_COMMENTS'); ?></span>
                            </span>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($item['article_id'] > 0) : ?>
                                    <span class="text-muted small"><?php echo htmlspecialchars($item['article_title']); ?></span>
                                <?php endif; ?>
                                <label class="mb-0 text-nowrap"><?php echo Text::_('COM_TALKBACKMIGRATE_ASSIGN_ARTICLE_ID'); ?></label>
                                <input type="hidden" name="href[<?php echo $idx; ?>]" value="<?php echo htmlspecialchars($item['href']); ?>">
                                <input type="number" name="article_id[<?php echo $idx; ?>]"
                                       value="<?php echo (int) $item['article_id']; ?>"
                                       class="form-control form-control-sm" style="width: 100px;"
                                       min="0" placeholder="0">
                            </div>
                        </div>
                        <div class="card-body p-2">
                            <table class="table table-sm table-striped mb-0" style="font-size: 12px;">
                                <thead>
                                    <tr>
                                        <th style="width:50px;">ID</th>
                                        <th style="width:90px;"><?php echo Text::_('COM_TALKBACKMIGRATE_DATE'); ?></th>
                                        <th style="width:120px;"><?php echo Text::_('COM_TALKBACKMIGRATE_AUTHOR'); ?></th>
                                        <th><?php echo Text::_('COM_TALKBACKMIGRATE_COMMENT_TEXT'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($item['samples'] as $sample) : ?>
                                    <tr>
                                        <td><?php echo $sample['id']; ?></td>
                                        <td><?php echo $sample['date']; ?></td>
                                        <td><?php echo htmlspecialchars($sample['author']); ?></td>
                                        <td><?php echo $sample['text']; ?>...</td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if ($item['count'] > 5) : ?>
                                    <tr>
                                        <td colspan="4" class="text-muted text-center">
                                            ... <?php echo Text::sprintf('COM_TALKBACKMIGRATE_AND_MORE', $item['count'] - 5); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <input type="hidden" name="<?php echo Session::getFormToken(); ?>" value="1">
                    <button type="submit" class="btn btn-primary">
                        <span class="icon-save"></span>
                        <?php echo Text::_('COM_TALKBACKMIGRATE_SAVE_ASSIGNMENTS'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php // === OUTPUT LOG === ?>
        <?php if (!empty($this->output)) : ?>
        <div class="card">
            <div class="card-header">
                <strong><?php echo Text::_('COM_TALKBACKMIGRATE_OUTPUT'); ?></strong>
                <?php if ($this->action) : ?>
                    <span class="badge bg-secondary"><?php echo strtoupper($this->action); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div style="font-family: monospace; font-size: 13px; line-height: 1.6; max-height: 600px; overflow-y: auto; padding: 10px; border-radius: 4px;">
                    <?php foreach ($this->output as $line) : ?>
                        <div><?php echo $line ?: '&nbsp;'; ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
