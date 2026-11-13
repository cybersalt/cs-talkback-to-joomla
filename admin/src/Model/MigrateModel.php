<?php
namespace Cybersalt\Component\TalkbackMigrate\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

class MigrateModel extends BaseDatabaseModel
{
    /**
     * Get saved manual href-to-article overrides
     */
    public function getOverrides(): array
    {
        $db = $this->getDatabase();
        $db->setQuery("SELECT params FROM #__extensions WHERE element = 'com_talkbackmigrate' AND type = 'component'");
        $params = $db->loadResult();
        if ($params) {
            $decoded = json_decode($params, true);
            return $decoded['overrides'] ?? [];
        }
        return [];
    }

    /**
     * Save manual href-to-article overrides
     */
    public function saveOverrides(array $overrides): void
    {
        $db = $this->getDatabase();
        // Ensure all values are integers and filter out zeros
        $clean = [];
        foreach ($overrides as $href => $articleId) {
            $id = (int) $articleId;
            if ($id > 0) {
                $clean[$href] = $id;
            }
        }
        $params = json_encode(['overrides' => $clean]);
        $db->setQuery("UPDATE #__extensions SET params = " . $db->quote($params) . " WHERE element = 'com_talkbackmigrate' AND type = 'component'");
        $db->execute();
    }

    /**
     * Get sample comments for a given href
     */
    private function getSamplesForHref(string $href, string $idColumn, int $limit = 5): array
    {
        $db = $this->getDatabase();
        $db->setQuery("SELECT * FROM bt_data WHERE href = " . $db->quote($href) . " AND moderate = 0 ORDER BY ID ASC LIMIT " . $limit);
        $samples = $db->loadAssocList();

        $sampleDisplay = [];
        foreach ($samples as $s) {
            $sampleDisplay[] = [
                'id' => $s[$idColumn] ?? '?',
                'date' => date('Y-m-d', (int) $s['time']),
                'author' => $s['author'] ?: 'Anonymous',
                'text' => htmlspecialchars(substr(strip_tags($s['text']), 0, 150)),
            ];
        }
        return $sampleDisplay;
    }

    /**
     * Detect the ID column name from a single row
     */
    private function detectIdColumnOnce(): string
    {
        $db = $this->getDatabase();
        $db->setQuery("SELECT * FROM bt_data LIMIT 1");
        $row = $db->loadAssoc();
        return $row ? $this->detectIdColumn([$row]) : 'ID';
    }

    /**
     * Get unmatched hrefs with sample comments for manual assignment
     */
    public function getUnmatched(): array
    {
        $db = $this->getDatabase();
        $result = $this->buildMapping();
        $mapping = $result['mapping'];
        $overrides = $this->getOverrides();
        $idColumn = $this->detectIdColumnOnce();

        $db->setQuery("SELECT DISTINCT href, COUNT(*) as comment_count FROM bt_data WHERE moderate = 0 GROUP BY href ORDER BY comment_count DESC");
        $hrefs = $db->loadObjectList();

        $unmatchedData = [];

        foreach ($hrefs as $hrefRow) {
            $href = $hrefRow->href;
            if (isset($mapping[$href])) {
                continue;
            }

            $unmatchedData[] = [
                'href' => $href,
                'count' => $hrefRow->comment_count,
                'samples' => $this->getSamplesForHref($href, $idColumn),
                'override_id' => $overrides[$href] ?? '',
            ];
        }

        return $unmatchedData;
    }

    /**
     * Get ALL hrefs with their current mapping (auto or override), sample comments,
     * and the assigned article info, so the user can review and change any assignment.
     */
    public function getAllMappings(): array
    {
        $db = $this->getDatabase();
        $overrides = $this->getOverrides();
        $idColumn = $this->detectIdColumnOnce();

        // Get all articles indexed by ID for lookups
        $query = $db->getQuery(true)
            ->select('c.id, c.alias, c.title, c.catid')
            ->select('cat.alias as cat_alias')
            ->from($db->quoteName('#__content', 'c'))
            ->leftJoin($db->quoteName('#__categories', 'cat') . ' ON c.catid = cat.id');
        $db->setQuery($query);
        $articlesById = [];
        foreach ($db->loadObjectList() as $a) {
            $articlesById[(int) $a->id] = $a;
        }

        // Get all hrefs
        $db->setQuery("SELECT DISTINCT href, COUNT(*) as comment_count FROM bt_data WHERE moderate = 0 GROUP BY href ORDER BY comment_count DESC");
        $hrefs = $db->loadObjectList();

        $allData = [];

        foreach ($hrefs as $hrefRow) {
            $href = $hrefRow->href;

            // Determine match type and article
            $matchType = 'none';
            $articleId = 0;
            $articleTitle = '';

            // Check override first
            if (isset($overrides[$href])) {
                $matchType = 'override';
                $articleId = (int) $overrides[$href];
                if (isset($articlesById[$articleId])) {
                    $articleTitle = $articlesById[$articleId]->title;
                } else {
                    $articleTitle = '(article #' . $articleId . ' not found!)';
                }
            } else {
                // Try auto-match
                $clean = trim($href, '/');
                $clean = preg_replace('/\.html?$/i', '', $clean);
                $segments = explode('/', $clean);
                $articleSlug = strtolower(end($segments));
                $categorySlug = count($segments) > 1 ? strtolower($segments[count($segments) - 2]) : '';

                foreach ($articlesById as $article) {
                    if (strtolower($article->alias) === $articleSlug) {
                        if ($categorySlug && isset($article->cat_alias) && strtolower($article->cat_alias) === $categorySlug) {
                            $matchType = 'auto';
                            $articleId = (int) $article->id;
                            $articleTitle = $article->title;
                            break;
                        }
                        if ($matchType !== 'auto') {
                            $matchType = 'auto';
                            $articleId = (int) $article->id;
                            $articleTitle = $article->title;
                        }
                    }
                }
            }

            $allData[] = [
                'href' => $href,
                'count' => $hrefRow->comment_count,
                'match_type' => $matchType,
                'article_id' => $articleId,
                'article_title' => $articleTitle,
                'samples' => $this->getSamplesForHref($href, $idColumn),
            ];
        }

        return $allData;
    }

    /**
     * Build mapping of TalkBack href URLs to Joomla article IDs
     * Incorporates manual overrides
     */
    public function buildMapping(): array
    {
        $db = $this->getDatabase();
        $log = [];
        $overrides = $this->getOverrides();

        // Check if bt_data table exists
        try {
            $db->setQuery("SELECT COUNT(*) FROM bt_data");
            $totalOld = $db->loadResult();
        } catch (\Exception $e) {
            return ['log' => [Text::_('COM_TALKBACKMIGRATE_ERROR_NO_BTDATA')], 'mapping' => []];
        }

        // Get unique hrefs
        $db->setQuery("SELECT DISTINCT href, COUNT(*) as comment_count FROM bt_data WHERE moderate = 0 GROUP BY href ORDER BY comment_count DESC");
        $hrefs = $db->loadObjectList();

        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_FOUND_PAGES', count($hrefs));

        // Get all Joomla articles with categories
        $query = $db->getQuery(true)
            ->select('c.id, c.alias, c.title, c.catid, c.created_by')
            ->select('cat.alias as cat_alias, cat.path as cat_path')
            ->from($db->quoteName('#__content', 'c'))
            ->leftJoin($db->quoteName('#__categories', 'cat') . ' ON c.catid = cat.id');
        $db->setQuery($query);
        $articles = $db->loadObjectList();
        $articlesById = [];
        foreach ($articles as $a) {
            $articlesById[(int) $a->id] = $a;
        }

        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_FOUND_ARTICLES', count($articles));

        if (!empty($overrides)) {
            $log[] = Text::sprintf('COM_TALKBACKMIGRATE_OVERRIDES_LOADED', count($overrides));
        }

        $log[] = '';

        $mapping = [];
        $unmatched = [];

        foreach ($hrefs as $hrefRow) {
            $href = $hrefRow->href;
            $count = $hrefRow->comment_count;

            // Check manual override first
            if (isset($overrides[$href]) && isset($articlesById[(int) $overrides[$href]])) {
                $overrideArticle = $articlesById[(int) $overrides[$href]];
                $mapping[$href] = $overrideArticle;
                $log[] = '<span class="text-primary">OVERRIDE</span> [' . $count . ' comments] ' . htmlspecialchars($href);
                $log[] = '&nbsp;&nbsp;&nbsp;&nbsp;&rarr; Article #' . $overrideArticle->id . ': ' . htmlspecialchars($overrideArticle->title);
                continue;
            }

            $clean = trim($href, '/');
            $clean = preg_replace('/\.html?$/i', '', $clean);

            $segments = explode('/', $clean);
            $articleSlug = strtolower(end($segments));
            $categorySlug = count($segments) > 1 ? strtolower($segments[count($segments) - 2]) : '';

            $bestMatch = null;

            foreach ($articles as $article) {
                if (strtolower($article->alias) === $articleSlug) {
                    if ($categorySlug && strtolower($article->cat_alias) === $categorySlug) {
                        $bestMatch = $article;
                        break;
                    }
                    if (!$bestMatch) {
                        $bestMatch = $article;
                    }
                }
            }

            if ($bestMatch) {
                $mapping[$href] = $bestMatch;
                $log[] = '<span class="text-success">MATCH</span> [' . $count . ' comments] ' . htmlspecialchars($href);
                $log[] = '&nbsp;&nbsp;&nbsp;&nbsp;&rarr; Article #' . $bestMatch->id . ': ' . htmlspecialchars($bestMatch->title);
            } else {
                $unmatched[] = ['href' => $href, 'count' => $count, 'slug' => $articleSlug];
                $log[] = '<span class="text-danger">NO MATCH</span> [' . $count . ' comments] ' . htmlspecialchars($href) . ' (slug: ' . htmlspecialchars($articleSlug) . ')';
            }
        }

        $log[] = '';
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_MATCHED_COUNT', count($mapping), count($hrefs));

        if (!empty($unmatched)) {
            $unmatchedTotal = array_sum(array_column($unmatched, 'count'));
            $log[] = '<span class="text-warning">' . Text::sprintf('COM_TALKBACKMIGRATE_UNMATCHED_COUNT', count($unmatched), $unmatchedTotal) . '</span>';
        }

        return ['log' => $log, 'mapping' => $mapping, 'unmatched' => $unmatched];
    }

    /**
     * Report mode - dry run showing what would be migrated
     */
    public function report(): array
    {
        $db = $this->getDatabase();
        $result = $this->buildMapping();
        $log = $result['log'];
        $mapping = $result['mapping'];

        if (empty($mapping)) {
            return $log;
        }

        $log[] = '';
        $log[] = '<strong>' . Text::_('COM_TALKBACKMIGRATE_PROCESSING') . '</strong>';
        $log[] = '';

        // Get all approved comments
        $db->setQuery("SELECT * FROM bt_data WHERE moderate = 0 ORDER BY ID ASC");
        $allComments = $db->loadAssocList();
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_TOTAL_APPROVED', count($allComments));

        // Detect ID column
        $idColumn = $this->detectIdColumn($allComments);
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_ID_COLUMN', $idColumn);
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_COLUMNS', implode(', ', array_keys($allComments[0] ?? [])));

        // Check for previous imports
        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback'");
        $alreadyImported = (int) $db->loadResult();
        if ($alreadyImported > 0) {
            $log[] = '<span class="text-warning">' . Text::sprintf('COM_TALKBACKMIGRATE_ALREADY_IMPORTED', $alreadyImported) . '</span>';
        }

        // Separate top-level and replies
        $topLevel = [];
        $replies = [];
        $skipped = 0;

        foreach ($allComments as $c) {
            if (!isset($mapping[$c['href']])) {
                $skipped++;
                continue;
            }
            if ($c['replyto'] == $c[$idColumn] || $c['replyto'] == 0) {
                $topLevel[] = $c;
            } else {
                $replies[] = $c;
            }
        }

        $log[] = '';
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_TOPLEVEL_COUNT', count($topLevel));
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_REPLIES_COUNT', count($replies));
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_SKIPPED_COUNT', $skipped);

        // Sample top-level
        $log[] = '';
        $log[] = '<strong>' . Text::_('COM_TALKBACKMIGRATE_SAMPLE_TOPLEVEL') . '</strong>';
        $log[] = '';
        foreach (array_slice($topLevel, 0, 10) as $c) {
            $article = $mapping[$c['href']];
            $date = date('Y-m-d H:i:s', (int)$c['time']);
            $text = htmlspecialchars(substr(strip_tags($c['text']), 0, 120));
            $log[] = '&nbsp;&nbsp;#' . $c[$idColumn] . ' by <strong>' . htmlspecialchars($c['author']) . '</strong> on ' . $date;
            $log[] = '&nbsp;&nbsp;&nbsp;&nbsp;Article: ' . htmlspecialchars($article->title);
            $log[] = '&nbsp;&nbsp;&nbsp;&nbsp;Text: ' . $text . '...';
            $log[] = '';
        }

        // Sample replies
        $log[] = '<strong>' . Text::_('COM_TALKBACKMIGRATE_SAMPLE_REPLIES') . '</strong>';
        $log[] = '';
        foreach (array_slice($replies, 0, 5) as $c) {
            $date = date('Y-m-d H:i:s', (int)$c['time']);
            $text = htmlspecialchars(substr(strip_tags($c['text']), 0, 120));
            $log[] = '&nbsp;&nbsp;#' . $c[$idColumn] . ' reply to #' . $c['replyto'] . ' by <strong>' . htmlspecialchars($c['author']) . '</strong> on ' . $date;
            $log[] = '&nbsp;&nbsp;&nbsp;&nbsp;Text: ' . $text . '...';
            $log[] = '';
        }

        $log[] = '<span class="text-info"><strong>' . Text::_('COM_TALKBACKMIGRATE_DRYRUN_COMPLETE') . '</strong></span>';

        return $log;
    }

    /**
     * Run the actual migration
     */
    public function migrate(): array
    {
        $db = $this->getDatabase();
        $result = $this->buildMapping();
        $log = $result['log'];
        $mapping = $result['mapping'];

        if (empty($mapping)) {
            return $log;
        }

        // Check for previous imports
        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback'");
        $alreadyImported = (int) $db->loadResult();
        if ($alreadyImported > 0) {
            $log[] = '';
            $log[] = '<span class="text-danger"><strong>' . Text::sprintf('COM_TALKBACKMIGRATE_ALREADY_IMPORTED', $alreadyImported) . '</strong></span>';
            $log[] = Text::_('COM_TALKBACKMIGRATE_UNDO_FIRST');
            return $log;
        }

        $log[] = '';
        $log[] = '<strong>' . Text::_('COM_TALKBACKMIGRATE_STARTING') . '</strong>';
        $log[] = '';

        // Get all approved comments
        $db->setQuery("SELECT * FROM bt_data WHERE moderate = 0 ORDER BY ID ASC");
        $allComments = $db->loadAssocList();
        $idColumn = $this->detectIdColumn($allComments);
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_ID_COLUMN', $idColumn);

        // Debug: show a sample reply mapping
        $sampleReply = null;
        foreach ($allComments as $c) {
            if ($c['replyto'] != $c[$idColumn] && $c['replyto'] != 0 && $c['replyto'] != '0') {
                $sampleReply = $c;
                break;
            }
        }
        if ($sampleReply) {
            $log[] = 'Debug: Sample reply - ' . $idColumn . '=' . $sampleReply[$idColumn]
                   . ' replyto=' . $sampleReply['replyto']
                   . ' (type: ' . gettype($sampleReply[$idColumn]) . '/' . gettype($sampleReply['replyto']) . ')';
        }

        // Separate top-level and replies
        $topLevel = [];
        $replies = [];
        $skipped = 0;

        foreach ($allComments as $c) {
            if (!isset($mapping[$c['href']])) {
                $skipped++;
                continue;
            }
            // Cast both to int for reliable comparison
            $thisId = (int) $c[$idColumn];
            $thisReplyTo = (int) $c['replyto'];

            if ($thisReplyTo === $thisId || $thisReplyTo === 0) {
                $topLevel[] = $c;
            } else {
                $replies[] = $c;
            }
        }

        $log[] = 'Separated: ' . count($topLevel) . ' top-level, ' . count($replies) . ' replies, ' . $skipped . ' skipped';
        $log[] = '';

        // Detect language to use from existing JComment
        $db->setQuery("SELECT lang, COUNT(*) as cnt FROM #__jcomment WHERE (source != 'talkback' OR source IS NULL OR source = '') AND lang != '' GROUP BY lang ORDER BY cnt DESC LIMIT 1");
        $existingLang = $db->loadObject();
        $commentLang = $existingLang ? $existingLang->lang : 'en-GB';
        $log[] = 'Using language: ' . $commentLang;
        $log[] = '';

        // Pass 1: Top-level comments (INSERT all, then batch-fix thread_id)
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_PASS1', count($topLevel));
        $oldToNew = [];
        $imported = 0;
        $errors = 0;

        foreach ($topLevel as $c) {
            $article = $mapping[$c['href']];
            $oldId = (int) $c[$idColumn];

            $columns = [
                'parent', 'thread_id', 'path', 'level',
                'object_id', 'object_group', 'viewname',
                'userid', 'name', 'username', 'email', 'homepage',
                'title', 'comment', 'ip', 'date',
                'published', 'subscribe', 'source', 'source_id', 'lang'
            ];

            $values = [
                0, 0, $db->quote('0'), 0,
                (int) $article->id, $db->quote('com_content'), $db->quote('article'),
                0, $db->quote($c['author'] ?: 'Anonymous'), $db->quote($c['author'] ?: 'Anonymous'),
                $db->quote($c['email'] ?: ''), $db->quote($c['website'] ?: ''),
                $db->quote($c['subject'] ?: ''), $db->quote($c['text']),
                $db->quote($c['ip'] ?: ''),
                $db->quote(date('Y-m-d H:i:s', (int) $c['time'])),
                1, (int) ($c['email_reply'] ?? 0),
                $db->quote('talkback'), $oldId, $db->quote($commentLang)
            ];

            $query = "INSERT INTO #__jcomment (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";

            try {
                $db->setQuery($query);
                $db->execute();
                $newId = $db->insertid();
                // Use integer keys for reliable lookup
                $oldToNew[$oldId] = (int) $newId;
                $imported++;
            } catch (\Exception $e) {
                $log[] = '<span class="text-danger">ERROR #' . $oldId . ': ' . htmlspecialchars($e->getMessage()) . '</span>';
                $errors++;
            }
        }

        // Batch fix: set thread_id = id for all top-level comments
        $db->setQuery("UPDATE #__jcomment SET thread_id = id WHERE source = 'talkback' AND parent = 0 AND thread_id = 0");
        $db->execute();
        $threadFixed = $db->getAffectedRows();

        $log[] = '&nbsp;&nbsp;' . Text::sprintf('COM_TALKBACKMIGRATE_PASS1_DONE', $imported, $errors);
        $log[] = '&nbsp;&nbsp;Batch thread_id fix applied to ' . $threadFixed . ' top-level comments';
        $log[] = '';

        // Debug: show first few oldToNew mappings
        $mapSample = array_slice($oldToNew, 0, 3, true);
        foreach ($mapSample as $oldId => $newId) {
            $log[] = '&nbsp;&nbsp;Debug map: old #' . $oldId . ' -> new #' . $newId;
        }
        $log[] = '&nbsp;&nbsp;Total mappings in oldToNew: ' . count($oldToNew);
        $log[] = '';

        // Pass 2: Replies
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_PASS2', count($replies));
        $replyImported = 0;
        $replySkipped = 0;
        $replySkippedSamples = [];

        foreach ($replies as $c) {
            $article = $mapping[$c['href']];
            $parentOldId = (int) $c['replyto'];

            if (!isset($oldToNew[$parentOldId])) {
                $replySkipped++;
                if (count($replySkippedSamples) < 5) {
                    $replySkippedSamples[] = '#' . (int)$c[$idColumn] . ' (replyto=' . $parentOldId . ')';
                }
                continue;
            }

            $parentNewId = $oldToNew[$parentOldId];

            $columns = [
                'parent', 'thread_id', 'path', 'level',
                'object_id', 'object_group', 'viewname',
                'userid', 'name', 'username', 'email', 'homepage',
                'title', 'comment', 'ip', 'date',
                'published', 'subscribe', 'source', 'source_id', 'lang'
            ];

            $values = [
                $parentNewId, $parentNewId, $db->quote('0,' . $parentNewId), 1,
                (int) $article->id, $db->quote('com_content'), $db->quote('article'),
                0, $db->quote($c['author'] ?: 'Anonymous'), $db->quote($c['author'] ?: 'Anonymous'),
                $db->quote($c['email'] ?: ''), $db->quote($c['website'] ?: ''),
                $db->quote($c['subject'] ?: ''), $db->quote($c['text']),
                $db->quote($c['ip'] ?: ''),
                $db->quote(date('Y-m-d H:i:s', (int) $c['time'])),
                1, (int) ($c['email_reply'] ?? 0),
                $db->quote('talkback'), (int) $c[$idColumn], $db->quote($commentLang)
            ];

            $query = "INSERT INTO #__jcomment (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";

            try {
                $db->setQuery($query);
                $db->execute();
                $newId = $db->insertid();
                $oldToNew[(int) $c[$idColumn]] = (int) $newId;
                $replyImported++;
            } catch (\Exception $e) {
                $log[] = '<span class="text-danger">ERROR reply #' . $c[$idColumn] . ': ' . htmlspecialchars($e->getMessage()) . '</span>';
                $errors++;
            }
        }
        $log[] = '&nbsp;&nbsp;' . Text::sprintf('COM_TALKBACKMIGRATE_PASS2_DONE', $replyImported, $replySkipped);
        if (!empty($replySkippedSamples)) {
            $log[] = '&nbsp;&nbsp;<span class="text-warning">Skipped reply samples: ' . implode(', ', $replySkippedSamples) . '</span>';
        }
        $log[] = '';

        // Pass 3: Object cache
        $log[] = Text::_('COM_TALKBACKMIGRATE_PASS3');
        try {
            $db->setQuery("
                INSERT IGNORE INTO #__jcomment_objects
                (object_id, object_group, viewname, lang, title, link, access, userid, expired, modified)
                SELECT DISTINCT c.object_id, c.object_group, 'article', " . $db->quote($commentLang) . ", a.title,
                       CONCAT('index.php?option=com_content&view=article&id=', a.id, '&catid=', a.catid),
                       1, a.created_by, 0, NOW()
                FROM #__jcomment c
                JOIN #__content a ON c.object_id = a.id
                WHERE c.source = 'talkback'
                AND NOT EXISTS (
                    SELECT 1 FROM #__jcomment_objects o
                    WHERE o.object_id = c.object_id AND o.object_group = c.object_group
                )
            ");
            $db->execute();
            $log[] = '&nbsp;&nbsp;' . Text::sprintf('COM_TALKBACKMIGRATE_PASS3_DONE', $db->getAffectedRows());
        } catch (\Exception $e) {
            $log[] = '<span class="text-danger">ERROR: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }

        // Pass 4: Fix language field to match existing JComment
        $log[] = 'Pass 4: Fixing language field...';
        try {
            // Detect the lang used by existing non-imported comments
            $db->setQuery("SELECT lang, COUNT(*) as cnt FROM #__jcomment WHERE (source != 'talkback' OR source IS NULL OR source = '') AND lang != '' GROUP BY lang ORDER BY cnt DESC LIMIT 1");
            $existingLang = $db->loadObject();
            $lang = $existingLang ? $existingLang->lang : 'en-GB';

            $db->setQuery("UPDATE #__jcomment SET lang = " . $db->quote($lang) . " WHERE source = 'talkback' AND (lang = '' OR lang IS NULL)");
            $db->execute();
            $langFixed = $db->getAffectedRows();
            $log[] = '&nbsp;&nbsp;Set lang="' . htmlspecialchars($lang) . '" on ' . $langFixed . ' comments';
        } catch (\Exception $e) {
            $log[] = '<span class="text-danger">ERROR: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }
        $log[] = '';

        // Pass 5: Verification
        $log[] = '';
        $log[] = '<strong>Verification:</strong>';
        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback'");
        $totalInDb = (int) $db->loadResult();
        $log[] = '&nbsp;&nbsp;Total comments in JComment: ' . $totalInDb;

        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback' AND parent = 0");
        $topInDb = (int) $db->loadResult();
        $log[] = '&nbsp;&nbsp;Top-level (parent=0): ' . $topInDb;

        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback' AND parent > 0");
        $repliesInDb = (int) $db->loadResult();
        $log[] = '&nbsp;&nbsp;Replies (parent>0): ' . $repliesInDb;

        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback' AND thread_id = 0");
        $brokenThread = (int) $db->loadResult();
        if ($brokenThread > 0) {
            $log[] = '&nbsp;&nbsp;<span class="text-warning">Comments with thread_id=0: ' . $brokenThread . ' (fixing...)</span>';
            $db->setQuery("UPDATE #__jcomment SET thread_id = id WHERE source = 'talkback' AND parent = 0 AND thread_id = 0");
            $db->execute();
        }

        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback' AND level = 0 AND parent > 0");
        $brokenLevel = (int) $db->loadResult();
        if ($brokenLevel > 0) {
            $log[] = '&nbsp;&nbsp;<span class="text-warning">Replies with level=0: ' . $brokenLevel . ' (fixing...)</span>';
            $db->setQuery("UPDATE #__jcomment SET level = 1 WHERE source = 'talkback' AND parent > 0 AND level = 0");
            $db->execute();
        }

        $log[] = '';
        $totalImported = $imported + $replyImported;
        $log[] = '<span class="text-success"><strong>' . Text::_('COM_TALKBACKMIGRATE_COMPLETE') . '</strong></span>';
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_COMPLETE_SUMMARY', $totalImported, $imported, $replyImported);
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_COMPLETE_ERRORS', $errors);
        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_COMPLETE_SKIPPED', $skipped + $replySkipped);

        return $log;
    }

    /**
     * Undo all imported comments
     */
    public function undo(): array
    {
        $db = $this->getDatabase();
        $log = [];

        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback'");
        $count = (int) $db->loadResult();

        if ($count === 0) {
            $log[] = Text::_('COM_TALKBACKMIGRATE_NOTHING_TO_UNDO');
            return $log;
        }

        $log[] = Text::sprintf('COM_TALKBACKMIGRATE_UNDOING', $count);

        // Delete votes
        try {
            $db->setQuery("DELETE v FROM #__jcomment_votes v
                           INNER JOIN #__jcomment c ON v.commentid = c.id
                           WHERE c.source = 'talkback'");
            $db->execute();
            $log[] = '&nbsp;&nbsp;' . Text::sprintf('COM_TALKBACKMIGRATE_DELETED_VOTES', $db->getAffectedRows());
        } catch (\Exception $e) {
            $log[] = '<span class="text-danger">ERROR: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }

        // Delete comments
        try {
            $db->setQuery("DELETE FROM #__jcomment WHERE source='talkback'");
            $db->execute();
            $log[] = '&nbsp;&nbsp;' . Text::sprintf('COM_TALKBACKMIGRATE_DELETED_COMMENTS', $db->getAffectedRows());
        } catch (\Exception $e) {
            $log[] = '<span class="text-danger">ERROR: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }

        // Clean orphaned object cache
        try {
            $db->setQuery("DELETE o FROM #__jcomment_objects o
                           LEFT JOIN #__jcomment c ON o.object_id = c.object_id AND o.object_group = c.object_group
                           WHERE c.id IS NULL");
            $db->execute();
            $log[] = '&nbsp;&nbsp;' . Text::sprintf('COM_TALKBACKMIGRATE_CLEANED_OBJECTS', $db->getAffectedRows());
        } catch (\Exception $e) {
            $log[] = '<span class="text-danger">ERROR: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }

        $log[] = '';
        $log[] = '<span class="text-success"><strong>' . Text::_('COM_TALKBACKMIGRATE_UNDO_COMPLETE') . '</strong></span>';

        return $log;
    }

    /**
     * Diagnose threading for a specific href - shows old TalkBack data vs what's in JComment
     */
    public function diagnose(string $href = ''): array
    {
        $db = $this->getDatabase();
        $log = [];
        $idColumn = $this->detectIdColumnOnce();

        if (empty($href)) {
            // Show list of hrefs to diagnose
            $db->setQuery("SELECT DISTINCT href, COUNT(*) as cnt FROM bt_data WHERE moderate = 0 GROUP BY href ORDER BY cnt DESC");
            $hrefs = $db->loadObjectList();
            $log[] = '<strong>Select a page to diagnose threading:</strong>';
            $log[] = '';
            foreach ($hrefs as $h) {
                $url = 'index.php?option=com_talkbackmigrate&action=diagnose&href=' . urlencode($h->href);
                $log[] = '<a href="' . $url . '">' . htmlspecialchars($h->href) . '</a> (' . $h->cnt . ' comments)';
            }
            return $log;
        }

        $log[] = '<strong>Diagnosing: ' . htmlspecialchars($href) . '</strong>';
        $log[] = '';

        // === OLD TALKBACK DATA ===
        $log[] = '<strong style="color:#0d6efd;">OLD TALKBACK DATA (bt_data):</strong>';
        $db->setQuery("SELECT * FROM bt_data WHERE href = " . $db->quote($href) . " AND moderate = 0 ORDER BY replyto ASC, " . $idColumn . " ASC");
        $oldComments = $db->loadAssocList();

        if (empty($oldComments)) {
            $log[] = 'No comments found for this href.';
            return $log;
        }

        $log[] = 'Total: ' . count($oldComments) . ' comments';
        $log[] = '';

        // Group by thread (replyto)
        $threads = [];
        $topLevelCount = 0;
        $replyCount = 0;

        foreach ($oldComments as $c) {
            $cId = (int) $c[$idColumn];
            $cReplyTo = (int) $c['replyto'];
            $isTopLevel = ($cReplyTo === $cId || $cReplyTo === 0);

            if ($isTopLevel) {
                $topLevelCount++;
            } else {
                $replyCount++;
            }
        }

        $log[] = 'Top-level (replyto=ID): <strong>' . $topLevelCount . '</strong>';
        $log[] = 'Replies (replyto!=ID): <strong>' . $replyCount . '</strong>';
        $log[] = '';

        // Show threaded view - first 30 comments
        $log[] = '<table class="table table-sm table-bordered" style="font-size:12px;">';
        $log[] = '<thead><tr><th>ID</th><th>replyto</th><th>Type</th><th>Date</th><th>Author</th><th>Text</th></tr></thead>';
        $log[] = '<tbody>';

        $shown = 0;
        foreach ($oldComments as $c) {
            if ($shown >= 30) break;
            $cId = (int) $c[$idColumn];
            $cReplyTo = (int) $c['replyto'];
            $isTopLevel = ($cReplyTo === $cId || $cReplyTo === 0);
            $indent = $isTopLevel ? '' : '&nbsp;&nbsp;&nbsp;&nbsp;&#x2514; ';
            $typeLabel = $isTopLevel
                ? '<span class="badge bg-success">TOP</span>'
                : '<span class="badge bg-info">REPLY to #' . $cReplyTo . '</span>';
            $date = date('Y-m-d H:i', (int) $c['time']);
            $text = htmlspecialchars(substr(strip_tags($c['text']), 0, 80));

            $log[] = '<tr' . ($isTopLevel ? ' style="font-weight:bold;"' : '') . '>'
                . '<td>' . $cId . '</td>'
                . '<td>' . $cReplyTo . '</td>'
                . '<td>' . $indent . $typeLabel . '</td>'
                . '<td>' . $date . '</td>'
                . '<td>' . htmlspecialchars($c['author']) . '</td>'
                . '<td>' . $text . '</td>'
                . '</tr>';
            $shown++;
        }
        $log[] = '</tbody></table>';

        if (count($oldComments) > 30) {
            $log[] = '... and ' . (count($oldComments) - 30) . ' more';
        }

        // === JCOMMENT DATA ===
        $log[] = '';
        $log[] = '<strong style="color:#198754;">JCOMMENT DATA (after migration):</strong>';

        // Find the article ID for this href
        $result = $this->buildMapping();
        $mapping = $result['mapping'];

        if (!isset($mapping[$href])) {
            $log[] = '<span class="text-danger">This href has no article mapping - comments were not migrated.</span>';
            return $log;
        }

        $articleId = (int) $mapping[$href]->id;
        $log[] = 'Mapped to Article #' . $articleId . ': ' . htmlspecialchars($mapping[$href]->title);
        $log[] = '';

        $db->setQuery("SELECT id, parent, thread_id, path, level, source_id, name, `date`,
                        SUBSTRING(comment, 1, 80) as comment_preview
                        FROM #__jcomment
                        WHERE source = 'talkback' AND object_id = " . $articleId . "
                        ORDER BY thread_id ASC, path ASC, `date` ASC
                        LIMIT 30");
        $jcComments = $db->loadObjectList();

        if (empty($jcComments)) {
            $log[] = '<span class="text-warning">No migrated comments found in JComment for this article.</span>';
            return $log;
        }

        $log[] = 'Found ' . count($jcComments) . ' comments in JComment:';
        $log[] = '';

        $log[] = '<table class="table table-sm table-bordered" style="font-size:12px;">';
        $log[] = '<thead><tr><th>JC ID</th><th>source_id</th><th>parent</th><th>thread_id</th><th>path</th><th>level</th><th>Date</th><th>Author</th><th>Text</th></tr></thead>';
        $log[] = '<tbody>';

        foreach ($jcComments as $jc) {
            $isReply = ($jc->parent > 0);
            $indent = $isReply ? '&nbsp;&nbsp;&#x2514; ' : '';
            $rowStyle = $isReply ? '' : ' style="font-weight:bold;"';
            $parentWarning = '';
            if ($jc->parent == 0 && $jc->level == 0 && $jc->thread_id == 0) {
                $parentWarning = ' <span class="badge bg-danger">thread_id=0!</span>';
            }

            $log[] = '<tr' . $rowStyle . '>'
                . '<td>' . $jc->id . '</td>'
                . '<td>' . $jc->source_id . '</td>'
                . '<td>' . $jc->parent . '</td>'
                . '<td>' . $jc->thread_id . $parentWarning . '</td>'
                . '<td>' . $jc->path . '</td>'
                . '<td>' . $jc->level . '</td>'
                . '<td>' . $jc->date . '</td>'
                . '<td>' . $indent . htmlspecialchars($jc->name) . '</td>'
                . '<td>' . htmlspecialchars(strip_tags($jc->comment_preview)) . '</td>'
                . '</tr>';
        }
        $log[] = '</tbody></table>';

        // Summary stats
        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback' AND object_id=" . $articleId . " AND parent=0");
        $jcTop = (int) $db->loadResult();
        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback' AND object_id=" . $articleId . " AND parent>0");
        $jcReplies = (int) $db->loadResult();
        $db->setQuery("SELECT COUNT(*) FROM #__jcomment WHERE source='talkback' AND object_id=" . $articleId . " AND thread_id=0");
        $jcBroken = (int) $db->loadResult();

        $log[] = '';
        $log[] = '<strong>JComment stats for this article:</strong>';
        $log[] = '&nbsp;&nbsp;Top-level (parent=0): ' . $jcTop;
        $log[] = '&nbsp;&nbsp;Replies (parent>0): ' . $jcReplies;
        if ($jcBroken > 0) {
            $log[] = '&nbsp;&nbsp;<span class="text-danger">Broken thread_id=0: ' . $jcBroken . '</span>';
        }

        return $log;
    }

    /**
     * Search for comments by author, text, or TalkBack ID.
     * Shows both old TalkBack data and where it ended up in JComment.
     */
    public function search(string $query): array
    {
        $db = $this->getDatabase();
        $log = [];
        $idColumn = $this->detectIdColumnOnce();

        if (empty(trim($query))) {
            $log[] = Text::_('COM_TALKBACKMIGRATE_SEARCH_EMPTY');
            return $log;
        }

        $searchTerm = $db->quote('%' . $db->escape($query, true) . '%');

        $log[] = '<strong>' . Text::sprintf('COM_TALKBACKMIGRATE_SEARCH_RESULTS', htmlspecialchars($query)) . '</strong>';
        $log[] = '';

        // Search in bt_data
        $sql = "SELECT * FROM bt_data WHERE moderate = 0 AND (author LIKE " . $searchTerm
             . " OR text LIKE " . $searchTerm
             . " OR subject LIKE " . $searchTerm
             . " OR email LIKE " . $searchTerm;

        // If query is numeric, also search by ID
        if (is_numeric($query)) {
            $sql .= " OR " . $idColumn . " = " . (int) $query;
        }

        $sql .= ") ORDER BY " . $idColumn . " ASC LIMIT 50";

        $db->setQuery($sql);
        $oldResults = $db->loadAssocList();

        if (empty($oldResults)) {
            $log[] = Text::_('COM_TALKBACKMIGRATE_SEARCH_NO_OLD');
            $log[] = '';
        } else {
            $log[] = '<strong style="color:#0d6efd;">' . Text::sprintf('COM_TALKBACKMIGRATE_SEARCH_OLD_COUNT', count($oldResults)) . '</strong>';
            $log[] = '';

            // Build mapping for article title lookup
            $result = $this->buildMapping();
            $mapping = $result['mapping'];

            $log[] = '<table class="table table-sm table-bordered" style="font-size:12px;">';
            $log[] = '<thead><tr><th>TB ID</th><th>Type</th><th>Date</th><th>Author</th><th>Original Page</th><th>Mapped To</th><th>Text</th></tr></thead>';
            $log[] = '<tbody>';

            foreach ($oldResults as $c) {
                $cId = (int) $c[$idColumn];
                $cReplyTo = (int) $c['replyto'];
                $isTopLevel = ($cReplyTo === $cId || $cReplyTo === 0);

                $typeLabel = $isTopLevel
                    ? '<span class="badge bg-success">TOP</span>'
                    : '<span class="badge bg-info">REPLY #' . $cReplyTo . '</span>';

                $date = date('Y-m-d', (int) $c['time']);
                $text = htmlspecialchars(substr(strip_tags($c['text']), 0, 100));
                $href = htmlspecialchars($c['href']);

                $mappedTo = '<span class="text-danger">unmapped</span>';
                if (isset($mapping[$c['href']])) {
                    $art = $mapping[$c['href']];
                    $mappedTo = 'Article #' . $art->id . ': ' . htmlspecialchars(substr($art->title, 0, 40));
                }

                $log[] = '<tr>'
                    . '<td>' . $cId . '</td>'
                    . '<td>' . $typeLabel . '</td>'
                    . '<td>' . $date . '</td>'
                    . '<td>' . htmlspecialchars($c['author']) . '</td>'
                    . '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' . $href . '">' . $href . '</td>'
                    . '<td>' . $mappedTo . '</td>'
                    . '<td>' . $text . '</td>'
                    . '</tr>';
            }

            $log[] = '</tbody></table>';

            if (count($oldResults) >= 50) {
                $log[] = '<em>' . Text::_('COM_TALKBACKMIGRATE_SEARCH_TRUNCATED') . '</em>';
            }
        }

        // Search in JComment for the imported version
        $log[] = '';
        $log[] = '<strong style="color:#198754;">' . Text::_('COM_TALKBACKMIGRATE_SEARCH_IN_JCOMMENTS') . '</strong>';
        $log[] = '';

        $jcSql = "SELECT jc.id, jc.source_id, jc.object_id, jc.parent, jc.thread_id, jc.level,
                         jc.name, jc.`date`, SUBSTRING(jc.comment, 1, 100) as comment_preview,
                         a.title as article_title
                  FROM #__jcomment jc
                  LEFT JOIN #__content a ON jc.object_id = a.id
                  WHERE jc.source = 'talkback'
                  AND (jc.name LIKE " . $searchTerm
                . " OR jc.comment LIKE " . $searchTerm
                . " OR jc.email LIKE " . $searchTerm;

        if (is_numeric($query)) {
            $jcSql .= " OR jc.source_id = " . (int) $query
                    . " OR jc.id = " . (int) $query;
        }

        $jcSql .= ") ORDER BY jc.id ASC LIMIT 50";

        $db->setQuery($jcSql);
        $jcResults = $db->loadObjectList();

        if (empty($jcResults)) {
            $log[] = Text::_('COM_TALKBACKMIGRATE_SEARCH_NO_JC');
        } else {
            $log[] = Text::sprintf('COM_TALKBACKMIGRATE_SEARCH_JC_COUNT', count($jcResults));
            $log[] = '';

            $log[] = '<table class="table table-sm table-bordered" style="font-size:12px;">';
            $log[] = '<thead><tr><th>JC ID</th><th>TB ID</th><th>Type</th><th>Date</th><th>Author</th><th>Article</th><th>Text</th></tr></thead>';
            $log[] = '<tbody>';

            foreach ($jcResults as $jc) {
                $isReply = ($jc->parent > 0);
                $typeLabel = $isReply
                    ? '<span class="badge bg-info">REPLY (parent=' . $jc->parent . ')</span>'
                    : '<span class="badge bg-success">TOP</span>';

                $articleInfo = $jc->article_title
                    ? '#' . $jc->object_id . ': ' . htmlspecialchars(substr($jc->article_title, 0, 40))
                    : '#' . $jc->object_id;

                $log[] = '<tr>'
                    . '<td>' . $jc->id . '</td>'
                    . '<td>' . $jc->source_id . '</td>'
                    . '<td>' . $typeLabel . '</td>'
                    . '<td>' . $jc->date . '</td>'
                    . '<td>' . htmlspecialchars($jc->name) . '</td>'
                    . '<td>' . $articleInfo . '</td>'
                    . '<td>' . htmlspecialchars(strip_tags($jc->comment_preview)) . '</td>'
                    . '</tr>';
            }

            $log[] = '</tbody></table>';
        }

        return $log;
    }

    /**
     * Detect the actual column name for the primary key in bt_data
     */
    private function detectIdColumn(array $rows): string
    {
        if (empty($rows)) {
            return 'ID';
        }
        $first = $rows[0];
        if (array_key_exists('ID', $first)) return 'ID';
        if (array_key_exists('id', $first)) return 'id';
        if (array_key_exists('Id', $first)) return 'Id';
        return 'ID';
    }
}
