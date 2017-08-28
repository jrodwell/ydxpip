<?php

/**
 * Plugin Name: Yellow Dog XML Properties Import Plugin
 * Description: Fetch XML file from 'expert agent', parse XML and insert close matching fields into Wordpress custom posts - please note some filds do not match and cannot be imported
 * Author: John Rodwell
 * Author URI: 
 * Version: 1.0
 * Plugin URI: 
 */

/*

Caveats:

- This plugin was written in half a day
- Branch must always be the first one for now - will there ever be other brances?
- Property must be for sale - only one example is given with the 'Department' set as 'Residential Sales', and there is no indication of what the value would be for a rental property.
- As above, many other fields do not match and cannot be filled, i.e. property has no description, title is not clear, property and lot size is not present, nor is year, location could be made from several fields but given location does not match exactly on Google maps, lat and long are blank making map useless (could possibly get from address via Google Maps API...), city is town and area is not included in the site but is in the XML - as are many others, pictures would have to be integrated with the gallery plugin and entered with a height, width, etc...
- Since no ID is given (not one which matches an existing field anyway) a new property will always be added! (use title field to match? Must guarentee title will never be the same... or add new ID field from XML?)
- Due to the above all new properties will be added as drafts via a settings page rather than a cron job.
- Summary; square peg, round hole.

*/

class ftp {

    public $conn;

    public function __construct($url) {
        $this->conn = ftp_connect($url);
    }
    
    public function __call($func, $a){
        if(strstr($func, 'ftp_') !== false && function_exists($func)) {
            array_unshift($a, $this->conn);
            return call_user_func_array($func, $a);
        } else {
            die("$func is not a valid FTP function.");
        }
    }
}

function runFTPUpdate() {

    $ftp = new ftp('ftp.expertagent.co.uk');
    if(!$ftp->ftp_login('HavanaLuxuryVillas', '}CKPCM4Q6nTQ')) {
    	die("Login failed.");
    }
    if(!$ftp->ftp_pasv(TRUE)) {
    	die("Passive FTP mode failed.");
    }

    //var_dump($ftp->ftp_nlist('/'));

    ob_start();
    $ftp->ftp_get('php://output', "/properties2.xml", FTP_BINARY);
    $xmlstr = ob_get_contents();
    ob_end_clean();

    /*
    $local_file_path = "/Users/johnrodwell/Sites/havanallp/test/wp-content/uploads/properties2.xml";
    if ($ftp->ftp_get($local_file_path, "/properties2.xml", FTP_BINARY)) {
        echo "File successfully written.\n";
    } else {
        die("FTP get failed.");
    }

    $xmlstr = file_get_contents($local_file_path);
    */

    if(!$xmlstr) {
        die("FTP get failed");
    }

    //echo $xmlstr;

    $properties = new SimpleXMLElement($xmlstr);

    foreach($properties->branches->branch[0]->properties as $properties) {
        foreach($properties->property as $property) {

            echo "<p>Processing property...</p>";

            $title = $property->advert_heading;
            $property_type = $property->property_type;
            $offer_type = 'sale'; //temp
            $city = $property->town;
            $featured = $property->featuredProperty;
            $price = $property->numeric_price;
            $price = substr($price, 0, strpos($price, "."));
            $bedrooms = $property->bedrooms->__toString();
            $bathrooms = $property->bathrooms->__toString();
            //$size = $property->estate_attr_property-size;
            $country = $property->country;
            $country = code_to_country($country);
            $district = $property->district->__toString();
            $location = array(
                'adddress' => '$city.", ".$district.", ".$country;',
                'lat' => $property->latitude->__toString(),
                'lng' => $property->longitude->__toString()
            );

            $post_id = wp_insert_post(array (
               'post_type' => 'estate',
               'post_title' => $title,
               'post_content' => '',
               'post_status' => 'draft',
               'comment_status' => 'open',
               'ping_status' => 'open',
            ));

            echo "<p>Inserted property $post_id</p>";

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

        }

        echo "<p>Done.</p>";

    }

}

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

    // Process form submit

    if(!empty($_POST)) {
        
        // Fire update

        runFTPUpdate();

    }

    echo '<h4>Click here to grab the XML file and import it - please note some fields do not match and cannot be imported:</h4>';

    echo '<form method="post" action="">';

    echo '<input type="hidden" name="run" value="1" />';

    submit_button('Run');

    echo '</form>';

    echo '</div>';
}

if (is_admin()) {
    add_action( 'admin_menu', 'ydxpip_plugin_menu' );
}

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


?>
