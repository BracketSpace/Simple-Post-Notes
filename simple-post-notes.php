<?php
/*
Plugin Name: Simple Post Notes
Description: Adds simple notes to post, pages and custom post type edit screen.
Author: BracketSpace
Author URI: https://bracketspace.com
Version: 1.7.8
Requires PHP: 7.0
License: GPL2
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

// Constants
define('SPNOTES', plugin_dir_url(__FILE__));
define('SPNOTES_DIR', plugin_dir_path(__FILE__));

/**
 * Simple Post Notes class
 */
class SPNotes {

    /**
     * Plugin settings
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
     * @var array
     */
    public $defaultNotesLabel;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->defaultNotesLabel = __('Notes', 'simple-post-notes');

        register_activation_hook(__FILE__, ['SPNotes', 'activation']);
        register_uninstall_hook(__FILE__, ['SPNotes', 'uninstall']);

        add_action('admin_menu', [$this, 'registerPage'], 8, 0);
        add_action('admin_init', [$this, 'registerSettings'], 10, 0);

        add_action('admin_init', [$this, 'addColumns'], 10, 0);

        add_action('admin_enqueue_scripts', [$this, 'enqueueScriptsAndStyles'], 10 , 1);

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
        add_option('spnotes_settings', [
        	'post_types'        => self::$defaultPostTypes,
            'notes_label'       => __('Notes', 'simple-post-notes'),
            'notes_placeholder' => '',
    	]);
    }

    /**
     * On plugin uninstall
     * @return void
     */
    public static function uninstall()
    {
        delete_option('spnotes_settings');
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
     * Gets settings
     * @return void
     */
    public function getSettings()
    {
        if (! empty($this->settings)) {
            return;
        }

        $this->settings = get_option('spnotes_settings');

        if (empty($this->settings['notes_label'])) {
            $this->settings['notes_label'] = $this->defaultNotesLabel;
        }
    }

    public function addColumnFilters()
    {
        $this->getSettings();

        foreach ($this->settings['post_types'] as $postType) {
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
    function addQuickEditField($columnName, $postType)
    {
        $this->getSettings();

        if (! $this->settings['post_types'] || ! in_array($postType, $this->settings['post_types']) || $columnName !== 'spnote') {
            return;
        }

        $label = wp_filter_nohtml_kses(sanitize_text_field(html_entity_decode($this->settings['notes_label'], ENT_QUOTES, 'UTF-8')));

        $placeholder = isset($this->settings['notes_placeholder']) ? $this->settings['notes_placeholder'] : '';
        $placeholder = wp_filter_nohtml_kses(sanitize_text_field(html_entity_decode($placeholder, ENT_QUOTES, 'UTF-8')));

        echo '<fieldset class="inline-edit-col-right">';
            wp_nonce_field('spnotes_note_bulk_edit', 'spnotes_nonce');
            echo '<div class="inline-edit-group">';
                echo '<label>';
                    echo '<span class="title">' . esc_html($label) . '</span>';
                    echo '<textarea name="spnote" placeholder="' . esc_attr($placeholder) . '"></textarea>';
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

        $label = wp_filter_nohtml_kses(sanitize_text_field(html_entity_decode($this->settings['notes_label'], ENT_QUOTES, 'UTF-8')));

        $keys[] = 'spnote';
        $vals[] = $label;

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
     * Registers added column as sortable
     * @param   array columns current columns
     * @return  array columns
     */
    public function queryOrderby($query)
    {
        if (! is_admin()) {
            return;
        }

        $orderby = $query->get('orderby');

        if ('spnote' == $orderby) {
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
            $note = get_post_meta($postId, '_spnote', true);

            if ($note) {
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
        $label = wp_filter_nohtml_kses(sanitize_text_field(html_entity_decode($this->settings['notes_label'], ENT_QUOTES, 'UTF-8')));

        foreach ($this->settings['post_types'] as $screen) {
            add_meta_box('spnotes',
                esc_html($label),
                [$this, 'metabox'],
                $screen,
                'side',
                'high');
        }
    }

    /**
     * Displays metabox content
     * @param  object $post current WP_Post object
     * @return void
     */
    public function metabox($post)
    {

        wp_nonce_field('spnotes_note_' . $post->ID, 'spnotes_nonce');

        if (! current_user_can('edit_posts')) {
            return;
        }

        $placeholder = wp_filter_nohtml_kses(sanitize_text_field(html_entity_decode($this->settings['notes_placeholder'], ENT_QUOTES, 'UTF-8')));

        $note = get_post_meta($post->ID, '_spnote', true);

        echo '<textarea style="display: block; width: 100%;" rows="5" name="spnote" placeholder="' . esc_attr($placeholder) . '" />' . esc_html($note) . '</textarea>';
    }

    /**
     * Saves the note
     * @param  int $post_id saved post ID
     * @return void
     */
    public function saveNote($post_id)
    {
        if (! isset($_POST['spnotes_nonce'])) {
            return;
        }

        if (! wp_verify_nonce($_POST['spnotes_nonce'], 'spnotes_note_' . $post_id)) {
            return;
        }

        if (! current_user_can('edit_posts')) {
            return;
        }

        if (! isset($_POST['spnote'])) {
            return;
        }

        update_post_meta($post_id, '_spnote', sanitize_textarea_field($_POST['spnote']));
    }

    /**
     * Saves the note from quick edit
     * @param  int $post_id saved post ID
     * @return void
     */
    public function saveQuickeditNote($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! isset($_POST['action']) || $_POST['action'] != 'inline-save') {
            return;
        }

        $this->getSettings();

        if (! $this->settings['post_types']) {
            return;
        }

        if (! wp_verify_nonce($_POST['spnotes_nonce'], 'spnotes_note_bulk_edit')) {
            return;
        }

        if (! current_user_can('edit_posts')) {
            return;
        }

        if (! in_array($_POST['post_type'], $this->settings['post_types'])) {
            return;
        }

        if (! isset($_POST['spnote'])) {
            return;
        }

        update_post_meta($post_id, '_spnote', sanitize_textarea_field($_POST['spnote']));
    }

    /**
     * Saves the note from bulk edit
     * @return void
     */
    public function saveBulkeditNote()
    {
        if (! wp_verify_nonce($_POST['nonce'], 'spnotes_note_bulk_edit')) {
            return;
        }

        if (! current_user_can('edit_posts')) {
            return;
        }

        $postIds = ( isset( $_POST['post_ids'] ) && ! empty( $_POST['post_ids'] ) ) ? $_POST['post_ids'] : [];
        $note = ( isset( $_POST['spnote'] ) && ! empty( $_POST['spnote'] ) ) ? $_POST['spnote'] : null;

        if (!empty($postIds) && is_array($postIds) && ! empty($note)) {
        	foreach($postIds as $postId) {
        		update_post_meta($postId, '_spnote', sanitize_textarea_field($note));
        	}
        }
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
            <h2><?php esc_html_e('Simple Post Notes Settings', 'simple-post-notes'); ?></h2>
            <form action="options.php" method="post" enctype="multipart/form-data">
                <?php settings_fields('spnotes_settings'); ?>
                <?php do_settings_sections('spnotes'); ?>
                <?php submit_button(__('Save', 'spnotes'), 'primary', 'save'); ?>
            </form>
        </div>
    <?php
    }

    /**
     * Registers settings
     * @return void
     */
    public function registerSettings()
    {
        $this->getSettings();

        register_setting('spnotes_settings', 'spnotes_settings');

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
        if (! isset($this->settings['post_types']) || empty($this->settings['post_types'])) {
            $this->settings['post_types'] = self::$defaultPostTypes;
        }

        echo '<select multiple="multiple" name="spnotes_settings[post_types][]" id="post_types" class="chosen-select" style="width: 300px;">';
            foreach (get_post_types(['public' => true], 'objects') as $postType) {
                if ($postType->name == 'attachment') {
                    continue;
                }

                $selected = in_array($postType->name, $this->settings['post_types']) ? 'selected="selected"' : '';
                echo '<option value="' . esc_attr($postType->name) . '" ' . esc_attr($selected) . '>' . esc_attr($postType->labels->name) . '</option>';
            }
        echo '</select>';
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
        if (! isset($this->settings['notes_label']) || empty($this->settings['notes_label'])) {
            $this->settings['notes_label'] = $this->defaultNotesLabel;
        }

        $label = wp_filter_nohtml_kses(sanitize_text_field(html_entity_decode($this->settings['notes_label'], ENT_QUOTES, 'UTF-8')));
        echo '<input name="spnotes_settings[notes_label]" id="notes_label" style="width: 300px;" value="' . esc_attr($label) . '">';
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
        if (! isset($this->settings['notes_placeholder']) || empty($this->settings['notes_placeholder'])) {
            $this->settings['notes_placeholder'] = '';
        }

        $placeholder = wp_filter_nohtml_kses(sanitize_text_field(html_entity_decode($this->settings['notes_placeholder'], ENT_QUOTES, 'UTF-8')));

        echo '<textarea name="spnotes_settings[notes_placeholder]" id="notes_placeholder" style="width: 300px;">';
        echo esc_html($placeholder);
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
        wp_enqueue_script('spnotes/chosen', SPNOTES . 'assets/chosen/chosen.jquery.min.js', ['jquery'], filemtime(SPNOTES_DIR . 'assets/chosen/chosen.jquery.min.js'), true);
        wp_enqueue_script('spnotes/admin', SPNOTES . 'assets/admin.js', ['jquery',
            'spnotes/chosen'], filemtime(SPNOTES_DIR . 'assets/admin.js'), true);
        wp_enqueue_style('spnotes/chosen', SPNOTES . 'assets/chosen/chosen.min.css', [], filemtime(SPNOTES_DIR . 'assets/chosen/chosen.min.css'));
    }

    /**
     * Displays shortcode output
     * @param  array $atts shortcode attributes
     * @return string
     */
    public function shortcodeCallback($atts)
    {
        $atts = shortcode_atts(['id' => null,], $atts, 'spnote');

        if ($atts['id'] == null) {
            global $post;

            if (empty($post)) {
                return '';
            }

            $atts['id'] = $post->ID;
        }

        $note = get_post_meta($atts['id'], '_spnote', true);

        return sprintf('<div class="simple-post-notes note note-%d">%s</div>', $atts['id'], nl2br($note));
    }
}

add_action('init', function() {
	new SPNotes();
});
