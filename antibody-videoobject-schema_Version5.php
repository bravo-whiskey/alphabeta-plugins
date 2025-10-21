<?php
/**
 * Plugin Name: Alphabeta VideoObject Schema (MU)
 * Description: Outputs Schema.org VideoObject JSON-LD on watch pages. Works with ACF (if present) or a built-in meta box. Minimal, theme-agnostic.
 * Author: Bridget Walsh Clair
 * Version: 1.0.5
 *
 * Notes:
 * - This file is intended for mu-plugins (must-use). MU plugins do not appear in the normal Plugins list.
 * - Adds ACF mapping UI (Settings → VideoObject Schema) so you can map your existing ACF fields to the plugin keys.
 * - Adds support for ACF image-array mappings and normalizes thumbnail output (string or array).
 */

if (!defined('ABSPATH')) exit;

class VOBJ_MU_Plugin {
  const META_PREFIX = '_vobj_';
  const NONCE = 'vobj_meta_nonce';
  const OPTION_POST_TYPES = 'vobj_meta_post_types';
  const OPTION_ACF_MAP = 'vobj_acf_field_map';

  public function __construct() {
    // Frontend JSON-LD
    add_action('wp_head', [$this, 'output_jsonld'], 1);

    // Admin UI (only if ACF isn't handling these fields)
    add_action('add_meta_boxes', [$this, 'add_meta_box']);
    add_action('save_post',      [$this, 'save_meta'], 10, 2);

    // Admin settings to choose which post types show the meta box and ACF mappings
    add_action('admin_menu', [$this, 'add_settings_page']);
    add_action('admin_init', [$this, 'register_settings']);

    // Allow customization via filters
    add_filter('vobj_post_types', function ($types) {
      // Default: posts + pages. Adjust via filter in theme or another plugin.
      return $types ?: ['post', 'page'];
    });
  }

  /* -------------------------
   * Core: Build + print JSON-LD
   * ------------------------- */
  public function output_jsonld() {
    if (!is_singular()) return;
    global $post; if (!$post) return;

    $data = $this->collect_video_data($post->ID);
    if (!$data) return;

    // Minimal eligibility guard: require name + thumbnailUrl + uploadDate
    if (empty($data['name']) || empty($data['thumbnailUrl']) || empty($data['uploadDate'])) return;

    $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!$json) return;

    echo "\n<script type=\"application/ld+json\">{$json}</script>\n";
  }

  /* -------------------------
   * ACF mapping helper
   * ------------------------- */
  /**
   * Retrieve a mapped ACF value for a logical plugin key (e.g. 'description', 'thumbnail_url').
   *
   * Mapping priority:
   * 1) Saved mappings from options (Settings UI)
   * 2) Filter 'vobj_acf_field_map' (callable or dot notation string)
   * 3) Plugin fallback: 'video_{key}' ACF field (get_field)
   *
   * For image fields you can configure the mapping to return:
   * - 'url'  => return image URL (default for most cases)
   * - 'array' => return the full ACF image array
   * - 'id'   => return attachment ID
   *
   * The Settings UI saves mapping entries as either a simple string (dot notation) or an array:
   * [
   *   'field' => 'work_thumbnail.image',
   *   'image_type' => 'array' // or 'url' or 'id'
   * ]
   *
   * Themes/plugins can still provide mappings via 'vobj_acf_field_map' filter (callable or string).
   */
  private function get_mapped_acf($post_id, $key) {
    // 1) Option-driven mapping (admin UI). Stored as array option.
    $opt = get_option(self::OPTION_ACF_MAP, []);
    if (!empty($opt) && isset($opt[$key])) {
      $cfg = $opt[$key];
      // cfg may be string or array
      if (is_string($cfg) && $cfg !== '') {
        $mapping = $cfg;
        $image_type = null;
      } elseif (is_array($cfg) && !empty($cfg['field'])) {
        $mapping = $cfg['field'];
        $image_type = isset($cfg['image_type']) ? $cfg['image_type'] : null;
      } else {
        $mapping = null;
        $image_type = null;
      }

      if ($mapping) {
        // allow callable stored? options cannot store callables, so treat as string path
        return $this->get_acf_value_by_mapping($post_id, $mapping, $image_type);
      }
    }

    // 2) Filter-provided mapping (callable or string)
    $map = (array) apply_filters('vobj_acf_field_map', []);
    if (isset($map[$key])) {
      $mapping = $map[$key];
      // callable mapping
      if (is_callable($mapping)) {
        try {
          return call_user_func($mapping, $post_id);
        } catch (Throwable $e) {
          return null;
        }
      }
      // array mapping from filter (supports ['field'=>'name','image_type'=>'array'])
      if (is_array($mapping) && !empty($mapping['field'])) {
        return $this->get_acf_value_by_mapping($post_id, $mapping['field'], $mapping['image_type'] ?? null);
      }
      // string mapping
      if (is_string($mapping) && $mapping !== '') {
        return $this->get_acf_value_by_mapping($post_id, $mapping, null);
      }
    }

    // 3) fallback to conventional video_{key} ACF field if ACF exists
    if (! function_exists('get_field')) return null;
    $fallback_field = 'video_' . $key;
    $val = get_field($fallback_field, $post_id);
    return ($val === false) ? null : $val;
  }

  /**
   * Helper: given a dot-notated mapping like 'work_thumbnail.image', fetch ACF field
   * and optionally adjust for image_type (url|array|id).
   */
  private function get_acf_value_by_mapping($post_id, $mapping, $image_type = null) {
    if (!function_exists('get_field')) return null;

    $parts = explode('.', $mapping);
    $field = array_shift($parts);
    $val = get_field($field, $post_id);
    if ($val === false) return null;

    // Walk subkeys if requested (e.g., group.subfield)
    foreach ($parts as $p) {
      if (is_array($val) && array_key_exists($p, $val)) {
        $val = $val[$p];
      } else {
        // If asked for a nested key that doesn't exist, return null
        return null;
      }
    }

    // If this mapping is for an image and caller requested a specific image_type, adapt:
    if ($image_type && in_array($image_type, ['array', 'url', 'id'], true)) {
      // If the returned value is an array and image_type is 'url' or 'id' extract accordingly
      if (is_array($val)) {
        if ($image_type === 'url' && !empty($val['url'])) return $val['url'];
        if ($image_type === 'id' && !empty($val['ID'])) return $val['ID'];
        // ACF sometimes returns 'id' or an array depending on field settings; try common keys
        if ($image_type === 'id') {
          if (!empty($val['ID'])) return $val['ID'];
          if (!empty($val['id'])) return $val['id'];
        }
        if ($image_type === 'url') {
          if (!empty($val['url'])) return $val['url'];
          if (!empty($val['sizes']) && is_array($val['sizes'])) {
            // fallback to full size or first size found
            $sizes = array_values($val['sizes']);
            return !empty($sizes[0]) ? $sizes[0] : null;
          }
        }
        // If image_type is array, return the full array.
        if ($image_type === 'array') return $val;
      } else {
        // val is scalar: if image_type 'id' or 'url' and val matches format, return as-is
        if ($image_type === 'id' && is_numeric($val)) return (int)$val;
        if ($image_type === 'url' && filter_var($val, FILTER_VALIDATE_URL)) return $val;
      }
    }

    // Default: return whatever ACF gave (string, array, id, etc.)
    return $val;
  }

  /* -------------------------
   * Data collection
   * ------------------------- */
  private function collect_video_data($post_id) {
    // ACF available?
    $acf_available = function_exists('get_field');

    // Use mapped ACF values where provided; otherwise fall back to existing plugin meta or video_* ACF keys
    $acf_title         = $this->get_mapped_acf($post_id, 'title');
    $acf_desc          = $this->get_mapped_acf($post_id, 'description');
    $acf_upload        = $this->get_mapped_acf($post_id, 'upload_date');
    $acf_duration      = $this->get_mapped_acf($post_id, 'duration_iso');   // e.g., PT2M31S
    $acf_thumb         = $this->get_mapped_acf($post_id, 'thumbnail_url');
    $acf_content_url   = $this->get_mapped_acf($post_id, 'content_url');
    $acf_embed_url     = $this->get_mapped_acf($post_id, 'embed_url');
    $acf_transcript    = $this->get_mapped_acf($post_id, 'transcript_url');
    $acf_lang          = $this->get_mapped_acf($post_id, 'language');
    $acf_clips         = $this->get_mapped_acf($post_id, 'clips');          // ACF repeater (array) or null
    $acf_seekto        = $this->get_mapped_acf($post_id, 'seektoaction');   // true/false
    $acf_visual_desc   = $this->get_mapped_acf($post_id, 'visual_description');

    $has_acf_minimum = $acf_available && ($acf_title || $acf_thumb || $acf_upload);

    // 2) Fallback to this MU plugin's own meta keys
    $m = function ($key, $default=null) use ($post_id) {
      $v = get_post_meta($post_id, VOBJ_MU_Plugin::META_PREFIX.$key, true);
      return ($v !== '') ? $v : $default;
    };

    // Simple "enabled" toggle—if not set and no ACF, do nothing.
    $enabled = $m('enabled', '');
    // Build post types: load saved option (admin settings) and allow filter override
    $saved_types = get_option(self::OPTION_POST_TYPES, null);
    $post_types = apply_filters('vobj_post_types', $saved_types);
    // If $post_types is still null, the default filter will supply ['post','page'] above.
    $is_supported_type = in_array(get_post_type($post_id), (array)$post_types, true);

    if (!$has_acf_minimum && (! $enabled || !$is_supported_type)) {
      return null;
    }

    // Prefer ACF mapping when present, else meta box values / defaults
    $name        = $acf_title       ?: $m('title', get_the_title($post_id));
    $desc        = $acf_desc        ?: $m('description', wp_strip_all_tags(get_the_excerpt($post_id)));
    $uploadDate  = $acf_upload      ?: $m('upload_date', get_post_time('c', true, $post_id));
    $duration    = $acf_duration    ?: $m('duration_iso', '');
    $thumb_raw   = $acf_thumb       ?: $m('thumbnail_url', '');
    $contentUrl  = $acf_content_url ?: $m('content_url', '');
    $embedUrl    = $acf_embed_url   ?: $m('embed_url', '');
    $transcript  = $acf_transcript  ?: $m('transcript_url', '');
    $lang        = $acf_lang        ?: $m('language', 'en');

    // New: visual description: prefer mapped ACF, else meta
    $visualDescription = $acf_visual_desc ?: $m('visual_description', '');

    // Normalize thumbnail handling (accept ACF array, attachment ID, URL, or comma list)
    $thumbnailUrl = $this->normalize_thumbnail($thumb_raw);

    // Clips:
    $clips = [];
    if ($acf_available && is_array($acf_clips) && $acf_clips) {
      foreach ($acf_clips as $row) {
        $clips[] = [
          "@type"       => "Clip",
          "name"        => isset($row['clip_name']) ? $row['clip_name'] : (isset($row['name']) ? $row['name'] : ''),
          "startOffset" => isset($row['start']) ? (int)$row['start'] : (isset($row['startOffset']) ? (int)$row['startOffset'] : 0),
          "endOffset"   => isset($row['end']) ? (int)$row['end'] : (isset($row['endOffset']) ? (int)$row['endOffset'] : null),
          "url"         => !empty($row['url']) ? $row['url'] : (get_permalink($post_id) . '?t=' . (int)($row['start'] ?? $row['startOffset'] ?? 0)),
        ];
      }
    } else {
      // Our meta box stores clips as JSON array
      $clips_json = $m('clips_json', '');
      if ($clips_json) {
        $arr = json_decode($clips_json, true);
        if (is_array($arr)) {
          foreach ($arr as $row) {
            $clips[] = [
              "@type"       => "Clip",
              "name"        => isset($row['name']) ? $row['name'] : '',
              "startOffset" => isset($row['startOffset']) ? (int)$row['startOffset'] : 0,
              "endOffset"   => isset($row['endOffset']) ? (int)$row['endOffset'] : null,
              "url"         => !empty($row['url']) ? $row['url'] : (get_permalink($post_id) . '?t=' . (int)($row['startOffset'] ?? 0)),
            ];
          }
        }
      }
    }

    // SeekToAction toggle:
    $seekTo = $acf_available ? (bool)$acf_seekto : (bool)$m('seektoaction', false);

    // Allow last-minute mapping/augmentation
    $payload = [
      "@context"        => "https://schema.org",
      "@type"           => "VideoObject",
      "name"            => $name,
      "description"     => wp_strip_all_tags((string)$desc),
      "thumbnailUrl"    => $thumbnailUrl,
      "uploadDate"      => (string)$uploadDate,
      "duration"        => $duration ?: null,
      "inLanguage"      => $lang ?: "en",
      "embedUrl"        => $embedUrl ?: null,
      "contentUrl"      => $contentUrl ?: null,
      "publisher"       => [
        "@type" => "Organization",
        "name"  => get_bloginfo('name'),
        "logo"  => [
          "@type" => "ImageObject",
          "url"   => get_site_icon_url() ?: (get_theme_file_uri('screenshot.png') ?: ''),
        ],
      ],
      "mainEntityOfPage"=> get_permalink($post_id),
    ];

    if (!empty($transcript)) {
      // Accept either a URL string or a small inline transcript
      // If it looks like a URL, pass through; else treat as text.
      if (filter_var($transcript, FILTER_VALIDATE_URL)) {
        $payload["transcript"] = $transcript;
      } else {
        $payload["transcript"] = wp_strip_all_tags($transcript);
      }
    }

    if (!empty($clips)) {
      $payload["hasPart"] = $clips;
    }

    if ($seekTo) {
      $payload["potentialAction"] = [
        "@type" => "SeekToAction",
        "target" => get_permalink($post_id) . "?t={seek_to_second_number}",
        "startOffset-input" => "required name=seek_to_second_number"
      ];
    }

    // New: include visual description as an additionalProperty (PropertyValue)
    if (!empty($visualDescription)) {
      $payload['additionalProperty'] = [
        [
          "@type" => "PropertyValue",
          "name"  => "visualDescription",
          "value" => wp_strip_all_tags($visualDescription),
        ],
      ];
    }

    // Clean null/empty to keep output tight
    $payload = $this->array_prune($payload);

    // Final hook for custom augmentation (e.g., about/mentions/sameAs)
    $payload = apply_filters('vobj_schema_payload', $payload, $post_id);

    return $payload;
  }

  /**
   * Normalize a thumbnail field into either a string URL or an array of URLs.
   * Accepts: attachment ID, ACF image array (with ['url'] or sizes), or raw URL(s).
   */
  private function normalize_thumbnail($thumb) {
    if (empty($thumb)) return null;

    // If ACF returns an array (image object)
    if (is_array($thumb)) {
      // If it looks like ACF image array with 'url'
      if (!empty($thumb['url']) && filter_var($thumb['url'], FILTER_VALIDATE_URL)) {
        return (string)$thumb['url'];
      }
      // If ACF image returns sizes array, build a map of sizes
      if (!empty($thumb['sizes']) && is_array($thumb['sizes'])) {
        // prefer 'full' if present, else first
        if (!empty($thumb['sizes']['full'])) return $thumb['sizes']['full'];
        $sizes = array_values($thumb['sizes']);
        return !empty($sizes[0]) ? $sizes[0] : null;
      }
      // If array appears to be a list of image items
      $urls = [];
      foreach ($thumb as $item) {
        if (is_array($item) && !empty($item['url']) && filter_var($item['url'], FILTER_VALIDATE_URL)) $urls[] = $item['url'];
        elseif (is_string($item) && filter_var($item, FILTER_VALIDATE_URL)) $urls[] = $item;
      }
      if (count($urls) === 1) return $urls[0];
      if ($urls) return array_values(array_unique($urls));
      return null;
    }

    // If thumbnail is an attachment ID (numeric string or int)
    if (is_numeric($thumb)) {
      $url = wp_get_attachment_url((int)$thumb);
      return $url ?: null;
    }

    // If it's a comma-separated list of URLs, normalize to array or single url
    if (is_string($thumb)) {
      $thumb = trim($thumb);
      if (strpos($thumb, ',') !== false) {
        $parts = array_map('trim', explode(',', $thumb));
        $urls = array_filter($parts, function($u){ return filter_var($u, FILTER_VALIDATE_URL); });
        if (count($urls) === 1) return array_values($urls)[0];
        return $urls ?: null;
      }
      // Single string URL
      return filter_var($thumb, FILTER_VALIDATE_URL) ? $thumb : null;
    }

    return null;
  }

  private function array_prune($arr) {
    if (!is_array($arr)) return $arr;
    foreach ($arr as $k => $v) {
      if (is_array($v)) {
        $arr[$k] = $this->array_prune($v);
        // Remove empty arrays
        if ($arr[$k] === [] || $arr[$k] === null) {
          unset($arr[$k]);
          continue;
        }
      } else {
        if ($v === '' || $v === null) {
          unset($arr[$k]);
        }
      }
    }
    return $arr;
  }

  /* -------------------------
   * Admin settings (choose post types + ACF mapping)
   * ------------------------- */
  public function add_settings_page() {
    add_options_page(
      __('VideoObject Schema', 'vobj'),
      __('VideoObject Schema', 'vobj'),
      'manage_options',
      'vobj-settings',
      [$this, 'render_settings_page']
    );
  }

  public function register_settings() {
    register_setting('vobj_settings_group', self::OPTION_POST_TYPES, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_post_types_option'],
      'default' => ['post', 'page'],
    ]);

    register_setting('vobj_settings_group', self::OPTION_ACF_MAP, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_acf_map_option'],
      'default' => [],
    ]);

    add_settings_section(
      'vobj_main_section',
      __('VideoObject Schema settings', 'vobj'),
      function() {
        echo '<p>' . esc_html__('General settings. Choose which post types should show the VideoObject meta box and optionally map your ACF fields to the plugin keys.', 'vobj') . '</p>';
      },
      'vobj_settings'
    );

    add_settings_field(
      self::OPTION_POST_TYPES,
      __('Show meta box on', 'vobj'),
      [$this, 'render_post_types_field'],
      'vobj_settings',
      'vobj_main_section'
    );

    add_settings_field(
      self::OPTION_ACF_MAP,
      __('ACF field mappings', 'vobj'),
      [$this, 'render_acf_mappings_field'],
      'vobj_settings',
      'vobj_main_section'
    );
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) {
      wp_die(__('Insufficient permissions', 'vobj'));
    }
    ?>
    <div class="wrap">
      <h1><?php _e('VideoObject Schema', 'vobj'); ?></h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('vobj_settings_group');
          do_settings_sections('vobj_settings');
          submit_button();
        ?>
      </form>
      <p class="description"><?php _e('Mapping examples: use dot notation to pick a sub-field from a group (e.g. work_thumbnail.description). For image fields you can choose whether the mapping returns the image URL, the attachment ID, or the raw ACF image array.', 'vobj'); ?></p>
    </div>
    <?php
  }

  public function render_post_types_field() {
    $all = get_post_types(['public' => true], 'objects');
    // Exclude attachments
    unset($all['attachment']);
    $selected = (array) get_option(self::OPTION_POST_TYPES, ['post', 'page']);

    foreach ($all as $pt => $obj) {
      $checked = in_array($pt, $selected, true) ? 'checked' : '';
      printf(
        '<label style="display:block;margin:2px 0;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
        esc_attr(self::OPTION_POST_TYPES),
        esc_attr($pt),
        $checked,
        esc_html($obj->labels->singular_name ?? $pt)
      );
    }

    // If none selected, show a helpful message
    if (empty($all)) {
      echo '<p class="description">' . esc_html__('No public post types found.', 'vobj') . '</p>';
    }
  }

  /**
   * Render a simple mapping UI for common keys.
   * Stores option as associative array: key => string or key => ['field'=>..., 'image_type'=>...]
   */
  public function render_acf_mappings_field() {
    $defaults = [
      'title' => '',
      'description' => '',
      'thumbnail_url' => '',
      'visual_description' => '',
      'duration_iso' => '',
      'upload_date' => '',
    ];
    $saved = (array) get_option(self::OPTION_ACF_MAP, []);
    // ensure keys exist
    $saved = array_merge($defaults, $saved);

    // Render rows
    ?>
    <table class="form-table" style="max-width:800px;">
      <tr>
        <th style="width:220px"><?php _e('Plugin key', 'vobj'); ?></th>
        <th><?php _e('ACF mapping (dot notation: field.subfield)', 'vobj'); ?></th>
        <th style="width:220px"><?php _e('Image options (thumbnail only)', 'vobj'); ?></th>
      </tr>
      <?php foreach ($saved as $key => $val): 
        $value = is_array($val) && isset($val['field']) ? $val['field'] : (is_string($val) ? $val : '');
        $image_type = is_array($val) && isset($val['image_type']) ? $val['image_type'] : 'url';
      ?>
        <tr>
          <td><strong><?php echo esc_html($key); ?></strong><br><span class="description"><?php echo $this->key_label_hint($key); ?></span></td>
          <td>
            <input type="text" name="<?php echo esc_attr(self::OPTION_ACF_MAP); ?>[<?php echo esc_attr($key); ?>][field]" value="<?php echo esc_attr($value); ?>" class="regular-text">
            <p class="description"><?php _e('Example: work_thumbnail.description or gallery.0.caption', 'vobj'); ?></p>
          </td>
          <td>
            <?php if ($key === 'thumbnail_url'): ?>
              <select name="<?php echo esc_attr(self::OPTION_ACF_MAP); ?>[<?php echo esc_attr($key); ?>][image_type]">
                <option value="url" <?php selected($image_type, 'url'); ?>><?php _e('URL (extract image URL)', 'vobj'); ?></option>
                <option value="array" <?php selected($image_type, 'array'); ?>><?php _e('ACF image array (return raw array)', 'vobj'); ?></option>
                <option value="id" <?php selected($image_type, 'id'); ?>><?php _e('Attachment ID', 'vobj'); ?></option>
              </select>
              <p class="description"><?php _e('Choose how your ACF image field should be interpreted.', 'vobj'); ?></p>
            <?php else: ?>
              <span class="description">&mdash;</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php
  }

  private function key_label_hint($key) {
    $hints = [
      'title' => __('Defaults to the post title when not mapped', 'vobj'),
      'description' => __('Maps to the post excerpt or the ACF field you choose', 'vobj'),
      'thumbnail_url' => __('ACF image field or attachment ID', 'vobj'),
      'visual_description' => __('Optional longer visual description field', 'vobj'),
      'duration_iso' => __('ISO 8601 duration (PT2M31S)', 'vobj'),
      'upload_date' => __('ISO 8601 datetime (2025-10-21T09:00:00+11:00)', 'vobj'),
    ];
    return $hints[$key] ?? '';
  }

  public function sanitize_post_types_option($input) {
    if (!is_array($input)) return [];
    $valid = get_post_types(['public' => true]);
    $out = [];
    foreach ($input as $pt) {
      if (in_array($pt, $valid, true) && $pt !== 'attachment') $out[] = sanitize_key($pt);
    }
    // Ensure at least one default to prevent accidental disabling; default to post+page if empty
    if (empty($out)) return ['post', 'page'];
    return array_values(array_unique($out));
  }

  public function sanitize_acf_map_option($input) {
    if (!is_array($input)) return [];
    $allowed_keys = ['title','description','thumbnail_url','visual_description','duration_iso','upload_date','content_url','embed_url','transcript_url','language','clips'];
    $out = [];
    foreach ($input as $key => $cfg) {
      if (!in_array($key, $allowed_keys, true)) continue;
      if (!is_array($cfg)) continue;
      $field = isset($cfg['field']) ? sanitize_text_field($cfg['field']) : '';
      if ($field === '') {
        // empty mapping -> treat as not set
        continue;
      }
      $image_type = isset($cfg['image_type']) ? sanitize_key($cfg['image_type']) : '';
      if ($key === 'thumbnail_url') {
        if (!in_array($image_type, ['url','array','id'], true)) $image_type = 'url';
      } else {
        $image_type = null;
      }
      $out[$key] = $field;
      if ($image_type) {
        $out[$key] = ['field' => $field, 'image_type' => $image_type];
      }
    }
    return $out;
  }

  /* -------------------------
   * Admin meta box (fallback UI)
   * ------------------------- */
  public function add_meta_box() {
    // Load saved types, then allow filter override.
    $saved_types = get_option(self::OPTION_POST_TYPES, null);
    $types = apply_filters('vobj_post_types', $saved_types);
    // Guarantee an array and fallback to defaults via filter earlier
    foreach ((array)$types as $pt) {
      add_meta_box(
        'vobj_meta',
        __('VideoObject Schema', 'vobj'),
        [$this, 'render_meta_box'],
        $pt,
        'normal',
        'default'
      );
    }
  }

  public function render_meta_box($post) {
    // If ACF provides fields, many users will ignore this box (that's fine).
    wp_nonce_field(self::NONCE, self::NONCE);
    $f = function ($key, $default='') use ($post) {
      return esc_attr(get_post_meta($post->ID, self::META_PREFIX.$key, true) ?: $default);
    };
    ?>
    <p><label><input type="checkbox" name="vobj_enabled" value="1" <?php checked(get_post_meta($post->ID, self::META_PREFIX.'enabled', true), '1'); ?>> <?php _e('Enable VideoObject on this page', 'vobj'); ?></label></p>

    <table class="form-table">
      <tr><th><label><?php _e('Title', 'vobj'); ?></label></th><td><input type="text" class="widefat" name="vobj_title" value="<?php echo $f('title'); ?>"></td></tr>
      <tr><th><label><?php _e('Description', 'vobj'); ?></label></th><td><textarea class="widefat" name="vobj_description" rows="3"><?php echo esc_textarea(get_post_meta($post->ID, self::META_PREFIX.'description', true)); ?></textarea></td></tr>
      <tr><th><label><?php _e('Upload Date (ISO 8601)', 'vobj'); ?></label></th><td><input type="text" class="widefat" placeholder="2025-10-21T09:00:00+11:00" name="vobj_upload_date" value="<?php echo $f('upload_date'); ?>"></td></tr>
      <tr><th><label><?php _e('Duration (ISO 8601)', 'vobj'); ?></label></th><td><input type="text" class="widefat" placeholder="PT2M31S" name="vobj_duration_iso" value="<?php echo $f('duration_iso'); ?>"></td></tr>
      <tr><th><label><?php _e('Thumbnail URL', 'vobj'); ?></label></th><td><input type="url" class="widefat" name="vobj_thumbnail_url" value="<?php echo $f('thumbnail_url'); ?>"></td></tr>
      <tr><th><label><?php _e('Content URL (mp4/HLS)', 'vobj'); ?></label></th><td><input type="url" class="widefat" name="vobj_content_url" value="<?php echo $f('content_url'); ?>"></td></tr>
      <tr><th><label><?php _e('Embed URL (YouTube/Vimeo)', 'vobj'); ?></label></th><td><input type="url" class="widefat" name="vobj_embed_url" value="<?php echo $f('embed_url'); ?>"></td></tr>
      <tr><th><label><?php _e('Transcript (URL or text)', 'vobj'); ?></label></th><td><textarea class="widefat" name="vobj_transcript_url" rows="2"><?php echo esc_textarea(get_post_meta($post->ID, self::META_PREFIX.'transcript_url', true)); ?></textarea></td></tr>
      <tr><th><label><?php _e('Language', 'vobj'); ?></label></th><td><input type="text" class="regular-text" placeholder="en or en-AU" name="vobj_language" value="<?php echo $f('language','en'); ?>"></td></tr>
      <tr><th><label><?php _e('SeekToAction (auto key moments)', 'vobj'); ?></label></th><td><label><input type="checkbox" name="vobj_seektoaction" value="1" <?php checked(get_post_meta($post->ID, self::META_PREFIX.'seektoaction', true), '1'); ?>> <?php _e('Enable', 'vobj'); ?></label></td></tr>
      <tr>
        <th><label><?php _e('Clips (JSON)', 'vobj'); ?></label></th>
        <td>
          <textarea class="widefat" name="vobj_clips_json" rows="5" placeholder='[{"name":"Intro","startOffset":0,"endOffset":29,"url":""},{"name":"Design breakdown","startOffset":30}]'><?php echo esc_textarea(get_post_meta($post->ID, self::META_PREFIX.'clips_json', true)); ?></textarea>
          <p class="description"><?php _e('Optional chapter markers. Each item: <code>name</code>, <code>startOffset</code> (sec), optional <code>endOffset</code>, optional <code>url</code>.', 'vobj'); ?></p>
        </td>
      </tr>

      <!-- New: Visual description -->
      <tr>
        <th><label><?php _e('Visual description', 'vobj'); ?></label></th>
        <td>
          <textarea class="widefat" name="vobj_visual_description" rows="4" placeholder="<?php echo esc_attr('Describe visually what happens in this piece (no transcript required).'); ?>"><?php echo esc_textarea(get_post_meta($post->ID, self::META_PREFIX.'visual_description', true)); ?></textarea>
          <p class="description"><?php _e('Optional: a short/long visual description for work where transcripts are not available. This will be emitted as a PropertyValue "visualDescription" in the schema.', 'vobj'); ?></p>
        </td>
      </tr>

    </table>
    <?php
  }

  public function save_meta($post_id, $post) {
    if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $fields = [
      'enabled'       => isset($_POST['vobj_enabled']) ? '1' : '',
      'title'         => sanitize_text_field($_POST['vobj_title'] ?? ''),
      'description'   => wp_kses_post($_POST['vobj_description'] ?? ''),
      'upload_date'   => sanitize_text_field($_POST['vobj_upload_date'] ?? ''),
      'duration_iso'  => sanitize_text_field($_POST['vobj_duration_iso'] ?? ''),
      'thumbnail_url' => esc_url_raw($_POST['vobj_thumbnail_url'] ?? ''),
      'content_url'   => esc_url_raw($_POST['vobj_content_url'] ?? ''),
      'embed_url'     => esc_url_raw($_POST['vobj_embed_url'] ?? ''),
      'transcript_url'=> trim($_POST['vobj_transcript_url'] ?? ''),
      'language'      => sanitize_text_field($_POST['vobj_language'] ?? 'en'),
      'seektoaction'  => isset($_POST['vobj_seektoaction']) ? '1' : '',
      'clips_json'    => $this->sanitize_json($_POST['vobj_clips_json'] ?? ''),

      // New: visual description (store limited HTML, but output will be stripped)
      'visual_description' => wp_kses_post($_POST['vobj_visual_description'] ?? ''),
    ];
    foreach ($fields as $key => $val) {
      if ($val === '' || $val === null) {
        delete_post_meta($post_id, self::META_PREFIX.$key);
      } else {
        update_post_meta($post_id, self::META_PREFIX.$key, $val);
      }
    }
  }

  private function sanitize_json($json) {
    $json = trim((string)$json);
    if ($json === '') return '';
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return '';
    // Basic per-item cleanup
    $clean = [];
    foreach ($decoded as $row) {
      if (!is_array($row)) continue;
      $clean[] = [
        'name'        => isset($row['name']) ? sanitize_text_field($row['name']) : '',
        'startOffset' => isset($row['startOffset']) ? (int)$row['startOffset'] : 0,
        'endOffset'   => isset($row['endOffset']) ? (int)$row['endOffset'] : null,
        'url'         => isset($row['url']) ? esc_url_raw($row['url']) : '',
      ];
    }
    return wp_json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
}

new VOBJ_MU_Plugin();
