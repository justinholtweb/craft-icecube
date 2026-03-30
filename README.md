# Icecube for Craft CMS

Lock entries, assets, categories, and globals behind a password in Craft CMS.

## Requirements

- Craft CMS 5.3.0+
- PHP 8.2+

## Installation

```bash
composer require justinholtweb/craft-icecube
php craft plugin/install icecube
```

## Features

- Lock individual entries, assets, categories, and global sets
- Protect against editing, deleting, or both
- Per-lock passwords or a single master password
- Configurable unlock session duration (1-1440 minutes)
- Optional notes on each lock explaining why it's locked
- Admin bypass setting
- Granular user permissions

## Configuration

Visit **Settings > Icecube** in the control panel to configure:

- **Master Password** -- fallback password when no per-lock password is set
- **Admins Bypass** -- allow admin users to skip all locks automatically
- **Element Types** -- enable/disable locking for entries, assets, categories, and globals individually
- **Unlock TTL** -- how long an unlock session stays valid (default: 10 minutes)

## Permissions

Icecube registers three permissions under **Settings > Users > Permissions**:

| Permission | Description |
|---|---|
| **Manage locks** | Create, edit, and delete locks |
| **Bypass all locks** | Skip locks entirely without entering a password |
| **Unlock locked content** | Enter a password to unlock content |

## How It Works

1. Create a lock targeting a specific entry, asset, category, or global set
2. Choose whether to lock editing, deleting, or both
3. Optionally set a per-lock password and add notes
4. When a user tries to save or delete a locked element, they'll see an unlock modal prompting for the password
5. A successful unlock grants a time-limited session (configurable via Unlock TTL)

## License

Craft License -- see [LICENSE.md](LICENSE.md).
