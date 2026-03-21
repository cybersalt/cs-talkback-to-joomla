# cs-talkback-to-joomla

A Joomla 5 admin component that migrates comments from the legacy TalkBack commenting system into [JComments](https://storejextensions.org).

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

- Joomla 5.x
- PHP 8.1+
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

## Building

Build the installable ZIP with 7-Zip:

```powershell
cd cs-talkback-to-joomla
& 'C:\Program Files\7-Zip\7z.exe' a -tzip com_talkbackmigrate_v1.0.0.zip talkbackmigrate.xml script.php admin
```

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).

## Author

**Cybersalt Consulting Ltd.**
- Website: [cybersalt.com](https://cybersalt.com)
- Email: support@cybersalt.com
