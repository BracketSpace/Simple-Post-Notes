<?php
/*
Plugin Name: Simple Post Notes
Description: Adds simple notes to post, pages and custom post type edit screen.
Author: BracketSpace
Author URI: https://bracketspace.com
Version: 1.8.0
Requires PHP: 7.4
Requires at least: 6.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: simple-post-notes
*/

declare(strict_types=1);

/*
    Copyright (C) 2025  BracketSpace

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if (! defined('ABSPATH')) {
    exit;
}

// Constants
define('SPNOTES', plugin_dir_url(__FILE__));
define('SPNOTES_DIR', plugin_dir_path(__FILE__));

/**
 * Simple Post Notes class
 */
class SPNotes {

    /**
     * Plugin settings (last value returned by getSettings())
     * @var array
     */
    public $settings = [];

    /**
     * Settings page hook
     * @var string
     */
    public $pageHook;

    /**
     * Default post types
     * @var array
     */
    public static $defaultPostTypes = ['post', 'page'];

    /**
     * Default notes label
     * @var string
     */
    public $defaultNotesLabel;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->defaultNotesLabel = __('Notes', 'simple-post-notes');

        add_action('admin_menu', [$this, 'registerPage'], 8, 0);
        add_action('admin_init', [$this, 'registerSettings'], 10, 0);

        add_action('admin_init', [$this, 'addColumns'], 10, 0);

        add_action('admin_enqueue_scripts', [$this, 'enqueueScriptsAndStyles'], 10, 1);

        add_action('add_meta_boxes', [$this, 'addMetaBox']);

        add_action('save_post', [$this, 'saveNote']);
        add_action('save_post', [$this, 'saveQuickeditNote']);
        add_action('wp_ajax_spnote_save_bulk_edit', [$this, 'saveBulkeditNote']);

        add_action('bulk_edit_custom_box', [$this, 'addQuickEditField'], 10, 2);
        add_action('quick_edit_custom_box', [$this, 'addQuickEditField'], 10, 2);

        add_shortcode('spnote', [$this, 'shortcodeCallback']);

        add_action('pre_get_posts', [$this, 'queryOrderby']);
    }

    /**
     * On plugin activation
     * @return void
     */
    public static function activation()
    {
        // add_option() does nothing when the option already exists.
        add_option('spnotes_settings', [
            'post_types'        => self::$defaultPostTypes,
            'notes_label'       => '',
            'notes_placeholder' => '',
        ]);
    }

    /**
     * Adds columns
     * @return  void
     */
    public function addColumns()
    {
        if (apply_filters('spn/columns-display', true)) {
            $this->addColumnFilters();
        }
    }

    /**
     * Gets settings, always a fully defaulted array
     *
     * A missing option (or one without the post_types key) means the defaults;
     * an empty post_types array means the user deliberately selected none.
     *
     * @return array
     */
    public function getSettings()
    {
        $option = get_option('spnotes_settings');
        $option = is_array($option) ? $option : [];

        $postTypes = self::$defaultPostTypes;
        if (isset($option['post_types']) && is_array($option['post_types'])) {
            $postTypes = array_values(array_filter($option['post_types'], 'is_string'));
        }

        $label = (isset($option['notes_label']) && is_string($option['notes_label'])) ? $option['notes_label'] : '';
        if ('' === $label) {
            $label = (string) $this->defaultNotesLabel;
        }

        $placeholder = (isset($option['notes_placeholder']) && is_string($option['notes_placeholder'])) ? $option['notes_placeholder'] : '';

        $this->settings = [
            'post_types'        => $postTypes,
            'notes_label'       => $label,
            'notes_placeholder' => $placeholder,
        ];

        return $this->settings;
    }

    /**
     * Gets plain text version of a settings string
     * @param  string $value settings value
     * @return string
     */
    private function plainText($value)
    {
        return wp_filter_nohtml_kses(sanitize_text_field(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8')));
    }

    /**
     * Gets notes label
     * @return string
     */
    private function getLabel()
    {
        $settings = $this->getSettings();

        return $this->plainText($settings['notes_label']);
    }

    /**
     * Gets notes placeholder
     * @return string
     */
    private function getPlaceholder()
    {
        $settings = $this->getSettings();

        return $this->plainText($settings['notes_placeholder']);
    }

    public function addColumnFilters()
    {
        $settings = $this->getSettings();

        foreach ($settings['post_types'] as $postType) {
            if (apply_filters('spn/columns-display/' . $postType, true)) {
                add_filter('manage_' . $postType . '_posts_columns', [$this, 'addColumn']);
                add_action('manage_' . $postType . '_posts_custom_column', [$this, 'outputColumn'], 10, 2);
            }

            if (apply_filters('spn/columns-sortable/' . $postType, true)) {
                add_filter('manage_edit-' . $postType . '_sortable_columns', [$this, 'registerSortableColumn']);
            }
        }
    }

    /**
     * Adds field to quick/bulk edit box
     * @param  string $columnName column name
     * @param  string $postType   post type name
     * @return void
     */
    public function addQuickEditField($columnName, $postType)
    {
        $settings = $this->getSettings();

        if ('spnote' !== $columnName || ! in_array($postType, $settings['post_types'], true)) {
            return;
        }

        if (! current_user_can('edit_posts')) {
            return;
        }

        $nonceId = 'bulk_edit_custom_box' === current_filter() ? 'spnotes_nonce_bulk' : 'spnotes_nonce_quick';

        echo '<fieldset class="inline-edit-col-right">';
            echo '<input type="hidden" id="' . esc_attr($nonceId) . '" name="spnotes_nonce" value="' . esc_attr(wp_create_nonce('spnotes_note_bulk_edit')) . '">';
            echo '<div class="inline-edit-group">';
                echo '<label>';
                    echo '<span class="title">' . esc_html($this->getLabel()) . '</span>';
                    echo '<textarea name="spnote" placeholder="' . esc_attr($this->getPlaceholder()) . '"></textarea>';
                echo '</label>';
            echo '</div>';
        echo '</fieldset>';
    }

    /**
     * Adds column with note to posts table
     * @param   array columns current columns
     * @return  array columns
     */
    public function addColumn($columns)
    {
        $insertKey = 'title';

        $keys = array_keys($columns);
        $vals = array_values($columns);

        $insertAfter = array_search($insertKey, $keys) + 1;

        $keys2 = array_splice($keys, $insertAfter);
        $vals2 = array_splice($vals, $insertAfter);

        $keys[] = 'spnote';
        $vals[] = $this->getLabel();

        return array_merge(array_combine($keys, $vals), array_combine($keys2, $vals2));
    }

    /**
     * Registers added column as sortable
     * @param   array columns current columns
     * @return  array columns
     */
    public function registerSortableColumn($columns)
    {
        $columns['spnote'] = 'spnote';
        return $columns;
    }

    /**
     * Sorts the main admin query by the note
     * @param   WP_Query $query query object
     * @return  void
     */
    public function queryOrderby($query)
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        if ('spnote' === $query->get('orderby')) {
            $query->set('meta_key', '_spnote');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Outputs column content
     * @param string $column  current column slug
     * @param int    $postId current post ID
     */
    public function outputColumn($column, $postId)
    {
        if ($column == 'spnote') {
            $note = (string) get_post_meta($postId, '_spnote', true);

            // Raw note for quick edit prefill; always printed so JS can clear the textarea.
            echo '<span class="spnote-raw hidden" hidden id="spnote-raw-' . esc_attr($postId) . '" data-note="' . esc_attr($note) . '"></span>';

            if ('' !== $note) {
                echo '<div id="spnote-' . esc_attr($postId) . '">';
                echo nl2br(wp_kses_data($note));
                echo '</div>';
            }
        }
    }

    /**
     * Adds metabox to edit post screen
     * @return  void
     */
    public function addMetaBox()
    {
        $settings = $this->getSettings();
        $label = $this->getLabel();

        foreach ($settings['post_types'] as $screen) {
            add_meta_box('spnotes',
                esc_html($label),
                [$this, 'metabox'],
                $screen,
                'side',
                'high',
                ['__block_editor_compatible_meta_box' => true]);
        }
    }

    /**
     * Displays metabox content
     * @param  object $post current WP_Post object
     * @return void
     */
    public function metabox($post)
    {
        if (! current_user_can('edit_post', $post->ID)) {
            return;
        }

        wp_nonce_field('spnotes_note_' . $post->ID, 'spnotes_nonce');

        $note = (string) get_post_meta($post->ID, '_spnote', true);

        echo '<textarea style="display: block; width: 100%;" rows="5" name="spnote" placeholder="' . esc_attr($this->getPlaceholder()) . '">' . esc_textarea($note) . '</textarea>';
    }

    /**
     * Gets a nonce value sent in the request
     * @param  string $key $_POST key
     * @return string
     */
    private function getPostedNonce($key)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is read here in order to be verified by the caller.
        if (! isset($_POST[$key]) || ! is_string($_POST[$key])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is read here in order to be verified by the caller.
        return sanitize_text_field(wp_unslash($_POST[$key]));
    }

    /**
     * Validates and saves the note of one post
     * @param  int    $postId post ID
     * @param  string $note   unslashed, sanitized note
     * @return bool           whether the note was saved
     */
    private function saveNoteForPost($postId, $note)
    {
        $postId = absint($postId);

        if (! $postId) {
            return false;
        }

        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return false;
        }

        $settings = $this->getSettings();

        if (! in_array(get_post_type($postId), $settings['post_types'], true)) {
            return false;
        }

        if (! current_user_can('edit_post', $postId)) {
            return false;
        }

        update_post_meta($postId, '_spnote', $note);

        return true;
    }

    /**
     * Saves the note
     * @param  int $post_id saved post ID
     * @return void
     */
    public function saveNote($post_id)
    {
        if (! wp_verify_nonce($this->getPostedNonce('spnotes_nonce'), 'spnotes_note_' . $post_id)) {
            return;
        }

        if (! isset($_POST['spnote']) || ! is_string($_POST['spnote'])) {
            return;
        }

        $this->saveNoteForPost($post_id, sanitize_textarea_field(wp_unslash($_POST['spnote'])));
    }

    /**
     * Saves the note from quick edit
     * @param  int $post_id saved post ID
     * @return void
     */
    public function saveQuickeditNote($post_id)
    {
        if (! isset($_POST['action']) || 'inline-save' !== $_POST['action']) {
            return;
        }

        if (! wp_verify_nonce($this->getPostedNonce('spnotes_nonce'), 'spnotes_note_bulk_edit')) {
            return;
        }

        if (! isset($_POST['spnote']) || ! is_string($_POST['spnote'])) {
            return;
        }

        $this->saveNoteForPost($post_id, sanitize_textarea_field(wp_unslash($_POST['spnote'])));
    }

    /**
     * Saves the note from bulk edit
     * @return void
     */
    public function saveBulkeditNote()
    {
        if (! wp_verify_nonce($this->getPostedNonce('nonce'), 'spnotes_note_bulk_edit')) {
            wp_send_json_error(null, 403);
        }

        $postIds = (isset($_POST['post_ids']) && is_array($_POST['post_ids'])) ? array_map('absint', wp_unslash($_POST['post_ids'])) : [];
        $note = (isset($_POST['spnote']) && is_string($_POST['spnote'])) ? sanitize_textarea_field(wp_unslash($_POST['spnote'])) : '';

        // An empty bulk note never overwrites existing notes.
        if (empty($postIds) || '' === trim($note)) {
            wp_send_json_error(null, 400);
        }

        $saved = 0;
        foreach (array_unique($postIds) as $postId) {
            if ($this->saveNoteForPost($postId, $note)) {
                $saved++;
            }
        }

        wp_send_json_success(['saved' => $saved]);
    }

    /**
     * Registers admin page
     * @return  void
     */
    public function registerPage()
    {
        $this->pageHook = add_options_page(__('Post Notes', 'simple-post-notes'),
            __('Post Notes', 'simple-post-notes'),
            'manage_options',
            'spnotes',
            [$this, 'displaySettingsPage']);
    }

    /**
     * Displays settings page
     * @return  void
     */
    public function displaySettingsPage()
    {
    ?>
        <div class="wrap">
            <h1><?php esc_html_e('Simple Post Notes Settings', 'simple-post-notes'); ?></h1>
            <form action="options.php" method="post">
                <?php settings_fields('spnotes_settings'); ?>
                <?php do_settings_sections('spnotes'); ?>
                <?php submit_button(__('Save', 'simple-post-notes'), 'primary', 'save'); ?>
            </form>
        </div>
    <?php
    }

    /**
     * Sanitizes settings before they are saved
     *
     * post_types is always stored as an array, so an empty selection (the form
     * sends no post_types key then) is stored as an empty array and not mistaken
     * for the missing option.
     *
     * @param  mixed $input submitted settings
     * @return array
     */
    public function sanitizeSettings($input)
    {
        $input = is_array($input) ? $input : [];

        $postTypes = (isset($input['post_types']) && is_array($input['post_types'])) ? $input['post_types'] : [];
        $postTypes = array_filter(array_map('sanitize_key', array_filter($postTypes, 'is_string')), 'post_type_exists');

        return [
            'post_types'        => array_values(array_unique($postTypes)),
            'notes_label'       => (isset($input['notes_label']) && is_string($input['notes_label'])) ? sanitize_text_field($input['notes_label']) : '',
            'notes_placeholder' => (isset($input['notes_placeholder']) && is_string($input['notes_placeholder'])) ? sanitize_text_field($input['notes_placeholder']) : '',
        ];
    }

    /**
     * Registers settings
     * @return void
     */
    public function registerSettings()
    {
        register_setting('spnotes_settings', 'spnotes_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitizeSettings'],
        ]);

        add_settings_section(
            'spnotes_general',
            __('General Settings', 'simple-post-notes'),
            null,
            'spnotes'
        );

        add_settings_field(
            'post_types',
            __('Post types', 'simple-post-notes'),
            [$this, 'settingsPostTypeField'],
            'spnotes',
            'spnotes_general'
        );

        add_settings_field(
            'notes_label',
            __('Notes label', 'simple-post-notes'),
            [$this, 'settingsNotesLabelField'],
            'spnotes',
            'spnotes_general'
        );

        add_settings_field(
            'notes_placeholder',
            __('Notes placeholder', 'simple-post-notes'),
            [$this, 'settingsNotesPlaceholderField'],
            'spnotes',
            'spnotes_general'
        );
    }

    /**
     * Settings fields
     *
     * Post type field output
     *
     * @access  public
     *
     * @return  void
     */
    public function settingsPostTypeField() {
        $settings = $this->getSettings();

        echo '<fieldset id="post_types">';
            foreach (get_post_types(['public' => true], 'objects') as $postType) {
                if ($postType->name == 'attachment') {
                    continue;
                }

                $checked = in_array($postType->name, $settings['post_types'], true);
                echo '<label style="display: block; margin-bottom: 4px;">';
                    echo '<input type="checkbox" name="spnotes_settings[post_types][]" value="' . esc_attr($postType->name) . '"' . checked($checked, true, false) . '> ';
                    echo esc_html($postType->labels->name);
                echo '</label>';
            }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Apply Post Notes to these post types', 'simple-post-notes') . '</p>';
    }

    /**
     * Settings fields
     *
     * Notes label field output
     *
     * @access  public
     *
     * @return  void
     */
    public function settingsNotesLabelField()
    {
        echo '<input name="spnotes_settings[notes_label]" id="notes_label" style="width: 300px;" value="' . esc_attr($this->getLabel()) . '">';
    }

    /**
     * Settings fields
     *
     * Notes placeholder field output
     *
     * @access  public
     *
     * @return  void
     */
    public function settingsNotesPlaceholderField()
    {
        echo '<textarea name="spnotes_settings[notes_placeholder]" id="notes_placeholder" style="width: 300px;">';
        echo esc_textarea($this->getPlaceholder());
        echo '</textarea>';

        echo '<p class="description">' . esc_html__('It will be displayed on a post edit page as a help message inside the note field.', 'simple-post-notes') . '</p>';
    }

    /**
     * Enqueue scripts and styles
     * @param  string $hook current page hook
     * @return void
     */
    public function enqueueScriptsAndStyles($hook)
    {
        if ('edit.php' !== $hook) {
            return;
        }

        $screen = get_current_screen();

        if (! $screen || ! $screen->post_type) {
            return;
        }

        $settings = $this->getSettings();

        if (! in_array($screen->post_type, $settings['post_types'], true)) {
            return;
        }

        wp_enqueue_script('spnotes/admin', SPNOTES . 'assets/admin.js', ['jquery'], (string) filemtime(SPNOTES_DIR . 'assets/admin.js'), true);
    }

    /**
     * Displays shortcode output
     * @param  array $atts shortcode attributes
     * @return string
     */
    public function shortcodeCallback($atts)
    {
        $atts = shortcode_atts(['id' => null,], $atts, 'spnote');

        $postId = absint($atts['id']);

        if (! $postId) {
            global $post;

            if (empty($post)) {
                return '';
            }

            $postId = (int) $post->ID;
        }

        $target = get_post($postId);

        if (! $target) {
            return '';
        }

        // Password-protected posts: only visitors who unlocked them, or editors.
        if (post_password_required($target) && ! current_user_can('edit_post', $postId)) {
            return '';
        }

        if ('publish' !== $target->post_status && ! current_user_can('read_post', $postId)) {
            return '';
        }

        $note = (string) get_post_meta($postId, '_spnote', true);

        return sprintf('<div class="simple-post-notes note note-%d">%s</div>', $postId, nl2br(esc_html($note)));
    }
}

register_activation_hook(__FILE__, ['SPNotes', 'activation']);

add_action('init', function() {
    new SPNotes();
});
