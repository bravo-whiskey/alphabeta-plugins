<?php
/**
 * Plugin Name: Alphabeta VideoObject Schema (MU)
 * Description: Outputs Schema.org VideoObject JSON-LD on watch pages. Works with ACF (if present) or a built-in meta box. Minimal, theme-agnostic.
 * Author: Bridget Walsh Clair
 * Version: 1.0.6
 *
 * Notes:
 * - This file is intended for mu-plugins (must-use). MU plugins do not appear in the normal Plugins list.
 * - Adds ACF mapping UI (Settings → VideoObject Schema) so you can map your existing ACF fields to the plugin keys.
 * - Adds support for ACF image-array mappings and normalizes thumbnail output (string or array).
 * - Added handling for:
 *     - oEmbed ACF field (work_video) -> embedUrl (normalizes YouTube/Vimeo to embed endpoints)
 *     - HTML5 file ACF file fields returning attachment IDs (html5_video_mp4, html5_video_webm) -> contentUrl / MediaObject
 *     - Thumbnail selection: supports the special mapping value "featured_image" or an ACF image field (main_title_image)
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
        // special-case: allow the literal token 'featured_image' to signal use of the WP featured image
        if ($mapping === 'featured_image') {
          return 'featured_image';
        }
        // options cannot store callables, so treat mapping as a string path
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
        // allow special featured_image token via filter as well
        if ($mapping === 'featured_image') return 'featured_image';
        return $this->get_acf_value_by_mapping($post_id, $mapping, null);
      }
    }

    // 3) fallback to conventional video_{key} ACF field if ACF exists
    if (! function_exists('get_field')) return null;
    $fallback_field = 'video_' . $key;
    $val = get_field($fallback_field, $post_id);
    return ($val === false) ? null : $val;
  }

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
        return null;
      }
    }

    // If this mapping is for an image and caller requested a specific image_type, adapt:
    if ($image_type && in_array($image_type, ['array', 'url', 'id'], true)) {
      if (is_array($val)) {
        if ($image_type === 'url' && !empty($val['url'])) return $val['url'];
        if ($image_type === 'id' && !empty($val['ID'])) return $val['ID'];
        if ($image_type === 'id') {
          if (!empty($val['ID'])) return $val['ID'];
          if (!empty($val['id'])) return $val['id'];
        }
        if ($image_type === 'url') {
          if (!empty($val['url'])) return $val['url'];
          if (!empty($val['sizes']) && is_array($val['sizes'])) {
            $sizes = array_values($val['sizes']);
            return !empty($sizes[0]) ? $sizes[0] : null;
          }
        }
        if ($image_type === 'array') return $val;
      } else {
        if ($image_type === 'id' && is_numeric($val)) return (int)$val;
        if ($image_type === 'url' && filter_var($val, FILTER_VALIDATE_URL)) return $val;
      }
    }

    return $val;
  }

  /* -------------------------
   * Helpers: normalize oEmbed and attachment -> schema objects
   * ------------------------- */
  private function normalize_oembed_to_embed_url($url) {
    if (empty($url)) return null;
    $url = trim($url);
    // YouTube
    if (preg_match('#(?:youtube\.com|youtu\.be)#i', $url)) {
      // extract ID
      if (preg_match('#(?:v=|/embed/|youtu\.be/)([A-Za-z0-9_-]{6,})#', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
      }
      return $url;
    }
    // Vimeo
    if (preg_match('#vimeo\.com#i', $url)) {
      if (preg_match('#vimeo\.com/(?:.*?/)?([0-9]+)#', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
      }
      return $url;
    }
    // Default: return original
    return $url;
  }

  private function file_to_mediaobject($attachment_id) {
    if (!$attachment_id) return null;
    $id = (int)$attachment_id;
    $url = wp_get_attachment_url($id);
    if (!$url) return null;
    $mime = get_post_mime_type($id) ?: '';
    $path = get_attached_file($id);
    $size = ($path && file_exists($path)) ? filesize($path) : null;

    $mo = [
      "@type" => "MediaObject",
      "contentUrl" => $url,
    ];
    if ($mime) $mo['encodingFormat'] = $mime;
    if ($size) $mo['contentSize'] = (string)$size;
    // Optionally include duration/width/height if available in metadata (not always present)
    $meta = wp_get_attachment_metadata($id);
    if (!empty($meta['width'])) $mo['width'] = (int)$meta['width'];
    if (!empty($meta['height'])) $mo['height'] = (int)$meta['height'];
    return $mo;
  }

  private function attachment_to_imageobject($attachment_id) {
    if (!$attachment_id) return null;
    $id = (int)$attachment_id;
    $url = wp_get_attachment_url($id);
    if (!$url) return null;
    $meta = wp_get_attachment_metadata($id);
    $img = ["@type" => "ImageObject", "url" => $url];
    if (!empty($meta['width'])) $img['width'] = (int)$meta['width'];
    if (!empty($meta['height'])) $img['height'] = (int)$meta['height'];
    $mime = get_post_mime_type($id);
    if ($mime) $img['encodingFormat'] = $mime;
    return $img;
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
    $acf_thumb         = $this->get_mapped_acf($post_id, 'thumbnail_url');  // may be 'featured_image' token or attachment id/array/url
    $acf_content_url   = $this->get_mapped_acf($post_id, 'content_url');    // legacy single content_url mapping
    $acf_embed_url     = $this->get_mapped_acf($post_id, 'embed_url');      // mapped to work_video (oembed)
    $acf_transcript    = $this->get_mapped_acf($post_id, 'transcript_url');
    $acf_lang          = $this->get_mapped_acf($post_id, 'language');
    $acf_clips         = $this->get_mapped_acf($post_id, 'clips');          // ACF repeater (array) or null
    $acf_seekto        = $this->get_mapped_acf($post_id, 'seektoaction');   // true/false
    $acf_visual_desc   = $this->get_mapped_acf($post_id, 'visual_description');

    // New explicit mappings for HTML5 files (attachment IDs)
    $acf_mp4_id        = $this->get_mapped_acf($post_id, 'html5_mp4');      // expected attachment ID
    $acf_webm_id       = $this->get_mapped_acf($post_id, 'html5_webm');     // expected attachment ID

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

    // Thumbnail resolution: support special token 'featured_image'
    if ($thumb_raw === 'featured_image') {
      $feat_id = get_post_thumbnail_id($post_id);
      $thumb_raw = $feat_id ? (int)$feat_id : '';
    }

    // Normalize thumbnail handling (accept ACF array, attachment ID, URL, or comma list)
    $thumbnailUrl = $this->normalize_thumbnail($thumb_raw);
    // If the ACF returned an attachment id for a thumbnail, normalize_thumbnail returns a URL string.
    // But if attachment id used, it's handy to emit ImageObject with dims; try to get imageobject from ID
    if (is_numeric($thumb_raw)) {
      $io = $this->attachment_to_imageobject((int)$thumb_raw);
      if ($io) $thumbnailUrl = $io;
    }

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

    // Build payload base
    $payload = [
      "@context"        => "https://schema.org",
      "@type"           => "VideoObject",
      "name"            => (string)$name,
      "description"     => wp_strip_all_tags((string)$desc),
      "thumbnailUrl"    => $thumbnailUrl,
      "uploadDate"      => (string)$uploadDate,
      "duration"        => $duration ?: null,
      "inLanguage"      => $lang ?: "en",
      "embedUrl"        => null,
      "contentUrl"      => null,
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

    // Handle embedUrl (oEmbed): prefer provider canonical embed endpoint
    if (!empty($embedUrl)) {
      $norm = $this->normalize_oembed_to_embed_url($embedUrl);
      if ($norm) $payload['embedUrl'] = $norm;
      else $payload['embedUrl'] = $embedUrl;
    }

    // Handle html5 mp4/webm (ACF file fields returning attachment IDs)
    $media_sources = [];
    if (!empty($acf_mp4_id) && is_numeric($acf_mp4_id)) {
      $mo = $this->file_to_mediaobject($acf_mp4_id);
      if ($mo) $media_sources[] = $mo;
    }
    if (!empty($acf_webm_id) && is_numeric($acf_webm_id)) {
      $mo = $this->file_to_mediaobject($acf_webm_id);
      if ($mo) $media_sources[] = $mo;
    }

    // Legacy single contentUrl mapping support (if mapping pointed to a direct URL)
    if (empty($media_sources) && !empty($contentUrl) && filter_var($contentUrl, FILTER_VALIDATE_URL)) {
      $payload['contentUrl'] = $contentUrl;
    }

    // If we have media sources from attachments, prefer to emit a single contentUrl when there is one,
    // or attach them to hasPart as MediaObject entries when there are many.
    if (count($media_sources) === 1) {
      $m0 = $media_sources[0];
      if (!empty($m0['contentUrl'])) $payload['contentUrl'] = $m0['contentUrl'];
      if (!empty($m0['encodingFormat'])) $payload['encodingFormat'] = $m0['encodingFormat'];
      if (!empty($m0['contentSize'])) $payload['contentSize'] = $m0['contentSize'];
      // also add as hasPart for discoverability
      $payload['hasPart'] = array_merge($clips, [$m0]);
    } elseif (count($media_sources) > 1) {
      // merge clips and media source objects in hasPart
      $payload['hasPart'] = array_merge($clips, $media_sources);
    } else {
      // no media objects added; leave hasPart if clips exist
      if (!empty($clips)) $payload['hasPart'] = $clips;
    }

    if (!empty($transcript)) {
      if (filter_var($transcript, FILTER_VALIDATE_URL)) {
        $payload["transcript"] = $transcript;
      } else {
        $payload["transcript"] = wp_strip_all_tags($transcript);
      }
    }

    if ($seekTo) {
      $payload["potentialAction"] = [
        "@type" => "SeekToAction",
        "target" => get_permalink($post_id) . "?t={seek_to_second_number}",
        "startOffset-input" => "required name=seek_to_second_number"
      ];
    }

    // Visual description as additionalProperty
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
      if (!empty($thumb['url']) && filter_var($thumb['url'], FILTER_VALIDATE_URL)) {
        return (string)$thumb['url'];
      }
      if (!empty($thumb['sizes']) && is_array($thumb['sizes'])) {
        if (!empty($thumb['sizes']['full'])) return $thumb['sizes']['full'];
        $sizes = array_values($thumb['sizes']);
        return !empty($sizes[0]) ? $sizes[0] : null;
      }
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
      return filter_var($thumb, FILTER_VALIDATE_URL) ? $thumb : null;
    }

    return null;
  }

  private function array_prune($arr) {
    if (!is_array($arr)) return $arr;
    foreach ($arr as $k => $v) {
      if (is_array($v)) {
        $arr[$k] = $this->array_prune($v);
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
      <p class="description"><?php _e('Mapping examples: use dot notation to pick a sub-field from a group (e.g. work_thumbnail.description). For image fields you can choose whether the mapping returns the featured image token "featured_image", an ACF image field (ID/array), or URL. For HTML5 files, map to ACF File fields that return attachment IDs (recommended).', 'vobj'); ?></p>
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
      'thumbnail_url' => '',          // use 'featured_image' to choose WP featured image
      'main_title_image' => '',       // optional alternative image (ACF returns ID)
      'visual_description' => '',
      'duration_iso' => '',
      'upload_date' => '',
      'embed_url' => '',              // e.g. work_video (oembed)
      'html5_mp4' => '',              // new: ACF file field returning attachment ID
      'html5_webm' => '',             // new: ACF file field returning attachment ID
    ];
    $saved = (array) get_option(self::OPTION_ACF_MAP, []);
    // ensure keys exist
    $saved = array_merge($defaults, $saved);

    // Render rows
    ?>
    <table class="form-table" style="max-width:900px;">
      <tr>
        <th style="width:220px"><?php _e('Plugin key', 'vobj'); ?></th>
        <th><?php _e('ACF mapping (dot notation: field.subfield or special token "featured_image")', 'vobj'); ?></th>
        <th style="width:260px"><?php _e('Image options (thumbnail only)', 'vobj'); ?></th>
      </tr>
      <?php foreach ($saved as $key => $val):
        $value = is_array($val) && isset($val['field']) ? $val['field'] : (is_string($val) ? $val : '');
        $image_type = is_array($val) && isset($val['image_type']) ? $val['image_type'] : 'url';
      ?>
        <tr>
          <td><strong><?php echo esc_html($key); ?></strong><br><span class="description"><?php echo $this->key_label_hint($key); ?></span></td>
          <td>
            <input type="text" name="<?php echo esc_attr(self::OPTION_ACF_MAP); ?>[<?php echo esc_attr($key); ?>][field]" value="<?php echo esc_attr($value); ?>" class="regular-text">
            <p class="description"><?php _e('Example: work_thumbnail.description or gallery.0.caption — or enter featured_image to use the WP featured image', 'vobj'); ?></p>
          </td>
          <td>
            <?php if ($key === 'thumbnail_url' || $key === 'main_title_image'): ?>
              <select name="<?php echo esc_attr(self::OPTION_ACF_MAP); ?>[<?php echo esc_attr($key); ?>][image_type]">
                <option value="url" <?php selected($image_type, 'url'); ?>><?php _e('URL (extract image URL)', 'vobj'); ?></option>
                <option value="array" <?php selected($image_type, 'array'); ?>><?php _e('ACF image array (return raw array)', 'vobj'); ?></option>
                <option value="id" <?php selected($image_type, 'id'); ?>><?php _e('Attachment ID', 'vobj'); ?></option>
              </select>
              <p class="description"><?php _e('Choose how your ACF image field should be interpreted (if used instead of featured image).', 'vobj'); ?></p>
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
      'thumbnail_url' => __('Use featured_image to use the WP Featured Image, or map an ACF image field', 'vobj'),
      'main_title_image' => __('Optional title-lockup image (ACF image ID) — not publisher logo', 'vobj'),
      'visual_description' => __('Optional longer visual description field', 'vobj'),
      'duration_iso' => __('ISO 8601 duration (PT2M31S)', 'vobj'),
      'upload_date' => __('ISO 8601 datetime (2025-10-21T09:00:00+11:00)', 'vobj'),
      'embed_url' => __('ACF oEmbed field (YouTube/Vimeo). Map your work_video oEmbed field here', 'vobj'),
      'html5_mp4' => __('ACF File field (attachment ID) for MP4 source', 'vobj'),
      'html5_webm' => __('ACF File field (attachment ID) for WebM source', 'vobj'),
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
    if (empty($out)) return ['post', 'page'];
    return array_values(array_unique($out));
  }

  public function sanitize_acf_map_option($input) {
    if (!is_array($input)) return [];
    $allowed_keys = ['title','description','thumbnail_url','main_title_image','visual_description','duration_iso','upload_date','content_url','embed_url','transcript_url','language','clips','html5_mp4','html5_webm'];
    $out = [];
    foreach ($input as $key => $cfg) {
      if (!in_array($key, $allowed_keys, true)) continue;
      if (!is_array($cfg)) continue;
      $field = isset($cfg['field']) ? sanitize_text_field($cfg['field']) : '';
      if ($field === '') continue;
      // Accept the special token 'featured_image' for thumbnail_url
      if ($key === 'thumbnail_url' && $field === 'featured_image') {
        $out[$key] = 'featured_image';
        continue;
      }
      $image_type = isset($cfg['image_type']) ? sanitize_key($cfg['image_type']) : '';
      if (in_array($key, ['thumbnail_url','main_title_image'], true)) {
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
    $saved_types = get_option(self::OPTION_POST_TYPES, null);
    $types = apply_filters('vobj_post_types', $saved_types);
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
  wp_nonce_field(self::NONCE, self::NONCE);

  // Prefill from meta, mapping or sensible defaults
  $prefill = function ($key, $default = '') use ($post) {
    // 1) prefer explicit post meta saved by this meta box
    $meta = get_post_meta($post->ID, self::META_PREFIX.$key, true);
    if ($meta !== '') return esc_attr($meta);

    // 2) try mapping (ACF)
    if (method_exists($this, 'get_mapped_acf')) {
      try {
        $mapped = $this->get_mapped_acf($post->ID, $key);
      } catch (Throwable $e) {
        $mapped = null;
      }
      // For non-thumbnail fields: if mapping returns scalar, show it
      if ($mapped !== null && $mapped !== 'featured_image') {
        if (!is_array($mapped)) {
          return esc_attr((string)$mapped);
        }
        // For arrays we avoid dumping into text fields (except thumbnail, handled below)
        return esc_attr($default);
      }
    }

    // 3) sensible defaults
    if ($key === 'title') return esc_attr(get_the_title($post->ID) ?: $default);
    if ($key === 'description') return esc_attr(wp_strip_all_tags(get_the_excerpt($post->ID)) ?: $default);
    return esc_attr($default);
  };

  // Helper for multi-line fields (textarea)
  $meta_text = function($key, $default='') use ($post) {
    $v = get_post_meta($post->ID, self::META_PREFIX.$key, true);
    if ($v !== '') return esc_textarea($v);
    if (method_exists($this, 'get_mapped_acf')) {
      $m = $this->get_mapped_acf($post->ID, $key);
      if (is_string($m) && $m !== '') return esc_textarea($m);
    }
    return esc_textarea($default);
  };

  // Determine a display value for thumbnail specifically (resolve featured_image or ID/array)
  $thumbnail_display = '';
  // If explicit post meta exists show that
  $meta_thumb = get_post_meta($post->ID, self::META_PREFIX.'thumbnail_url', true);
  if ($meta_thumb !== '') {
    $thumbnail_display = esc_attr($meta_thumb);
  } else {
    // try mapping resolution
    if (method_exists($this, 'get_mapped_acf')) {
      try {
        $mapped_thumb = $this->get_mapped_acf($post->ID, 'thumbnail_url');
      } catch (Throwable $e) {
        $mapped_thumb = null;
      }
      // special token
      if ($mapped_thumb === 'featured_image') {
        $feat_id = get_post_thumbnail_id($post->ID);
        if ($feat_id) {
          $url = wp_get_attachment_url($feat_id);
          if ($url) $thumbnail_display = esc_attr($url);
        }
      } elseif (is_numeric($mapped_thumb)) {
        $url = wp_get_attachment_url((int)$mapped_thumb);
        if ($url) $thumbnail_display = esc_attr($url);
      } elseif (is_array($mapped_thumb)) {
        // common ACF image array -> try url or sizes
        if (!empty($mapped_thumb['url']) && filter_var($mapped_thumb['url'], FILTER_VALIDATE_URL)) {
          $thumbnail_display = esc_attr($mapped_thumb['url']);
        } elseif (!empty($mapped_thumb['sizes']) && is_array($mapped_thumb['sizes'])) {
          if (!empty($mapped_thumb['sizes']['full'])) $thumbnail_display = esc_attr($mapped_thumb['sizes']['full']);
          else {
            $sizes = array_values($mapped_thumb['sizes']);
            if (!empty($sizes[0]) && filter_var($sizes[0], FILTER_VALIDATE_URL)) $thumbnail_display = esc_attr($sizes[0]);
          }
        }
      } elseif (is_string($mapped_thumb) && filter_var($mapped_thumb, FILTER_VALIDATE_URL)) {
        $thumbnail_display = esc_attr($mapped_thumb);
      }
    }
  }

  ?>
  <p><label><input type="checkbox" name="vobj_enabled" value="1" <?php checked(get_post_meta($post->ID, self::META_PREFIX.'enabled', true), '1'); ?>> <?php _e('Enable VideoObject on this page', 'vobj'); ?></label></p>

  <table class="form-table">
    <tr><th><label><?php _e('Title', 'vobj'); ?></label></th><td><input type="text" class="widefat" name="vobj_title" value="<?php echo $prefill('title', get_the_title($post->ID)); ?>"></td></tr>
    <tr><th><label><?php _e('Description', 'vobj'); ?></label></th><td><textarea class="widefat" name="vobj_description" rows="3"><?php echo $meta_text('description', wp_strip_all_tags(get_the_excerpt($post->ID))); ?></textarea></td></tr>
    <tr><th><label><?php _e('Upload Date (ISO 8601)', 'vobj'); ?></label></th><td><input type="text" class="widefat" placeholder="2025-10-21T09:00:00+11:00" name="vobj_upload_date" value="<?php echo $prefill('upload_date', get_post_time('c', true, $post->ID)); ?>"></td></tr>
    <tr><th><label><?php _e('Duration (ISO 8601)', 'vobj'); ?></label></th><td><input type="text" class="widefat" placeholder="PT2M31S" name="vobj_duration_iso" value="<?php echo $prefill('duration_iso', ''); ?>"></td></tr>

    <tr>
      <th><label><?php _e('Thumbnail (URL or featured_image token)', 'vobj'); ?></label></th>
      <td>
        <input type="text" class="widefat" name="vobj_thumbnail_url" value="<?php echo $thumbnail_display; ?>">
        <p class="description"><?php _e('Enter a URL or use the Schema Settings mapping (featured_image or an ACF image field). If mapping is used, the resolved URL is shown here for convenience.', 'vobj'); ?></p>
        <?php if (!empty($thumbnail_display) && filter_var($thumbnail_display, FILTER_VALIDATE_URL)): ?>
          <p style="margin-top:8px;"><img src="<?php echo esc_url($thumbnail_display); ?>" alt="" style="max-width:240px;border:1px solid #ddd;padding:4px;"></p>
        <?php endif; ?>
      </td>
    </tr>

    <tr><th><label><?php _e('Content URL (mp4/HLS) or leave for ACF file mapping', 'vobj'); ?></label></th><td><input type="url" class="widefat" name="vobj_content_url" value="<?php echo $prefill('content_url', ''); ?>"></td></tr>
    <tr><th><label><?php _e('Embed URL (YouTube/Vimeo)', 'vobj'); ?></label></th><td><input type="url" class="widefat" name="vobj_embed_url" value="<?php echo $prefill('embed_url', ''); ?>"></td></tr>
    <tr><th><label><?php _e('Transcript (URL or text)', 'vobj'); ?></label></th><td><textarea class="widefat" name="vobj_transcript_url" rows="2"><?php echo $meta_text('transcript_url', ''); ?></textarea></td></tr>
    <tr><th><label><?php _e('Language', 'vobj'); ?></label></th><td><input type="text" class="regular-text" placeholder="en or en-AU" name="vobj_language" value="<?php echo $prefill('language','en'); ?>"></td></tr>
    <tr><th><label><?php _e('SeekToAction (auto key moments)', 'vobj'); ?></label></th><td><label><input type="checkbox" name="vobj_seektoaction" value="1" <?php checked(get_post_meta($post->ID, self::META_PREFIX.'seektoaction', true), '1'); ?>> <?php _e('Enable', 'vobj'); ?></label></td></tr>

    <tr>
      <th><label><?php _e('Clips (JSON)', 'vobj'); ?></label></th>
      <td>
        <textarea class="widefat" name="vobj_clips_json" rows="5" placeholder='[{"name":"Intro","startOffset":0,"endOffset":29,"url":""}]'><?php echo esc_textarea(get_post_meta($post->ID, self::META_PREFIX.'clips_json', true)); ?></textarea>
        <p class="description"><?php _e('Optional chapter markers. Each item: <code>name</code>, <code>startOffset</code> (sec), optional <code>endOffset</code>, optional <code>url</code>.', 'vobj'); ?></p>
      </td>
    </tr>

    <!-- Visual description -->
    <tr>
      <th><label><?php _e('Visual description', 'vobj'); ?></label></th>
      <td>
        <textarea class="widefat" name="vobj_visual_description" rows="4" placeholder="<?php echo esc_attr('Describe visually what happens in this piece (no transcript required).'); ?>"><?php echo esc_textarea(get_post_meta($post->ID, self::META_PREFIX.'visual_description', true)); ?></textarea>
        <p class="description"><?php _e('Optional: a short/long visual description for work where transcripts are not available. This will be emitted as a PropertyValue "visualDescription" in the schema.', 'vobj'); ?></p>
      </td>
    </tr>

  </table>

  <?php
  // Show Preview (computed payload) for editors
  if (current_user_can('edit_post', $post->ID)) {
    echo '<hr>';
    echo '<details><summary><strong>' . esc_html__('Preview generated JSON-LD', 'vobj') . '</strong></summary><div style="margin-top:8px;">';
    try {
      $payload = $this->collect_video_data($post->ID);
      if ($payload && is_array($payload)) {
        $pretty = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo '<pre style="white-space:pre-wrap; background:#f6f8fa; padding:10px; border:1px solid #ddd;">' . esc_html($pretty) . '</pre>';
        $missing = [];
        if (empty($payload['name'])) $missing[] = 'name';
        if (empty($payload['thumbnailUrl'])) $missing[] = 'thumbnailUrl';
        if (empty($payload['uploadDate'])) $missing[] = 'uploadDate';
        if ($missing) {
          echo '<p style="color:#b94a48;"><strong>' . esc_html__('Missing required fields: ', 'vobj') . esc_html(implode(', ', $missing)) . '</strong></p>';
        }
      } else {
        echo '<p class="description">' . esc_html__('No schema payload generated for this post. Ensure the post is enabled or ACF mappings / meta values exist.', 'vobj') . '</p>';
      }
    } catch (Throwable $e) {
      echo '<p class="description">' . esc_html__('Preview failed: ') . esc_html($e->getMessage()) . '</p>';
    }
    echo '</div></details>';
  }
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
      'thumbnail_url' => sanitize_text_field($_POST['vobj_thumbnail_url'] ?? ''), // allow 'featured_image' token or URL
      'content_url'   => esc_url_raw($_POST['vobj_content_url'] ?? ''),
      'embed_url'     => esc_url_raw($_POST['vobj_embed_url'] ?? ''),
      'transcript_url'=> trim($_POST['vobj_transcript_url'] ?? ''),
      'language'      => sanitize_text_field($_POST['vobj_language'] ?? 'en'),
      'seektoaction'  => isset($_POST['vobj_seektoaction']) ? '1' : '',
      'clips_json'    => $this->sanitize_json($_POST['vobj_clips_json'] ?? ''),
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