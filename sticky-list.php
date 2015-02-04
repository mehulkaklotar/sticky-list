<?php
/*
Plugin Name: Gravity Forms Sticky List
Plugin URI: https://github.com/13pixlar/sticky-list
Description: List and edit submitted entries from the front end
Version: 1.1.5
Author: 13pixar
Author URI: http://13pixlar.se
*/


/* Todo
 * Support for file multiple uploads
 * Support for GF 1.9 "Save and Continue" functionallity
 * Support for multi page forms
 */

//------------------------------------------
if (class_exists("GFForms")) {
    GFForms::include_addon_framework();

    class StickyList extends GFAddOn {

        protected $_version = "1.1.5";
        protected $_min_gravityforms_version = "1.8.19.2";
        protected $_slug = "sticky-list";
        protected $_path = "gravity-forms-sticky-list/sticky-list.php";
        protected $_full_path = __FILE__;
        protected $_title = "Gravity Forms Sticky List";
        protected $_short_title = "Sticky List";

        public function init(){
            parent::init();

            // Add localization
            $this->stickylist_localize();
            
            // Add setting to fields settings tab
            add_action("gform_field_standard_settings", array( $this, "stickylist_field_settings"), 10, 2);

            // Add the Sticky List shortcode
            add_shortcode( 'stickylist', array( $this, 'stickylist_shortcode' ) );

            // Add supporting scripts to field settings page
            add_action("gform_editor_js", array($this, "editor_script"));

            // Add field settings page tooltips
            add_filter("gform_tooltips", array( $this, "add_stickylist_tooltips"));

            // Add css
            add_action("wp_enqueue_scripts", array( $this, "register_plugin_styles"));

            // View or Edit entries
            add_filter("gform_pre_render", array($this,"pre_entry_action"));
            add_action("gform_post_submission", array($this, "post_edit_entry"), 10, 2);

            // Delete entries
            $this->maybe_delete_entry();

            // Add notification options
            add_action("gform_notification_ui_settings", array($this, "stickylist_gform_notification_ui_settings"), 10, 3 );
            add_action("gform_pre_notification_save", array($this, "stickylist_gform_pre_notification_save"), 10, 2 );
            add_filter("gform_disable_notification", array($this, "stickylist_gform_disable_notification" ), 10, 4 );

            // Add confirmation options
            add_action("gform_confirmation_ui_settings", array($this, "stickylist_gform_confirmation_ui_settings"), 10, 3 );
            add_action("gform_pre_confirmation_save", array($this, "stickylist_gform_pre_confirmation_save"), 10, 2 );
            add_filter("gform_confirmation", array($this, "stickylist_gform_confirmation"), 10, 4);

            // Update connected Wordpress post if exsists
            add_filter("gform_post_data", array( $this, "stickylist_gform_post_data" ), 10, 3 );

            // Make sure required file fields validate when prepopulated whith existing file during edit
            add_filter('gform_validation', array( $this, "stickylist_validate_fileupload" ) );
        }


        /**
         * Sticky List localization function
         *
         */
        function stickylist_localize() {
            load_plugin_textdomain('sticky-list', false, basename( dirname( __FILE__ ) ) . '/languages' );
        }

        
        /**
         * Sticky List field settings function
         *
         */
        function stickylist_field_settings($position, $form_id){

            // Get the form
            $form = GFAPI::get_form($form_id);

            // Get form settings
            $settings = $this->get_form_settings($form);
                         
            // Only show settings if Sticky List is enabled for this form
            if(isset($settings["enable_list"]) && true == $settings["enable_list"]){
                
                // Show below everything else
                if($position == -1){ ?>
                    
                    <li class="list_setting">
                        Sticky List
                        <br>
                        <input type="checkbox" id="field_list_value" onclick="SetFieldProperty('stickylistField', this.checked);" /><label class="inline" for="field_list_value"><?php _e('Show in list', 'sticky-list'); ?> <?php gform_tooltip("form_field_list_value") ?></label>
                        <br>
                        <input type="checkbox" id="field_nowrap_value" onclick="SetFieldProperty('stickylistFieldNoWrap', this.checked);" /><label class="inline" for="field_nowrap_value"><?php _e('Dont wrap text from this field', 'sticky-list'); ?> <?php gform_tooltip("form_field_nowrap_value") ?></label>
                        <br>
                        <label class="inline" for="field_list_text_value"><?php _e('Column label', 'sticky-list'); ?> <?php gform_tooltip("form_field_text_value") ?></label><br><input class="fieldwidth-3" type="text" id="field_list_text_value" onkeyup="SetFieldProperty('stickylistFieldLabel', this.value);" />  
                    </li>
                    
                    <?php
                }
            }
        }

        
        /**
         * Sticky List field settings JQuery function
         *
         */
        function editor_script(){
            ?>
            <script type='text/javascript'>
                // Bind to the load field settings event to initialize the inputs
                jQuery(document).bind("gform_load_field_settings", function(event, field, form){
                    jQuery("#field_list_value").attr("checked", field["stickylistField"] == true);
                    jQuery("#field_nowrap_value").attr("checked", field["stickylistFieldNoWrap"] == true);
                    jQuery("#field_list_text_value").val(field["stickylistFieldLabel"]);
                });
            </script>
            <?php
        }

       
        /**
         * Sticky List field settings tooltips function
         *
         */   
        function add_stickylist_tooltips($tooltips){
           $tooltips["form_field_list_value"] = __('<h6>Show field in list</h6>Check this box to show this field in the list.','sticky-list');
           $tooltips["form_field_nowrap_value"] = __('<h6>Dont wrap whitespace</h6>Check this box to prevent wraping of text from this field','sticky-list');
           $tooltips["form_field_text_value"] = __('<h6>Header text</h6>Use this field to override the default text header.','sticky-list');
           return $tooltips;
        }

      
        /**
         * Sticky List shortcode function
         *
         */
        function stickylist_shortcode( $atts ) {
            $shortcode_id = shortcode_atts( array(
                'id' => '1',
                'user' => '',
            ), $atts );

            // Get the form ID from shortcode
            $form_id = $shortcode_id['id'];

            // Get the user ID from shortcode
            $user_id = $shortcode_id['user'];

            // Get the form
            $form = GFAPI::get_form($form_id);

            // Helper function to get and set settings
            function get_sticky_setting($setting_key, $settings) {
                if(isset($settings[$setting_key])) {
                    $setting = $settings[$setting_key];
                }else{
                    $setting = "";
                }
                return $setting;
            }

            // Get form settings
            $settings = $this->get_form_settings($form);

            // Setting variables
            $enable_list            = get_sticky_setting("enable_list", $settings);
            $show_entries_to        = get_sticky_setting("show_entries_to", $settings);
            $max_entries            = get_sticky_setting("max_entries", $settings);
            $enable_clickable       = get_sticky_setting("enable_clickable", $settings);
            $enable_postlink        = get_sticky_setting("enable_postlink", $settings);
            $link_label             = get_sticky_setting("link_label", $settings);
            $enable_view            = get_sticky_setting("enable_view", $settings);
            $enable_view_label      = get_sticky_setting("enable_view_label", $settings);
            $enable_edit            = get_sticky_setting("enable_edit", $settings);
            $enable_edit_label      = get_sticky_setting("enable_edit_label", $settings);
            $enable_delete          = get_sticky_setting("enable_delete", $settings);
            $enable_delete_label    = get_sticky_setting("enable_delete_label", $settings);
            $action_column_header   = get_sticky_setting("action_column_header", $settings);
            $enable_sort            = get_sticky_setting("enable_sort", $settings);
            $enable_search          = get_sticky_setting("enable_search", $settings);
            $embedd_page            = get_sticky_setting("embedd_page", $settings);
            $enable_pagination      = get_sticky_setting("enable_pagination", $settings);
            $page_entries           = get_sticky_setting("page_entries", $settings);

            // If a Custom embed url is set we override the selected embedd page
            if(isset($settings["custom_embedd_page"]) && $settings["custom_embedd_page"] != "") $embedd_page = $settings["custom_embedd_page"];
            
            // Only render list if Sticky List is enabled for this form
            if($enable_list){

                // Get current user or get user ID from shortcode
                if($user_id != "") {
                    $current_user_id = $user_id;
                }else{
                    $current_user = wp_get_current_user();
                    $current_user_id = $current_user->ID;    
                }

                //Set max nr of entries to be shown
                if($max_entries == "") { $max_entries = 999999; }

                // Set sorting and paging variables
                $sorting = array();
                $paging = array('offset' => 0, 'page_size' => $max_entries );

                   
                // Get entries to show depending on settings
                // Show only to creator
                if($show_entries_to === "creator"){

                    $search_criteria["field_filters"][] = array("key" => "status", "value" => "active");
                    $search_criteria["field_filters"][] = array("key" => "created_by", "value" => $current_user_id);

                    $entries = GFAPI::get_entries($form_id, $search_criteria, $sorting, $paging);
                
                // Show to all logged in users   
                }elseif($show_entries_to === "loggedin"){
                    
                    if(is_user_logged_in()) {
                        $search_criteria["field_filters"][] = array("key" => "status", "value" => "active");
                        $entries = GFAPI::get_entries($form_id, $search_criteria, $sorting, $paging);
                    }
                
                // Show to everyone
                }else{
                
                    $search_criteria["field_filters"][] = array("key" => "status", "value" => "active");
                    $entries = GFAPI::get_entries($form_id, $search_criteria, $sorting, $paging);
                }

                // If we have some entries, lets loop trough them and start building the output html
                if(!empty($entries)) {

                    // Allow for entries filtering
                    $entries = apply_filters( 'filter_entries', $entries );
                    
                    // This vaiable will hold all html for the form                
                    $list_html = "<div id='sticky-list-wrapper'>";
                    
                    // If sorting and searching is enabled, show search box        
                    if($enable_sort && $enable_search) {
                        $list_html .= "<input class='search' placeholder='" . __("Search", "sticky-list") . "' />";
                    }

                    $list_html .= "<table class='sticky-list'><thead><tr>";
                    
                    // Get all fields
                    $fields = $form["fields"];

                    // Make a counter for use in sorting
                    $i = 0;

                    // Make table header
                    foreach ($fields as $field) {

                        if(isset($field["stickylistField"]) && $field["stickylistField"] != "") {

                            // If we have a custom field label we use that, if not we use the fields standard label
                            if(isset($field["stickylistFieldLabel"]) && $field["stickylistFieldLabel"] != "") {                            
                                $label = $field["stickylistFieldLabel"];                                
                            }else{
                                $label = $field["label"];
                            }
                            
                            $list_html .= "<th class='sort' data-sort='sort-$i'>$label</th>";

                            // Increment sorting counter
                            $i++;
                        }
                    }

                    // If view, edit, delete or postlink is enabled we need an extra column
                    if($enable_view || $enable_edit || $enable_delete || $enable_postlink) {

                        $list_html .= "<th class='sticky-action'>$action_column_header</th>";
                    }

                    $list_html .= "</tr></thead><tbody class='list'>";

                    // Make table rows
                    foreach ($entries as $entry) {
                        
                        $entry_id = $entry["id"];

                        $list_html .= "<tr>";

                        // Recycle the sorting counter we used above
                        $i=0;

                        // Loop trough all the fields
                        foreach( $form["fields"] as $field ) {

                            // If the field is active 
                            if (isset($field["stickylistField"]) && $field["stickylistField"] != "") {
                                
                                // ...we get the value for it
                                $field_value = RGFormsModel::get_lead_field_value( $entry, $field );

                                // If nowrap is set for this field we add a class to it
                                $nowrap = "";
                                if(isset($field["stickylistFieldNoWrap"]) && $field["stickylistFieldNoWrap"] != "") {
                                    $nowrap = " sticky-nowrap";
                                }

                                // Set $custom_file_upload to true if this is a custom field file upload
                                if($field->type == "post_custom_field" && $field->inputType == "fileupload") { $custom_file_upload = true; }else{ $custom_file_upload = false; }

                                // If the value is an array (i.e. address field, name field, etc)
                                if(is_array($field_value)) {

                                    // Sort the array by key so that the fields are shown in the correct order
                                    ksort($field_value);
                                    $field_values = "";

                                    // Concatenate field values into string separated by a space
                                    foreach ($field_value as $field => $value) {
                                        $field_values .= $value . " ";
                                    }
                                    $list_html .= "<td class='sort-$i $nowrap'>$field_values</td>";
                                }

                                // If the field is a file field we use strtok to remove any metadata used by post_image filed (meta data is stored after "|" in string)
                                elseif ($field["type"] == "fileupload" || $field["type"] == "post_image" || $custom_file_upload = true ) {

                                    $field_value = strtok($field_value, "|");
                                    $file_name = basename($field_value);

                                    // Make file clickable or not
                                    if($enable_clickable) {
                                        $list_html .= "<td class='sort-$i $nowrap'><a href='$field_value'>$file_name</a></td>";
                                    }else{
                                        $list_html .= "<td class='sort-$i $nowrap'>$file_name</td>";
                                    }
                                }

                                // All other fields
                                else{ 
                                    $list_html .= "<td class='sort-$i $nowrap'>$field_value</td>";
                                }

                                // Increment sorting counter
                                $i++;
                            }
                        }

                        // If view, edit, delete or postlink is enabled we need a cell with appropiate links
                        if($enable_view || $enable_edit || $enable_delete || $enable_postlink){
                            
                            $list_html .= "<td class='sticky-action'>";

                                // Only show view link if view is enabled
                                if($enable_view) {
                                    $list_html .= "
                                        <form action='$embedd_page' method='post'>
                                            <button class='submit'>$enable_view_label</button>
                                            <input type='hidden' name='mode' value='view'>
                                            <input type='hidden' name='view_id' value='$entry_id'>
                                        </form>";
                                }

                                // Only show edit link if edit is enabled
                                if($enable_edit) {

                                    // ...and current user is the creator OR has the capability to edit others posts
                                    if($entry["created_by"] == $current_user->ID || current_user_can('edit_others_posts')) {
                                        $list_html .= "
                                            <form action='$embedd_page' method='post'>
                                                <button class='submit'>$enable_edit_label</button>
                                                <input type='hidden' name='mode' value='edit'>
                                                <input type='hidden' name='edit_id' value='$entry_id'>
                                            </form>";
                                    }
                                }

                                // Only show delete link if delete is enabled
                                if($enable_delete) {

                                    // ...and current user is the creator OR has the capability to delete others posts
                                    if($entry["created_by"] == $current_user->ID || current_user_can('delete_others_posts')) {
                                        
                                        $list_html .= "
                                            <button class='sticky-list-delete submit'>$enable_delete_label</button>
                                            <input type='hidden' name='delete_id' class='sticky-list-delete-id' value='$entry_id'>
                                        ";

                                        // If the entry is connected to a post we add a hidden field with the post ID
                                        if($entry["post_id"] != null ) {
                                            $delete_post_id = $entry["post_id"];
                                            $list_html .= "<input type='hidden' name='delete_post_id' class='sticky-list-delete-post-id' value='$delete_post_id'>";
                                        }
                                    }
                                }

                                // Only show post link if postlink is enabled and
                                if($enable_postlink && $entry["post_id"] != NULL) {

                                    $permalink = get_permalink($entry["post_id"]);
                                    $list_html .= "<button onclick='document.location.href=\"$permalink\"'>$link_label</button>";
                                }

                            $list_html .= "</td>";
                        }

                        $list_html .= "</tr>";
                    }

                    $list_html .= "</tbody></table>";

                    // If paignation is enabled we add the paignation container if there are more entries than what would fit in a page
                    if($enable_pagination && $page_entries < count($entries)) {
                        $list_html .= "<ul class='pagination'></ul>";
                    }

                    $list_html .= "</div>";


                    // If list sorting or pagination is enabled
                    if($enable_sort || $enable_pagination) {

                        // Build sort fields string
                        $sort_fileds = "";
                        for ($a=0; $a<$i; $a++) { 
                            $sort_fileds .= "'sort-$a',"; 
                        }

                        // Include list.js
                        $list_html .= "<script src='" . plugins_url( 'gravity-forms-sticky-list/js/list.min.js' ) . "'></script>";
                        
                        // Include list.js pagination plugin
                        if($enable_pagination) {
                            $list_html .= "<script src='" . plugins_url( 'gravity-forms-sticky-list/js/list.pagination.min.js' ) . "'></script>";
                        }

                        // If both sort and paignation is enabled
                        if($enable_sort && $enable_pagination) {
                            $list_html .= "<script>var options = { valueNames: [$sort_fileds], page: $page_entries, plugins: [ ListPagination({ outerWindow: 1 }) ] };var userList = new List('sticky-list-wrapper', options);</script><style>table.sticky-list th:not(.sticky-action) {cursor: pointer;}</style>";
                        
                        // If only sort is enabled
                        }elseif($enable_sort && !$enable_pagination) {
                            $list_html .= "<script>var options = { valueNames: [$sort_fileds] };var userList = new List('sticky-list-wrapper', options);</script><style>table.sticky-list th:not(.sticky-action) {cursor: pointer;}</style>";
                        
                        // If only paignation is enabled                        
                        }elseif(!$enable_sort && $enable_pagination) {                 
                            $list_html .= "<script>var options = { valueNames: ['xxx'], page: $page_entries, plugins: [ ListPagination({ outerWindow: 1 }) ] };var userList = new List('sticky-list-wrapper', options);</script></style>";
                        }
                    }

                    // If delete is enabled we need to insert ajax scripts to help with deletion
                    if($enable_delete) {

                        // Set som variables to use in the ajax function
                        $ajax_delete = plugin_dir_url( __FILE__ ) . 'ajax-delete.php';
                        $ajax_spinner = plugin_dir_url( __FILE__ ) . 'img/ajax-spinner.gif';
                        $delete_failed = __('Delete failed','sticky-list');

                        $list_html .= "
                            <img src='$ajax_spinner' style='display: none;'>
                            <script>
                            jQuery(document).ready(function($) {
                                $('.sticky-list-delete').click(function(event) {
                                    
                                    var delete_id = $(this).siblings('.sticky-list-delete-id').val();
                                    var delete_post_id = $(this).siblings('.sticky-list-delete-post-id').val();
                                    var current_button = $(this);
                                    var current_row = current_button.parent().parent();
                                    current_button.html('<img src=\'$ajax_spinner\'>');
                                    
                                    $.post( '', { mode: 'delete', delete_id: delete_id, delete_post_id: delete_post_id, form_id: '$form_id' })
                                    .done(function() {
                                        current_button.html('');
                                        current_row.css({   
                                            background: '#fbdcdc',
                                            color: '#fff'
                                        });
                                        current_row.hide('slow');
                                    })
                                    .fail(function() {
                                        current_button.html('$delete_failed');
                                    })

                                });
                            });
                            </script>
                        ";
                    }
                
                // If we dont have any entries, show the "Empty list" text to the user
                }else{
                    $list_html = $settings["empty_list_text"] . "<br>";
                }
                                    
                return $list_html;
            }
        }
        

        /**
         * Add Sticky List stylesheet
         *
         */
        public function register_plugin_styles() {
            wp_register_style( 'stickylist', plugins_url( 'gravity-forms-sticky-list/css/sticky-list_styles.css' ) );
            wp_enqueue_style( 'stickylist' );
        }


        /**
         * Performs actions when entrys are clicked in the list
         *
         */
        public function pre_entry_action($form) {
            
            if( isset($_POST["mode"]) == "edit" || isset($_POST["mode"]) == "view" ) {

                if($_POST["mode"] == "edit") {
                    $edit_id = $_POST["edit_id"];
                    $form_fields = GFAPI::get_entry($edit_id);
                }

                if($_POST["mode"] == "view") {
                    $view_id = $_POST["view_id"];
                    $form_fields = GFAPI::get_entry($view_id);
                }
        
                // Get current user
                $current_user = wp_get_current_user();
               
                // If we have an entry that is active
                if(!is_wp_error($form_fields) && $form_fields["status"] == "active") {
                    
                    // ...and the current user is the creator OR has the capability to edit others posts OR is viewing the entry
                    if($form_fields["created_by"] == $current_user->ID || current_user_can('edit_others_posts') || $_POST["mode"] == "view") {

                        // Loop trough the form fields and check for upload fields. If found, store ID in $uploads array
                        foreach ($form["fields"] as $fkey => &$fvalue) {
                            if($fvalue["type"] == 'fileupload' || $fvalue["type"] == "post_image") {
                                $uploads[] = $fvalue["id"];
                            }elseif ($fvalue["type"] == "post_custom_field" && $fvalue["inputType"] == "fileupload") {
                                $uploads[] = $fvalue["id"];
                            }
                        }

                        // This variable will hold upload fields                    
                        $upload_inputs = "";
                     
                        // Loop trough all the fields
                        foreach ($form_fields as $key => &$value) {

                            // If the key is numeric we need to change it from [X.X] to [input_X_X]
                            if (is_numeric($key)) {

                                // If the current field is a list field we need to unserialize it and flatten the array
                                if(is_array(maybe_unserialize($value))) {
                                    $list = maybe_unserialize($value);
                                    $value = iterator_to_array(new RecursiveIteratorIterator(new RecursiveArrayIterator($list)), FALSE);
                                }

                                // Format the key
                                $new_key = str_replace(".", "_", "input_$key");
                                $form_fields[$new_key] = $form_fields[$key];

                                // If the current field is an upload field we build the html do display it
                                $form_id = $form["id"];
                                if (is_array($uploads) && in_array( $key, $uploads ) ) {
                                    if ($value != "") {

                                        // Use strtok to remove any metadata used by post_image filed (meta data is stored after "|" in string)
                                        $path = strtok($value, "|");
                                        $file = basename($path);
                                        $delete_icon = plugin_dir_url( __FILE__ ) . 'img/delete.png';
                                        
                                        // Only show the remove icon if we are in edit mode
                                        if ($_POST["mode"] == "edit") {
                                            $show_delete = " <a title=\"" . __("Remove","sticky-list") . "\" class=\"remove-entry\"><img alt=\"" . __("Remove","sticky-list") . "\" src=\"$delete_icon\"></a>";
                                        }else{
                                            $show_delete = "";
                                        }

                                        $upload_inputs .= "$('input[name=\"$new_key\"]').before('<div class=\"file_$key\"><a href=\"$path\">$file</a>$show_delete<input name=\"file_$key\" type=\"hidden\" value=\"$value\"></div>');";
                                    }
                                }

                                // Unset old key
                                unset($form_fields[$key]);                    
                            }
                        }
                        
                        // Add is_submit_id field
                        $form_id = $form['id'];
                        $form_fields["is_submit_$form_id"] = "1";

                        // Get current form settings
                        $settings = $this->get_form_settings($form);

                        // Get update text
                        if(isset($settings["update_text"])) $update_text = $settings["update_text"]; else $update_text = ""; ?>

                        <!-- Add JQuery to help with view/update/delete -->
                        <script>
                        jQuery(document).ready(function($) {
                            var thisForm = $('#gform_<?php echo $form_id;?>')

                <?php   // If we are in edit mode we insert two hidden fields with entry id and mode = edit
                        if($_POST["mode"] == "edit") { ?>

                            thisForm.append('<input type="hidden" name="action" value="edit" />');
                            thisForm.append('<input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>" />');
                            thisForm.append('<input type="hidden" name="mode" value="edit" />');
                            $("#gform_submit_button_<?php echo $form_id;?>").val('<?php echo $update_text; ?>');

                <?php   }

                        // If we are in view mode we disable all inputs and hide the submit button        
                        if($_POST["mode"] == "view") { ?>

                            $("#gform_<?php echo $form_id;?> :input").attr("disabled", true);
                            $("#gform_submit_button_<?php echo $form_id;?>").css('display', 'none');
                <?php   }

                        // If we have a post ID it means that there is a post field present. We then insert a hidden field with the post ID for use later
                        if($form_fields["post_id"] != null ) { ?>

                            thisForm.append('<input type="hidden" name="post_id" value="<?php echo $form_fields["post_id"];?>" />');
                <?php   } 

                        // If we have one ore more upload fields we output the html to help with editing
                        if($upload_inputs != "") {
                            $upload_inputs .= "$('div[class^=\"file_\"] .remove-entry').click( function(event){ event.preventDefault; $(this).parent().remove();});";
                            echo $upload_inputs;
                        } ?>

                        });
                        </script>
                        <!-- End JQuery -->

                <?php   // Add our manipulated fields to the $_POST variable
                        $_POST = $form_fields;
                    }
                }
            }
            
            return $form;
        }


        /**
         *  Editing entries
         *
         */ 
        public function post_edit_entry($entry, $form) {
            
            // If we are in edit mode
            if(isset($_POST["action"]) && $_POST["action"] == "edit") {

                // Get original entry id
                $original_entry_id = $_POST["edit_id"];

                // Get current user
                $current_user = wp_get_current_user();
                
                // Get original entry
                $original_entry =  GFAPI::get_entry($original_entry_id);

                // If we have an original entry that is active 
                if($original_entry && $original_entry["status"] == "active") {

                    // ...and the current user is creator OR has the capability to edit others posts
                    if($original_entry["created_by"] == $current_user->ID || current_user_can('edit_others_posts')) {

                        // Keep starred and read status
                        $entry["is_read"] = $original_entry["is_read"];
                        $entry["is_starred"] = $original_entry["is_starred"];

                        // Look for existing file uploads and use them to keep the files
                        foreach ($_POST as $key => &$value) {
                            if (strpos($key, "file_") !== false) {
                                $entry[str_replace("file_", "", $key)] = $value;
                            }     
                        }

                        // Uppdate original entry with new fields
                        $success_uppdate = GFAPI::update_entry($entry, $original_entry_id);

                        // Empty the newly created entry before deletion (to keep attached files)
                        foreach ($entry as $key => &$value) {
                            
                            // Dont empty the ID or we wont be able to update and remove the entry
                            if ($key != "id") {
                                $entry[$key] = "";
                            }
                        }
                        
                        // Delete newly created entry
                        if($success_uppdate) {
                            $empty_the_entry = GFAPI::update_entry($entry, $entry["id"]);
                            $success_delete = GFAPI::delete_entry($entry["id"]);
                        } 
                    }
                }
            }
        }


        /**
         * Validate required file input fields
         *
         */
        function stickylist_validate_fileupload($validation_result) {

            // Get the validation results
            $form = $validation_result["form"];

            foreach($form['fields'] as &$field){

                // If we have a file upload field

                if($field->type == "post_custom_field" && $field->inputType == "fileupload") { $custom_file_upload = true; }else{ $custom_file_upload = false; }
                if($field->type == 'fileupload' || $field->type == "post_image"|| $custom_file_upload == true) {
                    
                    // If the field is not empty
                    if(rgpost("file_{$field['id']}") != "") {
                        
                        // Remove isRequired and set failed_validation to false
                        $field["isRequired"] = 0;                     
                        $field['failed_validation'] = false;

                        // Set the whole form as valid
                        $validation_result["is_valid"] = true;
                    }
                }
            }

            // Save our updated form back to the validation results
            $validation_result['form'] = $form;

            // Recheck all fields and set form to not valid if these is a non valid field
            foreach($form['fields'] as &$field) {
                if ($field['failed_validation'] == true) {
                    $validation_result["is_valid"] = false;
                    break;
                }
            }

            return $validation_result;
        }


        /**
         * Sticky List update Wordpress post
         *
         */
        function stickylist_gform_post_data( $post_data, $form, $entry ) {

            // If post ID is set we need to update the post
            if (isset($_POST["post_id"])) {
                $post_id = $_POST["post_id"];
                $post_data['ID'] = $post_id;

                // To prevent duplicate post meta when a form has custom field fields we need to remove the previous meta prior to saving.
                delete_post_meta($post_id, "_gform-entry-id");
                delete_post_meta($post_id, "_gform-form-id");
                $form_fields = $form["fields"];
                foreach ($form_fields as $form_field) {
                    if($form_field->type == "post_custom_field") {
                        delete_post_meta($post_id, $form_field->postCustomFieldName);
                    }
                }

                // Get the post and check the comment status
                $this_post = get_post($post_id);
                $post_data["comment_status"] = $this_post->comment_status;
            }
            return ( $post_data );
        }


        /**
         * Delete entries
         * This function is used to delete entries with an ajax request
         * Could use better (or at least some) error handling
         */
        public function maybe_delete_entry() {
            
            // First we make sure that delete mode is set to "delete" and that we have the entry id and form id
            if(isset($_POST["mode"]) && $_POST["mode"] == "delete" && isset($_POST["delete_id"]) && isset($_POST["form_id"])) {

                // Get form id
                $form_id = $_POST["form_id"];

                // Get the form
                $form = GFAPI::get_form($form_id);

                // Get delete settings
                $settings = $this->get_form_settings($form);
                $enable_delete = $settings["enable_delete"];
                $delete_type = $settings["delete_type"];

                // Make sure that delete is enabled
                if($enable_delete) {

                    $delete_id = $_POST["delete_id"];                
                    $current_user = wp_get_current_user();
                    $entry = GFAPI::get_entry($delete_id);
                    
                    // If we were able to retrieve the entry
                    if(!is_wp_error($entry)) {

                        // ...and the current user is the creator OR has the capability to delete others posts
                        if($entry["created_by"] == $current_user->ID || current_user_can('delete_others_posts' )) {

                            // If we have a connected post, we get the post ID
                            if($_POST["delete_post_id"] != null) {
                                $delete_post_id = $_POST["delete_post_id"];
                            }else{
                                $delete_post_id = "";
                            }
                           
                            // Move to trash
                            if($delete_type == "trash") { 
                                $entry["status"] = "trash";
                                $success = GFAPI::update_entry($entry, $delete_id);

                                // If we have a connected post, we move it to trash
                                if($delete_post_id != "") {
                                    wp_delete_post( $delete_post_id, false );
                                }
                            }

                            // Delete permanently
                            if($delete_type == "permanent") {
                                $success = GFAPI::delete_entry($delete_id);

                                // if we have a connected post, we delete it permanently
                                if($delete_post_id != "") {
                                     wp_delete_post( $delete_post_id, true );
                                }
                            }

                            // If delete (regardles of type) was successful, we send the notification (if any)
                            if($success) {

                                // Get all notifications for current form
                                $notifications = $form["notifications"];
                                $notification_ids = array();
                                
                                // Loop trough the notifications 
                                foreach ($notifications as $notification) {

                                    // Gett current notification type
                                    $notification_type = $notification["stickylist_notification_type"];

                                    // Collect ids from notifications that are set to "all" or "delete"
                                    if($notification_type == "delete" || $notification_type == "all") {
                                        $id = $notification["id"];
                                        array_push($notification_ids, $id);        
                                    }
                                }
                                
                                // Send the notification(s)
                                GFCommon::send_notifications($notification_ids, $form, $entry);
                            }          
                        }
                    }
                }
            }
        }


        /**
         * Form settings page
         *
         */
        public function form_settings_fields($form) {
            ?>
            <script>
            // Instert headers into the settings page. Since we need the headers to be translatable we set them here
            jQuery(document).ready(function($) { 
                $('#gaddon-setting-row-header-0 h4').html('<?php _e("General settings","sticky-list"); ?>')
                $('#gaddon-setting-row-header-1 h4').html('<?php _e("View, edit & delete","sticky-list"); ?>')
                $('#gaddon-setting-row-header-2 h4').html('<?php _e("Labels","sticky-list"); ?>')
                $('#gaddon-setting-row-header-3 h4').html('<?php _e("Sort & search","sticky-list"); ?>')
                $('#gaddon-setting-row-header-4 h4').html('<?php _e("Pagination","sticky-list"); ?>')
                $('#gaddon-setting-row-header-5 h4').html('<?php _e("Donate","sticky-list"); ?>')
                $('#gaddon-setting-row-donate .donate-text').html('<?php _e("Sticky List is completely free. But if you like, you can always <a target=\"_blank\" href=\"https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=8R393YVXREFN6\">donate</a> a few bucks.","sticky-list"); ?>')
             });
            </script>
            <?php

            // Build an array of all post to allow for selection in "embedd page" dropdown
            $args = array( 'posts_per_page' => 999999, 'post_type' => 'any', 'post_status' => 'any', 'orderby' => 'title'); 
            $posts = get_posts( $args );
            $posts_array = array();
            foreach ($posts as $post) {
                $post_title = get_the_title($post->ID);
                $post_url = get_permalink($post->ID);

                // We do not want attachments
                if($post->post_type != 'attachment') {
                    $posts_array = array_merge(
                        array(
                            array(
                                "label" => $post_title,
                                "value" => $post_url
                            )
                        ),$posts_array);
                }
            }
            
            return array(
                array(
                    "title"  => __('Sticky List Settings','sticky-list'),
                    "fields" => array(
                        array(
                            "label"   => __('Enable for this form','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_list",
                            "tooltip" => __('Check this box to enable Sticky List for this form','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => "",
                                    "name"  => "enable_list"
                                )
                            )
                        ),
                        array(
                            "label"   => __('Show entries in list to','sticky-list'),
                            "type"    => "select",
                            "name"    => "show_entries_to",
                            "tooltip" => __('Who should be able to se the entries in the list?','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Entry creator','sticky-list'),
                                    "value" => "creator"
                                ),
                                array(
                                    "label" => __('All logged in users','sticky-list'),
                                    "value" => "loggedin"
                                ),
                                array(
                                    "label" => __('Everyone','sticky-list'),
                                    "value" => "everyone"
                                )
                            )
                        ),
                        array(
                            "label"   => __('Embedd page/post','sticky-list'),
                            "type"    => "select",
                            "name"    => "embedd_page",
                            "tooltip" => __('The page/post where the form is embedded. This page will be used to view/edit the entry','sticky-list'),
                            "choices" => $posts_array
                        ),
                        array(
                            "label"   => __('Custom url','sticky-list'),
                            "type"    => "text",
                            "name"    => "custom_embedd_page",
                            "tooltip" => __('Manually input the url of the form. This overrides the selection made in the dropdown above. Use this if you cannot find the page/post in the list.','sticky-list'),
                            "class"   => "medium"
                        ),
                        array(
                            "label"   => __('Max nr of entries','sticky-list'),
                            "type"    => "text",
                            "name"    => "max_entries",
                            "tooltip" => __('Maximum number of entries to be shown in the list.','sticky-list'),
                            "class"   => "small"
                        ),
                        array(
                            "label"   => __('Make files clickable','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_clickable",
                            "tooltip" => __('Check this box to make uploaded files that are shown in the list clickable','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Enabled','sticky-list'),
                                    "name"  => "enable_clickable"
                                )
                            )
                        ),
                        array(
                            "label"   => __('Link to post','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_postlink",
                            "tooltip" => __('Check this box to insert a link to the WordPress post in the action column. Only applicable if the list actually contains WordPress posts.','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Enabled','sticky-list'),
                                    "name"  => "enable_postlink"
                                )
                            )
                        ),
                        array(
                            "label"   => __('Link label','sticky-list'),
                            "type"    => "text",
                            "name"    => "link_label",
                            "tooltip" => __('Label for the post link.','sticky-list'),
                            "class"   => "small",
                            "default_value" => __('Post','sticky-list')
                        ),
                        array(
                            "label"   => __('View entries','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_view",
                            "tooltip" => __('Check this box to enable users to view the complete submitted entry. A \"View\" link will appear in the list','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Enabled','sticky-list'),
                                    "name"  => "enable_view"
                                )
                            )
                        ),
                        array(
                            "label"   => __('View label','sticky-list'),
                            "type"    => "text",
                            "name"    => "enable_view_label",
                            "tooltip" => __('Label for the view button','sticky-list'),
                            "class"   => "small",
                            "default_value" => __('View','sticky-list')
                            
                        ),
                        array(
                            "label"   => __('Edit entries','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_edit",
                            "tooltip" => __('Check this box to enable user to edit submitted entries. An \"Edit\" link will appear in the list','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Enabled','sticky-list'),
                                    "name"  => "enable_edit"
                                )
                            )
                        ),
                        array(
                            "label"   => __('Edit label','sticky-list'),
                            "type"    => "text",
                            "name"    => "enable_edit_label",
                            "tooltip" => __('Label for the edit button','sticky-list'),
                            "class"   => "small",
                            "default_value" => __('Edit','sticky-list')
                            
                        ),
                         array(
                            "label"   => __('Update button text','sticky-list'),
                            "type"    => "text",
                            "name"    => "update_text",
                            "tooltip" => __('Text for the submit button that is displayed when editing an entry','sticky-list'),
                            "class"   => "small",
                            "default_value" => __('Update','sticky-list')              
                        ),
                        array(
                            "label"   => __('Delete entries','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_delete",
                            "tooltip" => __('Check this box to enable user to delete submitted entries. A \"Delete\" link will appear in the list','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Enabled','sticky-list'),
                                    "name"  => "enable_delete"
                                )
                            )
                        ),
                        array(
                            "label"   => __('Delete label','sticky-list'),
                            "type"    => "text",
                            "name"    => "enable_delete_label",
                            "tooltip" => __('Label for the delete button','sticky-list'),
                            "class"   => "small",
                            "default_value" => __('Delete','sticky-list')
                        ),
                        array(
                            "label"   => __('On delete','sticky-list'),
                            "type"    => "select",
                            "name"    => "delete_type",
                            "tooltip" => __('Move deleted entries to trash or delete permanently?','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Move to trash','sticky-list'),
                                    "value" => "trash"
                                ),
                                array(
                                    "label" => __('Delete permanently','sticky-list'),
                                    "value" => "permanent"
                                )
                            )
                        ),
                        array(
                            "label"   => __('Action column header','sticky-list'),
                            "type"    => "text",
                            "name"    => "action_column_header",
                            "tooltip" => __('Text to show as header for the action column','sticky-list'),
                            "class"   => "medium"
                            
                        ),
                        array(
                            "label"   => __('Empty list text','sticky-list'),
                            "type"    => "text",
                            "name"    => "empty_list_text",
                            "tooltip" => __('Text that is shown if the list is empty','sticky-list'),
                            "class"   => "medium",
                            "default_value" => __('The list is empty. You can edit or remove this text in settings','sticky-list')
                        ),
                        array(
                            "label"   => __('List sort','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_sort",
                            "tooltip" => __('Check this box to enable sorting for the list','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Enabled','sticky-list'),
                                    "name"  => "enable_sort"
                                )
                            )
                        ),
                        array(
                            "label"   => __('List search','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_search",
                            "tooltip" => __('Check this box to enable search for the list','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Enabled','sticky-list'),
                                    "name"  => "enable_search"
                                )
                            )
                        ),
                        array(
                            "label"   => __('List pagination','sticky-list'),
                            "type"    => "checkbox",
                            "name"    => "enable_pagination",
                            "tooltip" => __('Check this box to enable pagination for the list','sticky-list'),
                            "choices" => array(
                                array(
                                    "label" => __('Enabled','sticky-list'),
                                    "name"  => "enable_pagination"
                                )
                            )
                        ),
                        array(
                            "label"   => __('Entries per page','sticky-list'),
                            "type"    => "text",
                            "name"    => "page_entries",
                            "tooltip" => __('Number of entries to be shown on each page.','sticky-list'),
                            "class"   => "small",
                            "default_value" => "10"
                        )
                    )
                )
            );
        }


        /**
         * Include admin scripts
         *
         */
        public function scripts() {
        $scripts = array(
            array("handle" => "sticky_list_js",
                "src" => $this->get_base_url() . "/js/sticky-list_scripts.js",
                "version" => $this->_version,
                "deps" => array("jquery"),
                "enqueue" => array(
                    array(
                        "admin_page" => array("form_settings"),
                        "tab" => "sticky-list"
                        )
                    )
                ),
            );
            return array_merge(parent::scripts(), $scripts);
        }


        /**
         * Include admin css
         *
         */
        public function styles() {
            $styles = array(
                array("handle" => "sticky-list_admin_styles",
                    "src" => $this->get_base_url() . "/css/sticky-list_admin_styles.css",
                    "version" => $this->_version,
                    "enqueue" => array(
                    array(
                        "admin_page" => array("form_settings"),
                        "tab" => "sticky-list"
                        )
                    )
                )
            );
            return array_merge(parent::styles(), $styles);
        }


        /**
         * Add new notification settings
         *
         */
        function stickylist_gform_notification_ui_settings( $ui_settings, $notification, $form ) {

            $settings = $this->get_form_settings($form);

            if (isset($settings["enable_list"])) {

                // Add new notification options    
                $type = rgar( $notification, 'stickylist_notification_type' );
                $options = array(
                    'all' => __( "Always", 'sticky-list' ),
                    'new' => __( "When a new entry is submitted", 'sticky-list' ),
                    'edit' => __( "When an entry is updated", 'sticky-list' ),
                    'delete' => __( "When an entry is deleted", 'sticky-list' )
                );

                $option = '';

                // Loop trough the options
                foreach ( $options as $key => $value ) {
                    
                    $selected = '';
                    if ( $type == $key ) $selected = ' selected="selected"';
                    $option .= "<option value=\"{$key}\" {$selected}>{$value}</option>\n";
                }

                // Oputput the new setting
                $ui_settings['sticky-list_notification_setting'] = '
                <tr>
                    <th><label for="stickylist_notification_type">' . __( "Send this notification", 'sticky-list' ) . '</label></th>
                    <td><select name="stickylist_notification_type" value="' . $type . '">' . $option . '</select></td>
                </tr>';              
            }  

            return ( $ui_settings );
        }


        /**
         * Save the notification settings
         *
         */
        function stickylist_gform_pre_notification_save($notification, $form) {

            $notification['stickylist_notification_type'] = rgpost( 'stickylist_notification_type' );
            return ( $notification );
        }


        /**
         * Send selected notification type
         *
         */
        function stickylist_gform_disable_notification( $is_disabled, $notification, $form, $entry ) {

            // Get form settings
            $settings = $this->get_form_settings($form);

            // Only send notifications if Sticky List is enabled for the current form
            if(isset($settings["enable_list"])) {
                
                if(isset($notification["stickylist_notification_type"]) && $notification["stickylist_notification_type"] != "") {

                    $is_disabled = true;

                    // If we are in edit mode
                    if($_POST["action"] == "edit") {
                        
                        // ...and the current notification has the "edit" or "all" setting
                        if($notification["stickylist_notification_type"] == "edit" || $notification["stickylist_notification_type"] == "all") {
                            $is_disabled = false;
                        }

                    // Or if this is a new entry    
                    }else{
                        
                        // ...and the current notification has the "new" or "all" setting
                        if ( $notification["stickylist_notification_type"] == "new" || $notification["stickylist_notification_type"] == "all" ) {
                            $is_disabled = false;
                        }
                    }
                }           
            }

            return ( $is_disabled );
        }


        /**
         * Add new confirmation settings
         *
         */
        function stickylist_gform_confirmation_ui_settings( $ui_settings, $confirmation, $form ) {

            $settings = $this->get_form_settings($form);

            if (isset($settings["enable_list"])) {

                // Add new confirmation options    
                $type = rgar( $confirmation, 'stickylist_confirmation_type' );
               
                $options = array(
                    'all' => __( "Always", 'sticky-list' ),
                    'never' => __( "Never", 'sticky-list' ),
                    'new' => __( "When a new entry is submitted", 'sticky-list' ),
                    'edit' => __( "When an entry is updated", 'sticky-list' ),
                );

                $option = '';

                // Loop trough the options 
                foreach ( $options as $key => $value ) {
                    
                    $selected = '';
                    if ( $type == $key ) $selected = ' selected="selected"';
                    $option .= "<option value=\"{$key}\" {$selected}>{$value}</option>\n";
                }

                // Oputput the new setting
                $ui_settings['sticky-list_confirmation_setting'] = '
                <tr>
                    <th><label for="stickylist_confirmation_type">' . __( "Display this confirmation", 'sticky-list' ) . '</label></th>
                    <td><select name="stickylist_confirmation_type" value="' . $type . '">' . $option . '</select></td>
                </tr>';  
            }

            return ( $ui_settings );  
        }


        /**
         * Save the confirmation settings
         *
         */
        function stickylist_gform_pre_confirmation_save($confirmation, $form) {

            $confirmation['stickylist_confirmation_type'] = rgpost( 'stickylist_confirmation_type' );
            return ( $confirmation );
        }


        /**
         * Show confirmations
         *
         */
        function stickylist_gform_confirmation($original_confirmation, $form, $lead, $ajax){

            // Get form settings
            $settings = $this->get_form_settings($form);

            // Only show confirmations if Sticky List is enabled for the current form
            if(isset($settings["enable_list"])) {
            
                // Get all confirmations for the current form
                $confirmations = $form["confirmations"];
                $new_confirmation = "";

                // If action is not set we assume its a new entry
                if(!isset($_POST["action"])) {
                    $_POST["action"] = "new";
                }

                // Loop trough all confirmations
                foreach ($confirmations as $confirmation) {

                    // Get and set the confirmation type
                    if (isset($confirmation["stickylist_confirmation_type"])) {
                        $confirmation_type = $confirmation["stickylist_confirmation_type"];
                    }else{
                        $confirmation_type = "";
                    }

                    // Show matching confirmations
                    if( $confirmation_type == $_POST["action"] || $confirmation_type == "all" || !isset($confirmation["stickylist_confirmation_type"])) {
                        
                        // If the confirmation is a message we add that message to the output sting
                        if($confirmation["type"] == "message") {
                            $new_confirmation .= $confirmation["message"] . " ";

                        // If not, we set the redirect variable to true    
                        }else{
                            $new_confirmation = $original_confirmation;
                            break;
                        }
                    }             
                }

                // Apply merge tags to the confirmation message
                $new_confirmation = GFCommon::replace_variables($new_confirmation, $form, $lead);

                return $new_confirmation;

            }else{

                // If Sticky List is not enabled for the current form
                return $original_confirmation;
            }

        }
    }

    // Phew, thats it. Lets initialize the class
    new StickyList();
}
