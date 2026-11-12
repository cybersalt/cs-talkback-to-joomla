# cs-talkback-to-joomla

A Joomla 5/6 admin component that migrates comments from the legacy TalkBack commenting system into [JComments](https://storejextensions.org).

## Features

- **Auto-matching** — Resolves old TalkBack URL paths to Joomla article IDs by matching URL slugs against article aliases
- **Manual overrides** — Assign unmatched URLs to specific articles with a visual preview of sample comments
- **Review all mappings** — See every URL-to-article pairing with sample comments and change any assignment
- **Threaded replies** — Preserves the original comment threading structure (parent/child relationships)
- **Original dates preserved** — Comments keep their original timestamps for proper chronological sorting
- **Language-aware** — Auto-detects the JComments language setting and applies it to imported comments
- **Search** — Find any comment by author, text, email, or TalkBack ID and see where it ended up
- **Diagnose threading** — Side-by-side view of old TalkBack data vs JComments data for any page
- **Safe undo** — All imported comments are tagged with `source='talkback'` and can be removed with one click
- **Dry run** — Preview everything before committing to the migration

## Requirements

- Joomla 5.x or 6.x
- PHP 8.1+ (PHP 8.3+ recommended for Joomla 6)
- JComments extension installed and enabled
- TalkBack `bt_data` table present in the same database

## Installation

1. Download the latest release ZIP from the [Releases](../../releases) page
2. In Joomla admin, go to **System → Install → Extensions**
3. Upload the ZIP file
4. The component appears in the admin menu as **TalkBack to JComments Migration**

## Usage

1. **Check Mapping** — See which TalkBack URLs auto-match to Joomla articles
2. **Assign Unmatched** — Manually assign URLs that couldn't be auto-matched
3. **Review All Mappings** — Verify and adjust any mapping before migration
4. **Report (Dry Run)** — Preview what would be imported without making changes
5. **Migrate** — Run the actual import
6. **Diagnose Threading** — Inspect threading data for any specific page
7. **Search** — Find specific comments by author or content
8. **Undo** — Remove all imported comments if needed

## TalkBack Comment Structure

TalkBack stores comments in a `bt_data` table with these key fields:

| Field | Purpose |
|-------|---------|
| `ID` | Primary key |
| `replyto` | Threading: equals `ID` for top-level, parent's `ID` for replies |
| `href` | URL path linking the comment to a page |
| `time` | Unix timestamp |
| `author` | Commenter's display name |
| `text` | Comment body (HTML) |
| `moderate` | 0 = approved |

## About TalkBack Comments

[TalkBack Comments](https://github.com/richardwilliamson/TalkBack-Comments) was a standalone PHP commenting system by Richard Williamson (OldGuy), popular with Joomla sites before modern Joomla-native comment extensions existed. If you're migrating away from TalkBack and need to preserve your comment history in JComments, this tool handles the conversion including threading, date preservation, and URL-to-article mapping.

## Need to Migrate Comments from a Different System?

If you're moving comments from another legacy commenting system into Joomla — whether it's Disqus, Komento, JA Comment, RSComments, or any custom/standalone comment tool — [Cybersalt Consulting](https://cybersalt.com) can help. This component was built as a real-world migration tool and the same approach (mapping, threading, safe undo, dry runs) can be adapted for any source system and any Joomla-native comment extension.

**Get in touch:** [support@cybersalt.com](mailto:support@cybersalt.com)

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).

## Author

**Cybersalt Consulting Ltd.**
- Website: [cybersalt.com](https://cybersalt.com)
- Email: support@cybersalt.com
