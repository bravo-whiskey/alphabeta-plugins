# Alphabeta VideoObject Schema (MU)

Small MU-plugin that outputs Schema.org VideoObject JSON-LD for singular posts/pages. Designed to be theme-agnostic and to integrate with ACF when present.

Features
- Uses ACF fields if present (configurable mapping).
- Built-in fallback meta box for manual entry.
- Admin settings to control which post types show the meta box.
- Admin UI mapping to map existing ACF fields (dot notation), with options for image mappings (URL/array/ID).
- Visual description support (emitted as additionalProperty PropertyValue).
- Thumbnail normalization for ACF image arrays, IDs or URLs.

Installation (recommended)
1. Create a new GitHub repository (see commands below).
2. Clone the repo locally:
   git clone git@github.com:<your-user>/<repo>.git
3. Copy the plugin file to your repository root (antibody-videoobject-schema.php).
4. Commit and push:
   git add .
   git commit -m "Initial plugin"
   git push -u origin main
5. On your WordPress site, put the plugin file in `wp-content/mu-plugins/` (for must-use) or install as a normal plugin in `wp-content/plugins/` (but MU plugin will not appear in Plugins list).

ACF mapping examples
Add this to your theme's functions.php or a site plugin to provide mappings programmatically (optional):
```php
add_filter('vobj_acf_field_map', function($map){
  $map['description'] = 'work_thumbnail.description';
  $map['thumbnail_url'] = ['field' => 'work_thumbnail.image', 'image_type' => 'url'];
  return $map;
});
```

Settings
- Settings → VideoObject Schema: pick the post types where the fallback meta box appears and configure mappings.

License
MIT. See LICENSE file.
