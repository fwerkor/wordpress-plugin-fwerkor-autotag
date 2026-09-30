<?php
/**
 * Plugin Name: FWERKOR Auto Tag
 * Plugin URI: https://github.com/fwerkor/wordpress-plugin-fwerkor-autotag
 * Description: Conservative keyword-based automatic tagging for WordPress posts.
 * Version: 1.0.0
 * Author: FWERKOR
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class FWERKOR_Auto_Tag {
    private const OPTION = 'fwerkor_autotag_options';
    private const META = '_fwerkor_autotag_term_ids';

    public function __construct() {
        add_action('wp_after_insert_post', array($this, 'after_insert'), 30, 4);
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_fwerkor_autotag_retag_all', array($this, 'retag_all_action'));
    }

    public static function activate(): void {
        $defaults = array(
            'enabled' => 1,
            'max_tags' => 6,
            'preserve_manual' => 1,
            'dictionary' => implode("\n", array(
                'WordPress|wordpress',
                'Linux|linux',
                'Ubuntu|ubuntu',
                'Windows|windows',
                'Docker|docker',
                'NVIDIA|nvidia',
                'GPU|gpu',
                'MySQL|mysql',
                'systemd|systemd',
                'BBR|bbr',
                'ECMP|ecmp',
                'DDoS|ddos',
                'KMS|kms',
                'UWP|uwp',
                'VSCode|vscode,visual studio code',
                'ChatGPT|chatgpt',
                'API|api',
                'OCI|oci',
                'vLLM|vllm',
                'Ascend|ascend',
                'DeepSeek|deepseek',
            )),
        );
        add_option(self::OPTION, $defaults, '', false);
    }

    public function menu(): void {
        add_options_page(
            'FWERKOR Auto Tag',
            'FWERKOR Auto Tag',
            'manage_options',
            'fwerkor-autotag',
            array($this, 'render')
        );
    }

    public function after_insert(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ('post' !== $post->post_type || 'publish' !== $post->post_status) {
            return;
        }

        $options = $this->options();
        if (empty($options['enabled'])) {
            return;
        }

        $this->tag_post($post_id, $post);
    }

    public function tag_post(int $post_id, ?WP_Post $post = null): array {
        $post = $post ?: get_post($post_id);
        if (!$post instanceof WP_Post || 'post' !== $post->post_type) {
            return array();
        }

        $options = $this->options();
        $max = max(1, min(20, (int) ($options['max_tags'] ?? 6)));
        $dictionary = $this->parse_dictionary((string) ($options['dictionary'] ?? ''));

        $text = html_entity_decode(
            wp_strip_all_tags(
                $post->post_title . "\n" . $post->post_excerpt . "\n" . $post->post_content,
                true
            ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

        $candidates = array();
        foreach ($dictionary as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->contains($text, $alias)) {
                    $candidates[$this->lower($canonical)] = $canonical;
                    break;
                }
            }
            if (count($candidates) >= $max) {
                break;
            }
        }

        $previous_ids = array_map('intval', (array) get_post_meta($post_id, self::META, true));
        $existing = wp_get_post_tags($post_id, array('fields' => 'ids'));
        $existing = array_map('intval', is_array($existing) ? $existing : array());

        if (!empty($options['preserve_manual'])) {
            $manual = array_values(array_diff($existing, $previous_ids));
        } else {
            $manual = array();
        }

        $generated_ids = array();
        foreach (array_values($candidates) as $name) {
            $term = term_exists($name, 'post_tag');
            if (!$term) {
                $term = wp_insert_term($name, 'post_tag');
            }
            if (!is_wp_error($term)) {
                $generated_ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
            }
        }

        $final = array_values(array_unique(array_merge($manual, $generated_ids)));
        wp_set_post_tags($post_id, $final, false);
        update_post_meta($post_id, self::META, $generated_ids);

        return $generated_ids;
    }

    public function retag_all_action(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permission.');
        }
        check_admin_referer('fwerkor_autotag_retag_all');

        $ids = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
        ));

        foreach ($ids as $id) {
            $this->tag_post((int) $id);
        }

        wp_safe_redirect(admin_url('options-general.php?page=fwerkor-autotag&retagged=' . count($ids)));
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['fwerkor_autotag_settings'])) {
            check_admin_referer('fwerkor_autotag_settings');
            $options = array(
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'max_tags' => max(1, min(20, absint($_POST['max_tags'] ?? 6))),
                'preserve_manual' => isset($_POST['preserve_manual']) ? 1 : 0,
                'dictionary' => sanitize_textarea_field(wp_unslash($_POST['dictionary'] ?? '')),
            );
            update_option(self::OPTION, $options, false);
            wp_safe_redirect(admin_url('options-general.php?page=fwerkor-autotag&updated=1'));
            exit;
        }

        $o = $this->options();
        ?>
        <div class="wrap">
            <h1>FWERKOR Auto Tag</h1>
            <p>Creates a small set of stable keyword tags. It does not copy categories into tags and it never turns an entire Chinese title into a tag.</p>
            <?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['retagged'])) : ?><div class="notice notice-success is-dismissible"><p>Retagged <?php echo esc_html((string) absint($_GET['retagged'])); ?> published posts.</p></div><?php endif; ?>

            <form method="post" style="max-width:900px">
                <?php wp_nonce_field('fwerkor_autotag_settings'); ?>
                <input type="hidden" name="fwerkor_autotag_settings" value="1">
                <table class="form-table">
                    <tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($o['enabled'])); ?>> Tag published posts automatically</label></td></tr>
                    <tr><th>Maximum generated tags</th><td><input type="number" name="max_tags" min="1" max="20" value="<?php echo esc_attr((string) $o['max_tags']); ?>"></td></tr>
                    <tr><th>Manual tags</th><td><label><input type="checkbox" name="preserve_manual" value="1" <?php checked(!empty($o['preserve_manual'])); ?>> Preserve tags not generated by this plugin</label></td></tr>
                    <tr><th>Dictionary</th><td>
                        <textarea name="dictionary" rows="18" class="large-text code"><?php echo esc_textarea((string) $o['dictionary']); ?></textarea>
                        <p class="description">One line per tag: <code>Canonical tag|alias1,alias2,alias3</code>. Matching is case-insensitive. Aliases can contain Chinese text.</p>
                    </td></tr>
                </table>
                <p><button class="button button-primary" type="submit">Save settings</button></p>
            </form>

            <hr>
            <h2>Rebuild generated tags</h2>
            <p>This only replaces tags previously generated by FWERKOR Auto Tag; manual tags are preserved when that option is enabled.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('fwerkor_autotag_retag_all'); ?>
                <input type="hidden" name="action" value="fwerkor_autotag_retag_all">
                <button class="button" type="submit">Retag all published posts</button>
            </form>
        </div>
        <?php
    }

    private function options(): array {
        return wp_parse_args(
            (array) get_option(self::OPTION, array()),
            array(
                'enabled' => 1,
                'max_tags' => 6,
                'preserve_manual' => 1,
                'dictionary' => '',
            )
        );
    }

    private function parse_dictionary(string $raw): array {
        $result = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: array() as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            [$canonical, $alias_text] = array_pad(explode('|', $line, 2), 2, '');
            $canonical = trim($canonical);
            if ('' === $canonical) {
                continue;
            }
            $aliases = array_filter(array_map('trim', explode(',', $alias_text)));
            array_unshift($aliases, $canonical);
            $result[$canonical] = array_values(array_unique($aliases));
        }
        return $result;
    }

    private function contains(string $text, string $needle): bool {
        $needle = trim($needle);
        if ('' === $needle) {
            return false;
        }

        if (preg_match('/^[\x20-\x7E]+$/', $needle)) {
            return 1 === preg_match(
                '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/iu',
                $text
            );
        }

        return false !== (function_exists('mb_stripos') ? mb_stripos($text, $needle, 0, 'UTF-8') : stripos($text, $needle));
    }

    private function lower(string $text): string {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}

register_activation_hook(__FILE__, array('FWERKOR_Auto_Tag', 'activate'));
new FWERKOR_Auto_Tag();
