<?php

global $event, $step, $use_minified, $txp_permissions;

// DEBUG: set to false to load regular (non-minified) js and css files
$use_minified = true;

// Initialise reused variables
init_glz_custom_fields();

if (@txpinterface === 'admin') {

    // glz admin panels / events
    $glz_admin_events = array(
        'article',
        'prefs',
        'glz_custom_fields',
        'plugin_prefs.glz_custom_fields'
    );

    // Add prefs privs
    add_privs('prefs.glz_custom_f', '1');
    add_privs('prefs.glz_custom_f.glz_cf_datepicker', '1');
    add_privs('prefs.glz_custom_f.glz_cf_timepicker', '1');

    // Disable regular customs preferences (remove privs)
    $txp_permissions['prefs.custom'] = '';

    // 'Options' link on plugin panel
    add_privs('plugin_prefs.glz_custom_fields', '1');
    register_callback('glz_custom_fields_prefs_redirect', 'plugin_prefs.glz_custom_fields');

    add_privs('glz_custom_fields_install', '1');
    register_callback('glz_custom_fields_install', 'plugin_lifecycle.glz_custom_fields', 'installed');

    // Restrict css/js + pre-save to relevant admin pages only
    if (in_array($event, $glz_admin_events)) {

        // Add CSS & JS to admin head area
        add_privs('glz_custom_fields_inject_css_js', '1,2,3,4,5,6');
        register_callback('glz_custom_fields_inject_css_js', 'admin_side', 'head_end');

        // Write tab: array -> string conversion on save/create
        if (($step === 'edit') || ($step === 'create')) {
            add_privs('glz_custom_fields_before_save', '1,2,3,4,5,6');
            register_callback('glz_custom_fields_before_save', 'article', '', 1);
        }
    }

    // Custom fields tab under extensions
    add_privs('glz_custom_fields', '1,2');
    register_tab('extensions', 'glz_custom_fields', gTxt('glz_cf_tab_name'));
    register_callback('glz_custom_fields', 'glz_custom_fields');

    // Replace default custom fields with modified glz custom fields
    add_privs('glz_custom_fields_replace', '1,2,3,4,5,6');
    // -> custom fields
    register_callback('glz_custom_fields_replace', 'article_ui', 'custom_fields');
    // -> textareas
    register_callback('glz_custom_fields_replace', 'article_ui', 'body');
}

// -------------------------------------------------------------
// Main function: generates the content for Extensions > Custom Fields
function glz_custom_fields()
{
    global $event, $all_custom_sets, $prefs;
    $msg = '';

    // We have $_POST, let's see if there is any CRUD
    if ($_POST) {
        $incoming = stripPost();
        // DEBUG
        // die(dmp($incoming));

        extract($incoming);

        // Create an empty $value if it's not set in the $_POST
        if (!isset($value)) {
            $value = '';
        }

        // Delete a new custom field
        if (gps('delete')) {
            glz_custom_fields_MySQL("delete", $custom_set, "txp_prefs");
            glz_custom_fields_MySQL("delete", $custom_set, "txp_lang");
            glz_custom_fields_MySQL("delete", $custom_set, "custom_fields");
            glz_custom_fields_MySQL("delete", glz_custom_number($custom_set), "textpattern");

            $msg = gTxt('glz_cf_deleted', array('{custom_set_name}' => $custom_set_name));
        }

        // Reset one of the mighty 10 standard custom fields
        if (gps('reset')) {
            glz_custom_fields_MySQL("reset", $custom_set, "txp_prefs");
            glz_custom_fields_MySQL("delete", $custom_set, "custom_fields");
            glz_custom_fields_MySQL(
                "reset",
                glz_custom_number($custom_set),
                "textpattern",
                array(
                    'custom_set_type' => $custom_set_type,
                    'custom_field' => glz_custom_number($custom_set)
                )
            );

            $msg = gTxt('glz_cf_reset', array('{custom_set_name}' => $custom_set_name));
        }

        // Add a new custom field
        if (gps("custom_field_number")) {
            $custom_set_name = gps("custom_set_name");
            $custom_field_number = gps("custom_field_number");

            // A name has been specified
            if (!empty($custom_set_name)) {
                $custom_set_name = glz_clean_string($custom_set_name);
                $custom_set = "custom_".intval($custom_field_number)."_set";

                $name_exists = glz_check_custom_set_name($all_custom_sets, $custom_set_name, $custom_set);

                // If name doesn't exist
                if ($name_exists == false) {
                    glz_custom_fields_MySQL(
                        "new",
                        $custom_set_name,
                        "txp_prefs",
                        array(
                            'custom_field_number' => $custom_field_number,
                            'custom_set_type'     => $custom_set_type,
                            'custom_set_position' => $custom_set_position
                        )
                    );
                    glz_custom_fields_MySQL(
                        "new",
                        $custom_set_name,
                        "txp_lang",
                        array(
                            'custom_field_number' => $custom_field_number,
                            'lang'                => $GLOBALS['prefs']['language']
                        )
                    );
                    glz_custom_fields_MySQL(
                        "new",
                        $custom_set_name,
                        "textpattern",
                        array(
                            'custom_field_number' => $custom_field_number,
                            'custom_set_type'     => $custom_set_type
                        )
                    );
                    // There are custom fields for which we do not need to touch custom_fields table
                    if (!in_array($custom_set_type, array("textarea", "text_input"))) {
                        glz_custom_fields_MySQL(
                            "new",
                            $custom_set_name,
                            "custom_fields",
                            array(
                                'custom_field_number' => $custom_field_number,
                                'value'               => $value
                            )
                        );
                    }
                    $msg = gTxt('glz_cf_created', array('{custom_set_name}' => $custom_set_name));
                } else {
                    // Name exists, abort
                    $msg = array(gTxt('glz_cf_exists', array('{custom_set_name}' => $custom_set_name)), E_ERROR);
                }
            } else {
                // No name given
                $msg = array(gTxt('glz_cf_no_name'), E_ERROR);
            }
        }

        // Edit an existing custom field
        if (gps('save')) {
            if (!empty($custom_set_name)) {
                $custom_set_name = glz_clean_string($custom_set_name);

                $name_exists = glz_check_custom_set_name($all_custom_sets, $custom_set_name, $custom_set);
                // If name doesn't exist we'll need to create a new custom_set
                if ($name_exists == false) {
                    glz_custom_fields_MySQL(
                        "update",
                        $custom_set,
                        "txp_prefs",
                        array(
                            'custom_set_name'     => $custom_set_name,
                            'custom_set_type'     => $custom_set_type,
                            'custom_set_position' => $custom_set_position
                        )
                    );

                    // Custom sets need to be changed based on their type
                    glz_custom_fields_MySQL(
                        "update",
                        $custom_set,
                        "textpattern",
                        array(
                            'custom_set_type' => $custom_set_type,
                            'custom_field' => glz_custom_number($custom_set)
                        )
                    );

                    // For textareas we do not need to touch custom_fields table
                    if ($custom_set_type != "textarea") {
                        glz_custom_fields_MySQL("delete", $custom_set, "custom_fields");
                        glz_custom_fields_MySQL(
                            "new",
                            $custom_set_name,
                            "custom_fields",
                            array(
                                'custom_set'  => $custom_set,
                                'value'       => $value
                            )
                        );
                    }

                    $msg = gTxt('glz_cf_updated', array('{custom_set_name}' => $custom_set_name));
                } else {
                    // Name exists, abort
                    $msg = array(gTxt('glz_cf_exists', array('{custom_set_name}' => $custom_set_name)), E_ERROR);
                }
            } else {
                $msg = array(gTxt('glz_cf_no_name'), E_ERROR);
            }
        }
    }

    // CUSTOM FIELDS Pane
    // ––––––––––––––––--

    pagetop(gTxt('glz_cf_tab_name'), $msg);

    $contentBlock = tag_start('div', array('class' => 'txp-layout')).
            tag_start('div', array('class' => 'txp-layout-2col')).
                hed(gTxt('glz_cf_tab_name'), 1, array('class' => 'txp-heading')).
            tag_end('div').
            tag_start('div', array('class' => 'txp-layout-2col')).
                href(gTxt('tab_preferences'), '?event=prefs#prefs_group_glz_custom_f', array('class' => 'glz-cf-setup-switch')).
            tag_end('div').
        tag_end('div'); // end .txp-layout

    // Need to re-fetch data since things modified
    $all_custom_sets = glz_custom_fields_MySQL("all");

    // CUSTOM FIELDS Table -------------------

    // Column headings
    $headers = array(
        'id'        => 'id',
        'position'  => 'position',
        'name'      => 'name',
        'type'      => 'type',
        'options'   => 'options'
    );

    $head_row = '';

    foreach ($headers as $header => $column_head) {
        $head_row .= column_head(array(
                'options' => array('class' => trim('txp-list-col-'.$header)),
                'value'   => $column_head,
                'sort'    => $header
            )
        );
    }

    // Table head
    $contentBlock .= tag_start('div', array('class' => 'txp-listtables')).
                n.tag_start('table', array('class' => 'txp-list glz_custom_fields')).
                n.tag_start('thead').
                tr($head_row).
                n.tag_end('thead');

    // Table body
    $contentBlock .= n.tag_start('tbody');

    // Custom field table rows
    $i = 0;
    foreach ($all_custom_sets as $custom => $custom_set) {
        // First 10 fields cannot be deleted, just reset
        if ($i < 10) {
            // Only show 'reset' for custom fields that are set
            $reset_delete = ($custom_set['name']) ?
                    glz_form_buttons("reset",
                        gTxt('reset'),
                        $custom,
                        htmlspecialchars($custom_set['name']),
                        $custom_set['type'],
                        '',
                        'return confirm(\''.gTxt('glz_cf_confirm_reset', array('{custom}' => 'ID# '.glz_custom_digit($custom).': '.htmlspecialchars($custom_set['name']) )).'\')'
                    )
                :
                    null;
        } else {
            $reset_delete =
                    glz_form_buttons("delete",
                        gTxt('delete'),
                        $custom,
                        htmlspecialchars($custom_set['name']),
                        $custom_set['type'],
                        '',
                        'return confirm(\''.gTxt('glz_cf_confirm_delete', array('{custom}' => 'ID# '.glz_custom_digit($custom).': '.htmlspecialchars($custom_set['name']) )).'\')'
                    );
        }

        $edit =
                    glz_form_buttons("edit",
                        gTxt('edit'),
                        $custom,
                        htmlspecialchars($custom_set['name']),
                        $custom_set['type'],
                        $custom_set['position']
                    );

        $contentBlock .= tr(
            hCell(
                $custom_set['id'], '', array('class' => 'txp-list-col-id')
            ).
            td(
                $custom_set['position'], '', 'txp-list-col-position'
            ).
            td(
                $custom_set['name'], '', 'txp-list-col-name'
            ).
            td(
                (($custom_set['name']) ? gTxt('glz_cf_'.$custom_set['type']) : ''), '', 'txp-list-col-type'
            ).
            td(
                $edit.sp.$reset_delete, '', 'txp-list-col-options'
            )
        );
        $i++;
    }

    $contentBlock .= n.tag_end('tbody').
        n.tag_end('table').
        n.tag_end('div'); // End of .txp-listtables.

    echo $contentBlock;

    // CUSTOM FIELDS Edit/Add Panel ----------

    // Variables for edit or add form
    $legend = gps('edit') ?
        gTxt('glz_cf_action_edit_title', array('{custom_set_name}' => gTxt('glz_cf_title').' #'.glz_custom_digit(gps('custom_set')))) :
        gTxt('glz_cf_action_new_title');

    $custom_field = gps('edit') ?
        hInput('custom_set', gps('custom_set')) :
        hInput('custom_field_number', glz_custom_next($all_custom_sets));

    $custom_set = gps('edit') ?
        gps('custom_set') :
        null;

    $custom_name = gps('edit') ?
        gps('custom_set_name') :
        null;

    $custom_set_position = gps('edit') ?
        gps('custom_set_position') :
        null;

    $arr_custom_set_types = glz_custom_set_types();

    $custom_set_types = null;
    foreach ($arr_custom_set_types as $custom_type_group => $custom_types) {
        $custom_set_types .= '<optgroup label="'.ucfirst($custom_type_group).'">'.n;
        foreach ($custom_types as $custom_type) {
            $selected = (gps('edit') && gps('custom_set_type') == $custom_type) ?
                ' selected="selected"' :
                null;
            $custom_set_types .= '<option value="'.$custom_type.'" dir="auto"'.$selected.'>'.gTxt('glz_cf_'.$custom_type).'</option>'.n;
        }
        $custom_set_types .= '</optgroup>'.n;
    }


    // Fetch values for this custom field
    if (gps('edit')) {
        if ($custom_set_type == "text_input") {
            $arr_values = glz_custom_fields_MySQL('all_values', glz_custom_number($custom_set), '', array('custom_set_name' => $custom_set_name, 'status' => 4));
        } else {
            $arr_values = glz_custom_fields_MySQL("values", $custom_set, '', array('custom_set_name' => $custom_set_name));
        }

        $values = ($arr_values) ? implode("\r\n", $arr_values) : '';
    } else {
        $values = '';
    }

    $action = gps('edit') ?
        sLink('glz_custom_fields', '', gTxt('cancel'), 'txp-button').
        fInput('submit', 'save', gTxt('save'), 'publish') :
        fInput('submit', 'add_new', gTxt('glz_cf_add_new_cf'), 'publish');

    // This needs to be different for a script
    if (isset($custom_set_type) && $custom_set_type == "custom-script") {
        $value = fInput('text', 'value', $values, '', '', '', '', '', 'value');
        $value_instructions = 'glz_cf_js_script_msg';
    } else {
        $value = text_area('value', 0, 0, $values, 'value');
        $value_instructions = 'glz_cf_multiple_values_instructions';
    }

    // Build the form

    $out = array();

    $out[] = hed($legend, 2);
    $out[] =
    inputLabel(
            'custom_set_name',
            fInput('text', 'custom_set_name', htmlspecialchars($custom_name), '', '', '', INPUT_REGULAR, '', 'custom_set_name'),
            'glz_cf_edit_name',
            array(
                0 => '',
                1 => 'glz_cf_edit_name_hint' // Inline help string
            )
        ).
    inputLabel(
            'custom_set_type',
            '<select name="custom_set_type" id="custom_set_type">'.$custom_set_types.'</select>',
            'glz_cf_edit_type',
            array(
                0 => '',
                1 => 'glz_cf_js_configure_msg'  // Inline help string
            )
        ).
    inputLabel(
            'custom_set_position',
            fInput('text', 'custom_set_position', htmlspecialchars($custom_set_position), '', '', '', INPUT_MEDIUM, '', 'custom_set_position'),
            'glz_cf_edit_position',
            array(
                0 => '',
                1 => 'glz_cf_edit_position_hint'  // Inline help string
            )
        ).
    inputLabel(
            'custom_set_value',
            $value,
            'glz_cf_edit_value',
            array(
                0 => '',
                1 => $value_instructions  // Inline help string
            )
        ).
    n.tag(gTxt('glz_cf_js_script_msg'), 'span', array('class' => 'glz-custom-script-msg hidden')).
    n.tag(gTxt('glz_cf_js_textarea_msg'), 'span', array('class' => 'glz-custom-textarea-msg hidden')).
    hInput('event', 'glz_custom_fields').
    $custom_field.
    graf(
        $action,
        array('class' => 'txp-edit-actions')
    );

    echo form(join('', $out), '', '', 'post', 'txp-edit', '', 'add_edit_custom_field');

}
