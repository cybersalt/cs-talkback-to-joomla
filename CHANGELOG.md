# Changelog

## 🚀 Version 1.0.1 (November 2026)

### 🔒 Security
- **Security hardening** of the migration tool's actions.

### 🔧 Improvements
- **Joomla 6 Compatibility**: Verified and tested for both Joomla 5.x and Joomla 6.x
- **PHP Version Check**: Installation now verifies PHP 8.1+ requirement
- **Joomla Version Check**: Installation now verifies Joomla 5.0+ requirement
- **Autoload Cache**: Installer clears autoload cache to prevent class-not-found errors on upgrade
- **Updates**: Joomla's Update Manager now offers new versions from cybersalt.com, and the Extensions manager's Changelog button works

## 🚀 Version 1.0.0 (March 2026)

### 📦 New Features
- **Comment Migration**: Migrates TalkBack comments and replies into JComments with full threading support
- **Auto-matching**: Resolves TalkBack URL paths to Joomla article IDs via slug matching
- **Manual Overrides**: Assign unmatched URLs to articles with sample comment preview
- **Review All Mappings**: View and edit all URL-to-article pairings
- **Search**: Find comments by author, text, email, or TalkBack ID across both systems
- **Diagnose Threading**: Side-by-side comparison of old and new comment data
- **Undo**: Safe removal of all imported comments via `source='talkback'` tag
- **Dry Run**: Preview migration results before committing
- **Language Detection**: Auto-detects JComments language setting for imported comments
- **Object Cache**: Automatically creates JComments object cache entries for migrated articles
