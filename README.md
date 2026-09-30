# FWERKOR Auto Tag

Conservative automatic tagging for WordPress posts.

## Features

- Dictionary-based canonical tags with editable aliases
- Case-insensitive matching for technical terms
- Chinese aliases without naive title segmentation
- Configurable maximum generated tag count
- Generated tags are tracked separately from manual tags
- Manual tags can be preserved across automatic re-tagging
- Categories are never copied into tags
- Bulk rebuild for published posts
- No external service and no site-specific hostname

Dictionary format:

    Canonical tag|alias1,alias2,alias3

## Requirements

WordPress 6.0+ and PHP 8.0+.

## License

GPL-2.0-or-later.
