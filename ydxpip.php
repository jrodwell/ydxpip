<?php

/**
 * Plugin Name: Yellow Dog Expert Agent Integration Plugin
 * Description: Fetch data file via FTP from Expert Agent, parse XML, and insert close matching fields into Wordpress custom posts. Runs manually and on cron to keep sync. See settings page (Settings - > YDXPIP) for instructions and tools.
 * Author: John Rodwell
 * Author URI: 
 * Version: 1.2.3
 * Plugin URI: 
 */

/*

Changelog:

V1.2.2  - deletes posts which are no longer present in the XML, and also uses different keys for the title.
V1.2.3  - bugfixes - performance of deletion - refactored
        - check existing images


Notes:

- Branch must always be the first one for now - will there ever be other branches?

- Fields do not line up exactly. For those fields which are not absolutely clear, editor should review...
    - Description is 'Advert One' in EA - further adverts are not used
    - Location is a composite of fields
    - If lat/long are blank, an approximate value is geolocated from the address
    - Property and lot size are not present in EA, nor is year of build
    - Concept of room description does not exist on WordPress frontend
    - Post thumbnail is first gallery image by default

- Cron runs hourly by default

- Still to do...
    - Spinner?
    - auto-publish feature?

- Tests...
    - Test image adding and deleting
    - Test floorplan adding and deleting

*/

// On activation set default cron frequency and auto publish

// import image functions etc into cron job

require_once(ABSPATH.  "wp-admin" . '/includes/image.php');
require_once(ABSPATH . "wp-admin" . '/includes/file.php');
require_once(ABSPATH . "wp-admin" . '/includes/media.php');
require_once(ABSPATH . "wp-admin" . '/includes/admin.php');

function ydxpip_activate() {
    if(!wp_next_scheduled('ydxpip_cron')) {
        wp_schedule_event(time(), 'hourly', 'ydxpip_cron');
    }
}

register_activation_hook(__FILE__, 'ydxpip_activate');

function ydxpip_deactivate() {
    wp_clear_scheduled_hook('ydxpip_cron');
}

register_deactivation_hook(__FILE__, 'ydxpip_deactivate');

// Prepare and use session to store saved post/objost data and report to admin screen

function start_session() {
    if(!session_id()) {
        session_start();
    }
}

add_action('init', 'start_session', 1);

function end_session() {
    session_destroy ();
}

add_action(‘wp_logout’, ‘end_session’);
add_action(‘wp_login’, ‘end_session’);

/* Main script action - sync with EE */

function run_ftp_sync() {

    /* get XML string */

    error_log("Running FTP sync...");

    $ftp = new ftp('ftp.expertagent.co.uk');

    $ret = $ftp->ftp_login('HavanaLuxuryVillas', '}CKPCM4Q6nTQ');
    if(!handle_admin_error($ret, "FTP")) { error_log("FTP failed"); return false; }

    $ret = $ftp->ftp_pasv(TRUE);
    if(!handle_admin_error($ret, "FTP")) { error_log("FTP failed"); return false; }

    ob_start();
    $ret = $ftp->ftp_get('php://output', "/properties2.xml", FTP_BINARY);
    $xmlstr = ob_get_contents();
    ob_end_clean();

    if(!handle_admin_error($ret, "FTP")) { error_log("FTP failed"); return false; }

    if(!$xmlstr) {
        $err = new WP_Error( 'ftp_failed', __('No XML retrieved.'));
        if(!handle_admin_error($ret, "XML")) { error_log("FTP failed"); return false; }
    }

    $properties = new SimpleXMLElement($xmlstr);

    /* Get all properties' EA reference numbers */

    $ee_refs = array();
    $args = array(
        'post_type' => 'estate',
        'post_status' => array('publish', 'draft'),
        'posts_per_page' => -1,
        'meta_key' => 'ee_reference'
        );
    $ref_query = new WP_Query($args);
    if($ref_query->have_posts()) : while($ref_query->have_posts()) :
        $ref_query->the_post();
        $ee_refs[] = array(
            'id' => get_the_ID(),
            'ref' => get_post_meta(get_the_ID(), 'ee_reference', true)
        );
    endwhile; endif;
    wp_reset_postdata();

    ob_start();
    var_dump($ee_refs);
    $ee_refs_str = ob_get_contents();
    ob_end_clean();

    error_log("EE REFS = ".$ee_refs_str);

    $properties_inserted = 0;

    $XML_properties = array();

    foreach($properties->branches->branch[0]->properties as $properties) {
        foreach($properties->property as $property) {

            /* Does property exist in the XML AND the site? */
            $property_exists = false;
            $property_reference = $property->property_reference->__toString();

            foreach($ee_refs as $ee_ref) {
                if($ee_ref['ref']==$property_reference) {
                    $property_exists = true;
                    $post_id = $ee_ref['id'];
                    $_SESSION['ydxpip_notices']['notices'][] = "Property $post_id exists.";
                    error_log("Property $post_id exists.");
                    break;
                }
            }

            // Title is a combination of house name and road name
            //$title = $property->advert_heading;          
            $housenum = $property->house_number;
            $roadname = $property->street;
            $title = $housenum.", ".$roadname;

            $property_type = $property->property_type;
            $department = $property->department;
            /* Department can be "Residential Sales" or "Residential Lettings" */
            if($department=="Residential Sales") {
                $offer_type = 'sale';
            } else {
                $offer_type = 'rent';
            }
            /* Description is 'Advert One/Main Advert' field */
            $description = $property->main_advert;
            $city = $property->town;
            $featured = $property->featuredProperty;
            $price = $property->numeric_price;
            /* Price normalised by removing decimal */
            $price = substr($price, 0, strpos($price, "."));
            $bedrooms = $property->bedrooms->__toString();
            $bathrooms = $property->bathrooms->__toString();
            $country = $property->country;
            $country = code_to_country($country);
            $district = $property->district->__toString();
            $lat = $property->latitude->__toString();
            $lng = $property->longitude->__toString();
            /* Address is a composite of three fields */
            $address = $city.", ".$district.", ".$country;
            /* If lat/lng are not set, compute an approximate value */
            if($lat=='0'&&$lng=='0') {
                //$_SESSION['ydxpip_notices']['notices'][] = "Lat/Lng is not set. Computing loaction from address '".$address."'...";
                //error_log("Lat/Lng is not set. Computing loaction from address '".$address."'...");
                $geo = get_geoloation($address);
                $loc = $geo[0]['geometry']['location'];
                $lat = $loc['lat'];
                $lng = $loc['lng'];
            }
            /* Location is serialised address, lat, lng */
            $location = array(
                'adddress' => $address,
                'lat' => $lat,
                'lng' => $lng
            );

            if(!$property_exists) {

                $_SESSION['ydxpip_notices']['notices'][] = "Doing post insert...";
                error_log("Doing post insert...");

                $post_id = wp_insert_post(array(
                   'post_type' => 'estate',
                   'post_title' => $title,
                   'post_content' => $description,
                   'post_status' => 'draft',
                   'comment_status' => 'open',
                   'ping_status' => 'open',
                ));

            } else {
                $changed_post = array(
                    'ID'           => $post_id,
                    'post_title'   => $title,
                    'post_content' => $description
                );
                wp_update_post($changed_post);
            }

            wp_set_object_terms($post_id, $property_type, 'property-type');
            wp_set_object_terms($post_id, $offer_type, 'offer-type');
            wp_set_object_terms($post_id, $city, 'city');
            update_post_meta($post_id, '_estate_featured', 'myhome_estate_featured');
            if($featured=='YES') {
                update_post_meta($post_id, 'estate_featured', 1);
            } else {
                update_post_meta($post_id, 'estate_featured', 0);
            }
            update_post_meta($post_id, '_estate_attr_price', 'myhome_estate_attr_price');
            update_post_meta($post_id, 'estate_attr_price', $price);
            update_post_meta($post_id, '_estate_attr_bedrooms', 'myhome_estate_attr_bedrooms');
            update_post_meta($post_id, 'estate_attr_bedrooms', $bedrooms);
            update_post_meta($post_id, '_estate_attr_bathrooms', 'myhome_estate_attr_bathrooms');
            update_post_meta($post_id, 'estate_attr_bathrooms', $bathrooms);
            //update_post_meta($post_id, '_estate_attr_property-size', 'myhome_estate_attr_property-size');
            //update_post_meta($post_id, 'estate_attr_property-size', $size);
            update_post_meta($post_id, '_estate_location', 'myhome_estate_location');
            update_post_meta($post_id, 'estate_location', $location);
            update_post_meta($post_id, 'ee_reference', $property_reference);

            /* Images */

            update_post_meta($post_id, '_estate_gallery', 'myhome_estate_gallery');
            $images = array();

            $_SESSION['ydxpip_notices']['notices'][] = "Checking images...";
            error_log("Checking images...");
            
            $existing_images_str = get_post_meta($post_id, 'estate_gallery', true);
            $existing_images_ids = explode(",", $existing_images_str);

            $existing_ids = array();
            $existing_images = array();
            foreach($existing_images_ids as $existing_images_id) {
                $existing_images[] = $existing_images_id;
                $existing_filenames[] = get_post_meta($existing_images_id, 'orig_filename', true);
            }

            //$_SESSION['ydxpip_notices']['notices'][] = "Existing filenames = ".implode(", ", $existing_filenames);
            //error_log("Existing filenames = ".implode(", ", $existing_filenames));
            
            foreach($property->pictures->picture as $picture) {

                /* Try to check if image exists... */

                $filename = basename($picture->filename);
                if(!in_array($filename, $existing_filenames)) {
                    //$_SESSION['ydxpip_notices']['notices'][] = "Uploading image $filename";
                    //error_log("Uploading image $filename");
                    $image_id = upload_image($picture->filename, $post_id);
                    update_post_meta($image_id, 'orig_filename', $filename);
                    if(is_wp_error($image_id)) {
                        $_SESSION['ydxpip_notices']['errors'][] = "Upload error: ".$image_id->get_error_message();
                        error_log("Upload error: ".$image_id->get_error_message());
                        break;
                    }
                    $images[] = $image_id;
                    update_post_meta($image_id, 'orig_filename', $filename);
                } else {
                    //$_SESSION['ydxpip_notices']['notices'][] = "Not uploading image $filename as it already exists";
                    //error_log("Not uploading image $filename as it already exists");
                }
            }

            $updated_images = array_unique(array_merge($existing_images, $images), SORT_REGULAR);
            $updated_images_str = implode(",", $updated_images);

            if(!empty($images)) {
                set_post_thumbnail($post_id, $updated_images[0]);
            }

            update_post_meta($post_id, 'estate_gallery', $updated_images_str);

            /* Floor plans (no redundancy check yet...) */

            update_post_meta($post_id, '_estate_plans', 'myhome_estate_plans');
            $num_existing_plans = get_post_meta($post_id, 'estate_plans', true);
            $num_plans = 0;
            foreach($property->floorplans->floorplan as $floorplan) {
                
                $filename = $floorplan->filename;
                $image_id = upload_image($filename, $post_id);
                if(is_wp_error($image_id)) {
                    $_SESSION['ydxpip_notices']['errors'][] = "Upload error: ".$image_id->get_error_message();
                    error_log("Upload error: ".$image_id->get_error_message());
                }
                $plan_name = "Undefined";

                $attributes = $floorplan->attributes();
                foreach($attributes as $key=>$value) {
                    if((string)$key=="name") $plan_name = $value->__toString();
                }

                $plan_name_key = "estate_plans_".$num_plans."_estate_plans_name";
                $plan_image_key = "estate_plans_".$num_plans."_estate_plans_image";

                update_post_meta($post_id, $plan_name_key, $plan_name);
                update_post_meta($post_id, $plan_image_key, $image_id);
                
                $num_plans++;

            }
            
            if($num_existing_plans>$num_plans) {
                for($i=$num_plans; $i<$num_existing_plans; $i++) {
                    $plan_name_key = "estate_plans_".$i."_estate_plans_name";
                    $plan_image_key = "estate_plans_".$i."_estate_plans_image";
                    delete_post_meta($post_id, $plan_name_key);
                    delete_post_meta($post_id, $plan_image_key);
                }
            }

            update_post_meta($post_id, 'estate_plans', $num_plans);

            /* Bullet points */

            $bullet1 = $property->bullet1->__toString();
            $bullet2 = $property->bullet2->__toString();
            $bullet3 = $property->bullet3->__toString();
            $bullet4 = $property->bullet4->__toString();
            $bullet5 = $property->bullet5->__toString();
            $bullet6 = $property->bullet6->__toString();
            $bullet7 = $property->bullet7->__toString();
            $bullet8 = $property->bullet8->__toString();
            $bullet9 = $property->bullet9->__toString();
            $bullet10 = $property->bullet10->__toString();

            $tags = array($bullet1, $bullet2, $bullet3, $bullet4, $bullet5, $bullet6, $bullet7, $bullet8, $bullet9, $bullet10);
            wp_set_post_terms($post_id, $tags, 'features');

            /* Complete notices */

            if($property_exists) {
                $_SESSION['ydxpip_notices']['notices'][] = "Updated property $post_id";
                error_log("Updated property $post_id");
            } else {
                $_SESSION['ydxpip_notices']['notices'][] = "Inserted property $post_id";
                error_log("Inserted property $post_id");
                $properties_inserted += 1;
            }

            $XML_properties[] = $post_id;

        }

    }

    $_SESSION['ydxpip_notices']['notices'][] = "Trashing redundant properties...";
    error_log("Trashing properties...");
    //$_SESSION['ydxpip_notices']['notices'][] = "XML properties = ".implode(", ", $XML_properties);
    //error_log("XML properties = ".implode(", ", $XML_properties));

    /* Trash properties no longer in the XML */

    foreach($ee_refs as $ee_ref) {
       if(!in_array($ee_ref['id'], $XML_properties)) {
            wp_trash_post($ee_ref['id']);
            $_SESSION['ydxpip_notices']['notices'][] = "Deleted property $post_id as it no longer exists in the XML";
            error_log("Deleted property $post_id as it no longer exists in the XML");
       }      
    }

    /* Send email on post insert */

    if($properties_inserted>0) {

        $_SESSION['ydxpip_notices']['notices'][] = "Sending email";
        error_log("Sending email"); 

        $email_to = get_option('admin_email');
        $email_subject = "New Properties added to HavannaLLP...";
        $email_text = "For your information '.$properties_inserted.' new properties have been inserted into HavannaLLP as drafts ready for your approval and publishing";
         
        $status = wp_mail($email_to, $email_subject, $email_text, $headers);
        if(!$status) {
            $_SESSION['ydxpip_notices']['notices'][] = "Email not sent";
            error_log("Email not sent"); 
        }
        
    }

    $_SESSION['ydxpip_notices']['notices'][] = "Done.";
    error_log("Done.");

}

// Add action for cron job

add_action('ydxpip_cron', 'run_ftp_sync');

// Add simple options page to run import

function ydxpip_plugin_menu() {
    add_options_page('Yellow Dog XML Properties Import Plugin Options', 'YDXPIP', 'manage_options', 'ydxpip__options', 'ydxpip_options_func');
}

function ydxpip_options_func() {

    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    // Options page here

    echo '<div class="wrap">';

    echo '<h1>Yellow Dog XML Properties Import Plugin</h1>';

    echo '<h4>Click here to grab the XML file and synchronise it right now:</h4>';

    echo '<form method="post" action="'.esc_url(admin_url("admin-post.php")).'">';

    echo '<input type="hidden" name="action" value="ftp_sync">';

    echo '<input type="hidden" name="run" value="1" />';

    submit_button('Run');

    echo '</form>';

    echo '<h4>Set cron frequency (the time between auto-runs of the synchronisation) here:</h4>';

    echo '<form method="post" action="">';

    $frequency = get_scheduled_event_frequency('ydxpip_cron');

    echo '<select name="frequency">';
    echo '<option value="hourly" ';
    if($frequency=='hourly') echo 'selected';
    echo '>Hourly</option>';
    echo '<option value="twicedaily" ';
    if($frequency=='twicedaily') echo 'selected';
    echo '>Twice daily</option>';
    echo '<option value="daily" ';
    if($frequency=='daily') echo 'selected';
    echo '>Daily</option>';
    echo '</select>';

    submit_button('Set');

    echo '</form>';

    echo "<h4>Notes:</h4>";

    echo "<ul>";
    echo "<li>Branch must always be the first one for now - will there ever be other branches?</li>";

    echo "<li>Fields do not line up exactly. For those fields which are not absolutely clear, editor should review...</li>";
        echo "<li><ul>";
        echo "<li> - Description is Advert One in EA</li>";
        echo "<li> - Title is a combination of house name/num and road name</li>";
        echo "<li> - Location is a composite of fileds</li>";
        echo "<li> - If lat/long are blank, an appriximate value is geolocated from the address</li>";
        echo "<li> - Property and lot size are not present in EA, nor is year</li>";
        echo "<li> - Post thumbnail is first gallery image by default</li>";
    echo "</ul></li>";

    echo "<li>Cron runs hourly by default</li>";

    echo "<li>Auto publish cannot be enabled in this version</li>";

    echo "</ul>";

    echo '</div>';
}

// Add plugin to settings

if (is_admin()) {
    add_action( 'admin_menu', 'ydxpip_plugin_menu' );
}

// Display admin notices

function ydxpip_custom_admin_notices() {

    if (array_key_exists('ydxpip_notices', $_SESSION)) {

        if(!empty($_SESSION['ydxpip_notices']['errors'])) {
            foreach($_SESSION['ydxpip_notices']['errors'] as $error) {
                ?><div class="error">
                    <p><?php echo $error; ?></p>
                </div><?php
            }
        }

        if(!empty($_SESSION['ydxpip_notices']['notices'])) {
            foreach($_SESSION['ydxpip_notices']['notices'] as $notice) {
                ?><div class="updated">
                    <p><?php echo $notice; ?></p>
                </div><?php
            }
        }

        unset($_SESSION['ydxpip_notices']);

    }

}

// Process form submit

function sync_form_submit() {
    if(!empty($_POST)) {

        if(isset($_POST['run'])) {
            run_ftp_sync();  
        }

        if(isset($_POST['frequency'])) {
            wp_clear_scheduled_hook('ydxpip_cron');
            wp_schedule_event(time(), $_POST['frequency'], 'ydxpip_cron');
        }

        wp_redirect($_SERVER['HTTP_REFERER']);
        exit();

    }
}

// Actions for admin page

add_action('admin_notices', 'ydxpip_custom_admin_notices', 10, 3);

add_action('admin_post_ftp_sync', 'sync_form_submit');

// Utility functions

function code_to_country($code) {

    $code = strtoupper($code);

    $countryList = array(
        'AF' => 'Afghanistan',
        'AX' => 'Aland Islands',
        'AL' => 'Albania',
        'DZ' => 'Algeria',
        'AS' => 'American Samoa',
        'AD' => 'Andorra',
        'AO' => 'Angola',
        'AI' => 'Anguilla',
        'AQ' => 'Antarctica',
        'AG' => 'Antigua and Barbuda',
        'AR' => 'Argentina',
        'AM' => 'Armenia',
        'AW' => 'Aruba',
        'AU' => 'Australia',
        'AT' => 'Austria',
        'AZ' => 'Azerbaijan',
        'BS' => 'Bahamas the',
        'BH' => 'Bahrain',
        'BD' => 'Bangladesh',
        'BB' => 'Barbados',
        'BY' => 'Belarus',
        'BE' => 'Belgium',
        'BZ' => 'Belize',
        'BJ' => 'Benin',
        'BM' => 'Bermuda',
        'BT' => 'Bhutan',
        'BO' => 'Bolivia',
        'BA' => 'Bosnia and Herzegovina',
        'BW' => 'Botswana',
        'BV' => 'Bouvet Island (Bouvetoya)',
        'BR' => 'Brazil',
        'IO' => 'British Indian Ocean Territory (Chagos Archipelago)',
        'VG' => 'British Virgin Islands',
        'BN' => 'Brunei Darussalam',
        'BG' => 'Bulgaria',
        'BF' => 'Burkina Faso',
        'BI' => 'Burundi',
        'KH' => 'Cambodia',
        'CM' => 'Cameroon',
        'CA' => 'Canada',
        'CV' => 'Cape Verde',
        'KY' => 'Cayman Islands',
        'CF' => 'Central African Republic',
        'TD' => 'Chad',
        'CL' => 'Chile',
        'CN' => 'China',
        'CX' => 'Christmas Island',
        'CC' => 'Cocos (Keeling) Islands',
        'CO' => 'Colombia',
        'KM' => 'Comoros the',
        'CD' => 'Congo',
        'CG' => 'Congo the',
        'CK' => 'Cook Islands',
        'CR' => 'Costa Rica',
        'CI' => 'Cote d\'Ivoire',
        'HR' => 'Croatia',
        'CU' => 'Cuba',
        'CY' => 'Cyprus',
        'CZ' => 'Czech Republic',
        'DK' => 'Denmark',
        'DJ' => 'Djibouti',
        'DM' => 'Dominica',
        'DO' => 'Dominican Republic',
        'EC' => 'Ecuador',
        'EG' => 'Egypt',
        'SV' => 'El Salvador',
        'GQ' => 'Equatorial Guinea',
        'ER' => 'Eritrea',
        'EE' => 'Estonia',
        'ET' => 'Ethiopia',
        'FO' => 'Faroe Islands',
        'FK' => 'Falkland Islands (Malvinas)',
        'FJ' => 'Fiji the Fiji Islands',
        'FI' => 'Finland',
        'FR' => 'France, French Republic',
        'GF' => 'French Guiana',
        'PF' => 'French Polynesia',
        'TF' => 'French Southern Territories',
        'GA' => 'Gabon',
        'GM' => 'Gambia the',
        'GE' => 'Georgia',
        'DE' => 'Germany',
        'GH' => 'Ghana',
        'GI' => 'Gibraltar',
        'GR' => 'Greece',
        'GL' => 'Greenland',
        'GD' => 'Grenada',
        'GP' => 'Guadeloupe',
        'GU' => 'Guam',
        'GT' => 'Guatemala',
        'GG' => 'Guernsey',
        'GN' => 'Guinea',
        'GW' => 'Guinea-Bissau',
        'GY' => 'Guyana',
        'HT' => 'Haiti',
        'HM' => 'Heard Island and McDonald Islands',
        'VA' => 'Holy See (Vatican City State)',
        'HN' => 'Honduras',
        'HK' => 'Hong Kong',
        'HU' => 'Hungary',
        'IS' => 'Iceland',
        'IN' => 'India',
        'ID' => 'Indonesia',
        'IR' => 'Iran',
        'IQ' => 'Iraq',
        'IE' => 'Ireland',
        'IM' => 'Isle of Man',
        'IL' => 'Israel',
        'IT' => 'Italy',
        'JM' => 'Jamaica',
        'JP' => 'Japan',
        'JE' => 'Jersey',
        'JO' => 'Jordan',
        'KZ' => 'Kazakhstan',
        'KE' => 'Kenya',
        'KI' => 'Kiribati',
        'KP' => 'Korea',
        'KR' => 'Korea',
        'KW' => 'Kuwait',
        'KG' => 'Kyrgyz Republic',
        'LA' => 'Lao',
        'LV' => 'Latvia',
        'LB' => 'Lebanon',
        'LS' => 'Lesotho',
        'LR' => 'Liberia',
        'LY' => 'Libyan Arab Jamahiriya',
        'LI' => 'Liechtenstein',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'MO' => 'Macao',
        'MK' => 'Macedonia',
        'MG' => 'Madagascar',
        'MW' => 'Malawi',
        'MY' => 'Malaysia',
        'MV' => 'Maldives',
        'ML' => 'Mali',
        'MT' => 'Malta',
        'MH' => 'Marshall Islands',
        'MQ' => 'Martinique',
        'MR' => 'Mauritania',
        'MU' => 'Mauritius',
        'YT' => 'Mayotte',
        'MX' => 'Mexico',
        'FM' => 'Micronesia',
        'MD' => 'Moldova',
        'MC' => 'Monaco',
        'MN' => 'Mongolia',
        'ME' => 'Montenegro',
        'MS' => 'Montserrat',
        'MA' => 'Morocco',
        'MZ' => 'Mozambique',
        'MM' => 'Myanmar',
        'NA' => 'Namibia',
        'NR' => 'Nauru',
        'NP' => 'Nepal',
        'AN' => 'Netherlands Antilles',
        'NL' => 'Netherlands the',
        'NC' => 'New Caledonia',
        'NZ' => 'New Zealand',
        'NI' => 'Nicaragua',
        'NE' => 'Niger',
        'NG' => 'Nigeria',
        'NU' => 'Niue',
        'NF' => 'Norfolk Island',
        'MP' => 'Northern Mariana Islands',
        'NO' => 'Norway',
        'OM' => 'Oman',
        'PK' => 'Pakistan',
        'PW' => 'Palau',
        'PS' => 'Palestinian Territory',
        'PA' => 'Panama',
        'PG' => 'Papua New Guinea',
        'PY' => 'Paraguay',
        'PE' => 'Peru',
        'PH' => 'Philippines',
        'PN' => 'Pitcairn Islands',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'PR' => 'Puerto Rico',
        'QA' => 'Qatar',
        'RE' => 'Reunion',
        'RO' => 'Romania',
        'RU' => 'Russian Federation',
        'RW' => 'Rwanda',
        'BL' => 'Saint Barthelemy',
        'SH' => 'Saint Helena',
        'KN' => 'Saint Kitts and Nevis',
        'LC' => 'Saint Lucia',
        'MF' => 'Saint Martin',
        'PM' => 'Saint Pierre and Miquelon',
        'VC' => 'Saint Vincent and the Grenadines',
        'WS' => 'Samoa',
        'SM' => 'San Marino',
        'ST' => 'Sao Tome and Principe',
        'SA' => 'Saudi Arabia',
        'SN' => 'Senegal',
        'RS' => 'Serbia',
        'SC' => 'Seychelles',
        'SL' => 'Sierra Leone',
        'SG' => 'Singapore',
        'SK' => 'Slovakia (Slovak Republic)',
        'SI' => 'Slovenia',
        'SB' => 'Solomon Islands',
        'SO' => 'Somalia, Somali Republic',
        'ZA' => 'South Africa',
        'GS' => 'South Georgia and the South Sandwich Islands',
        'ES' => 'Spain',
        'LK' => 'Sri Lanka',
        'SD' => 'Sudan',
        'SR' => 'Suriname',
        'SJ' => 'Svalbard & Jan Mayen Islands',
        'SZ' => 'Swaziland',
        'SE' => 'Sweden',
        'CH' => 'Switzerland, Swiss Confederation',
        'SY' => 'Syrian Arab Republic',
        'TW' => 'Taiwan',
        'TJ' => 'Tajikistan',
        'TZ' => 'Tanzania',
        'TH' => 'Thailand',
        'TL' => 'Timor-Leste',
        'TG' => 'Togo',
        'TK' => 'Tokelau',
        'TO' => 'Tonga',
        'TT' => 'Trinidad and Tobago',
        'TN' => 'Tunisia',
        'TR' => 'Turkey',
        'TM' => 'Turkmenistan',
        'TC' => 'Turks and Caicos Islands',
        'TV' => 'Tuvalu',
        'UG' => 'Uganda',
        'UA' => 'Ukraine',
        'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',
        'US' => 'United States of America',
        'UM' => 'United States Minor Outlying Islands',
        'VI' => 'United States Virgin Islands',
        'UY' => 'Uruguay, Eastern Republic of',
        'UZ' => 'Uzbekistan',
        'VU' => 'Vanuatu',
        'VE' => 'Venezuela',
        'VN' => 'Vietnam',
        'WF' => 'Wallis and Futuna',
        'EH' => 'Western Sahara',
        'YE' => 'Yemen',
        'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe'
    );

    if( !$countryList[$code] ) return $code;
    else return $countryList[$code];
}

function get_geoloation($address) {
    $url = "http://maps.google.com/maps/api/geocode/json?sensor=false&address=" . urlencode($address);
    $json = file_get_contents($url);
    $data = json_decode($json, TRUE);
    if($data['status']=="OK"){
        return $data['results'];
    }
}

function get_scheduled_event_frequency($hook) {
    $schedule  = wp_get_schedule($hook);
    return $schedule;
    $schedules = wp_get_schedules();
    return isset($schedules[$schedule]) ? $schedules[$schedule]['interval'] : false;
}

function upload_image($file, $post_id) {

    // Set variables for storage, fix file filename for query strings
    preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches);
    if (!$matches) {
         return new WP_Error( 'image_sideload_failed', __('Invalid image URL'));
    }

    $file_array = array();
    $file_array['name'] = basename($matches[0]);

    // Download file to temp location
    $file_array['tmp_name'] = download_url($file);

    // If error storing temporarily, return the error
    if(is_wp_error($file_array['tmp_name'])) {
        return $file_array['tmp_name'];
    }

    // Do the validation and storage stuff
    $id = media_handle_sideload($file_array, $post_id);

    // If error storing permanently, unlink
    if(is_wp_error($id)) {
        @unlink($file_array['tmp_name']);
    }

    return $id;

}

class ftp {

    public $conn;

    public function __construct($url) {
        $this->conn = ftp_connect($url);
    }
    
    public function __call($func, $a){
        if(strstr($func, 'ftp_') !== false && function_exists($func)) {
            array_unshift($a, $this->conn);
            $ret = call_user_func_array($func, $a);
            if(!$ret) {
                if($func=='ftp_login') {
                    return new WP_Error( 'ftp_failed', __('Connect failed. Most likely username/password is incorrect.'));
                } else if($func=='ftp_passv') {
                    return new WP_Error( 'ftp_failed', __('Passive mode failed. Most likely server does not support it.'));
                } else if($func=='ftp_get') {
                    return new WP_Error( 'ftp_failed', __('GET failed. Most likely file does not exist.'));
                }
            } else {
                return true;
            }
        } else {
            return new WP_Error('ftp_failed', $func.__(' is not a valid FTP function.'));
        }
    }
}

function handle_admin_error($err, $type) {
    if(is_wp_error($err)) {
        if(!$type) { $type = "General"; }
        $_SESSION['ydxpip_notices']['errors'][] = $type." error: ".$err->get_error_message();
        return false;
    } else {
        return true;
    }
}

function ydxpip_cron_schedules($schedules) {
    if(!isset($schedules["10min"])) {
        $schedules["10min"] = array(
            'interval' => 10*60,
            'display' => __('Once every 10 minutes'));
    }
    if(!isset($schedules["30min"])) {
        $schedules["30min"] = array(
            'interval' => 30*60,
            'display' => __('Once every 30 minutes'));
    }
    return $schedules;
}

add_filter('cron_schedules', 'ydxpip_cron_schedules');

?>
