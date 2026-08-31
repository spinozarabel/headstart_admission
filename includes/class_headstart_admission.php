<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 */

/**
 * The core plugin class.
 *  1. The ticket (non-defualt) custom field names MUST exactly match the admin label from the Ninja forms
 *  2. The ticket (non-default) custom field names are used to derive the custom slugs for the CF as SLugs are not user settable.
 *  3. The status names in the code must exactly match the names in the status settings of Support Candy plugin
 *  4. The Category names used on the code must exactly match the names of the categories in the Support Candy plugin.
 *  5. The fees and cohorts etc., in the settings must be keyed correctly to category names, not slugs.
 *
 *  Statusses used: 'Admission Payment Process Completed' , 'Interaction Completed' , 'School Accounts Being Created'
 *                  'Admission Payment Order Being Created', 'Admission Granted', 'Admission Confirmed'
 *                  
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 * sc_v3p1
 * @since      1.0.0
 * @author     Madhu Avasarala
 */

 if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

//setup for the WooCommerce REST API
require MA_HSA_ABSPATH . 'vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

class headstart_admission
{
    // keeps the secret information for APIs etc.
    public static $config;

    // keeps the fees indexed by category name
    public static $category_fee_arr;

    // keeps the payment decsription keyed by category name
    public static $category_paymentdescription_arr;

    // keeps the cohortid that new students should be put into keyed by category name
    public static $category_cohortid_arr;

    // dictates the logging
    public static $verbose;

    // WP user object obtained by WooCommerce API from hset-payments intranet site
    public static $wp_user_hset_payments; // WooCommerce user object as returned by Woocommerce API

    // This is an array that is indexed by Status Name having value as status object
    public static $status_name_slug_array;

    // same thing indexed by status id with value as status object
     public static $status_id_name_array;

    // name of plugin
    public static $plugin_name;


    /**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct()
    {
        self::init();
	}

    public static function init()
    {
        // set the logging
        self::$verbose = false;
        
        self::$plugin_name = MA_HSA_PLUGIN_NAME;

        // load admin actions only if admin
		if ( is_admin() ) 
        {
            self::define_admin_hooks();
        }

        // load public facing actions
		self::define_public_hooks();

        // read the config file and build the secrets array
        self::get_config();

        // read the fee and description pairs from settings and form an associative array
        self::admission_settings();

        // Index ths status name vs slug and id vs name arrays
        // self::index_status_array();
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    public static function index_status_array()
    {
        // get an array of all statuses
        $status_objects = WPSC_Status::find( array( 'items_per_page' => 0 ) )['results'];

        $status_name_slug_array = [];
        $status_id_name_array = [];

        foreach ($status_objects as $status_object):
            
            // for each status object add a row indexed by status name
            $status_name_slug_array[$status_object->name] = $status_object->slug;

            $status_id_name_array[$status_object->id]     = $status_object->name;

        endforeach;

        // load the array as a static property to the static class
        self::$status_name_slug_array = $status_name_slug_array;
        self::$status_id_name_array   = $status_id_name_array;
    }




    /**
     * VISUALLY CHECKED for SC 3.0 compatibility
     * 
     * This function is sually called once, from the class constructor
     * Get settings values for fee and description based on category
     * This array will be used to look up fee and payment description agent fields, based on category
     */
    public static function admission_settings() : void
    {
        $setting_category_fee = get_option('headstart_admission_settings')['category_fee'];

        // each key=>value pair is on a separate line. So by exploding on EOL we get a numerical array with key=>value pairs
        $array1 = explode(PHP_EOL, $setting_category_fee); 

        // array_map takes array1 and makes it into an array of arrays by exploding => separator.
        // finally use the array_column to index the column 1 of each subarray indexed by column 0
        $category_fee_arr = array_column(array_map(function($row){
                                                                    return explode("=>", $row);
                                                                }, $array1), 1, 0);
        self::$category_fee_arr = $category_fee_arr;

        self::$verbose ? error_log( print_r( $category_fee_arr, true ) ) : false;

        $setting_category_paymentdescription = get_option('headstart_admission_settings')['category_paymentdescription'];

        $array2 = explode(PHP_EOL, $setting_category_paymentdescription); 

        $category_paymentdescription_arr = array_column(array_map(function($row){
                                                                                    return explode("=>", $row);
                                                                                }, $array2), 1, 0);

        self::$category_paymentdescription_arr = $category_paymentdescription_arr;

        self::$verbose ? error_log( print_r( $category_paymentdescription_arr, true ) ) : false;

        $setting_category_cohortid = get_option('headstart_admission_settings')['category_cohortid'];

        // array is an array each entry corresponding to a line of text: category=>cohortid
        $array3 = explode(PHP_EOL, $setting_category_cohortid); 

        // array_map takes array3 and makes it into an array of arrays by exploding => separator.
        // finally use the array_column to index the column 1 of each subarray indexed by column 0
        $category_cohortid_arr = array_column(array_map(function($row){
                                                                        return explode("=>", $row);
                                                                    }, $array3), 1, 0);

        self::$category_cohortid_arr = $category_cohortid_arr;

        self::$verbose ? error_log( print_r( $category_cohortid_arr, true ) ) : false;
    }


    /**
     * VISUALLY CHECKED for SC 3.0 compatibility
     * 
     * @return bool
     * This processes the webhook coming from site hset-payments on any order that is completed
     * It extracts the order ID and then gets the order from the hset-payments site.
     * From the order the ticket_id is extracted and it's status is updated.
     * The server originating the webhook IP and domain are checked in addition to the webhook signature
     */
    public static function webhook_order_complete_process() : bool
    {
        if ( $_SERVER['REMOTE_ADDR']              == '68.183.189.119' &&
             $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE'] == 'https://sritoni.org/hset-payments/'     
           )
        {
            // if you get here it means that origin IP and domain have been verified

            $signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'];

            $request_body = file_get_contents('php://input');

            $signature_verified = self::verify_webhook_signature($request_body, $signature);

            if ($signature_verified)
            {
                self::$verbose ? error_log("HSET order completed webhook signature verified") : false;

                // decoded as object
                $data = json_decode($request_body, false);  

                if ($data->action = "woocommerce_order_status_completed")
                {
                    $order_id = $data->arg;

                    self::$verbose ? error_log($data->action . " for Order: " . $order_id) : false;

                    // so we now have the order id for the completed order. Fetch the order object!
                    $order = self::get_wc_order($order_id);

                    // from the order, extract the admission number which is our ticket id
                    $ticket_id = $order->admission_number;

                    $new_status_name = 'Admission Payment Process Completed';

                    // change the status of the ticket to 'Admission Payment Process Completed'
                    self::change_ticket_status($ticket_id, $new_status_name);

                    // extract the transaction ID from the order
                    $transaction_id = str_replace (['{', '}'], ['', ''], $order->transaction_id);
 
                    // update the agent only fields payment-bank-reference which is really thee transaction_id
                    self::change_ticket_field($ticket_id, 'payment-bank-reference', $transaction_id);

                    // return after successful termination of webhook
                    return true;
                }
            }
            else 
            {
                self::$verbose ? error_log("HSET order completed webhook signature NOT verified") : false;
                die;
            }
        }
        else
        {
            self::$verbose ? error_log("HSET order completed webhook source NOT verified") : false;
            self::$verbose ? error_log($_SERVER['REMOTE_ADDR'] . " " . $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE']) : false;

            die;
        }
    }

    /**
     * 
     */
    public static function verify_webhook_signature( $request_body, $signature ): bool
    {
        $secret = self::$config['wc_webhook_secret'];

        // true if verified, false if not verified
        $is_signature_verified = base64_encode(hash_hmac('sha256', $request_body, $secret, true)) == $signature;

        return $is_signature_verified;
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     *  reads in a config php file and gets the API secrets. The file has to be in gitignore and protected
     *  The information is read into an associative arrray automatically by the nature of the process
     *  1. Key and Secret of Payment Gateway involved needed to ccheck/create VA and read payments
     *  2. Moodle token to access Moodle Webservices
     *  3. Woocommerce Key and Secret for Woocommerce API on payment server
     *  4. Webhook secret for order completed, from payment server
     */
    public static  function get_config()
    {
      $config = include( MA_HSA_ABSPATH  . MA_HSA_PLUGIN_NAME . "_" . "config.php" );

      if ($config)
      {
        self::$verbose ? error_log("Plugin Config file successfully loaded") : false;
      }
      else
      {
        error_log("Could NOT load Plugin Config File correctley, check...");
      }

      self::$config = $config;
    }



    /**
     * Define all of the admin facing hooks and filters required for this plugin
     * @return null
     */
    private  static function define_admin_hooks()
    {   // create a sub menu called Admissions in the Users menu
        add_action( 'admin_menu', array(__CLASS__, 'add_my_menu' ) );
    }

    /**
     * VISUALLY CHECKED for SC 3.0 compatibility
     * Define all of the public facing hooks and filters required for this plugin
     * @return null
     */
    private static function define_public_hooks() : void
    {
        // do_action( 'wpsc_change_ticket_status', self::$ticket, $prev, $new, $customer_id ); in class individual ticket SCandy
        // This is the main state control for this App
        add_action( 'wpsc_change_ticket_status',   array( __CLASS__, 'my_wpsc_change_ticket_status_action_callback' ), 10,4 );

        // check Ninja form data before it is saved
        // add_filter( 'ninja_forms_submit_data',      [$this, 'action_validate_ninja_form_data'] );

        /**
         * Fix Ninja Forms fatal error during CSV generation on PHP 8+
         * Intercepts date fields before NF_Fields_Date::filter_csv_value() receives them as arrays.
         */
        add_filter( 'ninja_forms_csv_field_date', function( $value, $field ) {
            if ( is_array( $field ) ) {
                // Return the formatted or raw date string directly from the array
                return $field['value'] ?? $field['settings']['value'] ?? $value;
            }
            return $value;
        }, 1, 2 ); // Priority 1 ensures this runs BEFORE Ninja Forms' internal filter (priority 10)
        

        // after a NInja form submission, its data is mapped to a support ticket
        // This is the principal source of data for subsequent actions such as account creation
        add_action( 'ninja_forms_after_submission', array( __CLASS__, 'map_ninja_form_to_ticket' ) );
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     *  @param int:$ticket_id
     *  @param string:$cf_name
     *  @param $value is the value to be assigned to the custom field whose name is passed in for the given ticket id.
     */
    public static function change_ticket_field( int $ticket_id, string $cf_name, $value): void
    {
        // build the ticket  using pased id parameter
        $ticket = new WPSC_Ticket( $ticket_id );

        // get the slug of the custom field using the custom field name of ticket passed in as parameter
        $cf_slug = self::get_cf_slug_by_cf_name( $cf_name );

        if ( $cf_slug )
        {
            // if the slug of the ticket's custom field is valid then set the new value for the ticket field
            $ticket->$cf_slug = $value;

            // set the update date and time
            $ticket->date_updated = new DateTime();

            // save the modified ticket
            $ticket->save();
        }
        else {
            // slug was not found for the given ticket custom field name. Log the issue and return
            error_log( "CF Slug non-existent for Ticket:" . $ticket_id . " for CF Name->" . $cf_name);
        }
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     *  Build the ticket using supplied ID and extract the status ID and customer ID information.
     *  Use the supplied new status name to extract its ID and change status
     * 
     *  @param int:$ticket_id
     *  @param string:$new_status_name is the name of the desired status that ticket needs to be changed to
     */
    public static function change_ticket_status( int $ticket_id, string $new_status_name ) : void
    {

    $desired_status_id = self::get_status_id_given_name( $new_status_name );

        $ticket = new WPSC_Ticket( $ticket_id );

        if ( ! $ticket->id ) {

            error_log( "Could NOT get id for Ticket:" . $ticket_id );

            return;
        }

        WPSC_Individual_Ticket::$ticket = $ticket;

        // only change status if new status id is valid AND not already same as existing status of ticket
        if ( $desired_status_id && $ticket->status->id != $desired_status_id ) {
            //                                   { prev status ID, new statusID, customer_id)}
            // includes the hook for 'wpsc_change_ticket_status'
            WPSC_Individual_Ticket::change_status( $ticket->status->id, $desired_status_id, $ticket->customer );
        }
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  Given a keyword, looks through the array and returns the index for a partial match. Returns false if no match found
     *  @param array:$arr is the array to be serached
     *  @param string:$keyword is the key to be serached for even a partial match
     *  @return mixed:$index the index of the array whose value matches at least partially with the given keyword or false
     */
    public static function array_search_partial($arr, $keyword) 
    {   // Given a keyword, looks through the array and returns the index for a partial match. Returns false if no match found
        foreach($arr as $index => $string) 
        {
            if (stripos($string, $keyword) !== FALSE)
                return $index;
        }
        return false;
    }


    

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$cf_name is the exact name of the custom field including case and spaces, whose id we desire
     *  @return int$cf_id is the id of the desired custom field that is returned If not found null is returned
     * 
     *  Given the name of an existing category, its integer ID is returned if it exists. If not, null is returned
     */
    public static function get_cf_id_given_name(string  $cf_name): ? int
    {
        $cf_id = null;    // initialize the return object

        // get an array of all statuses
        $cf_objects = WPSC_Custom_Field::find( array( 'items_per_page' => 0 ) )['results'];

        foreach ($cf_objects as $cf_object):
        
            if ( $cf_name == $cf_object->name)
            {
                self::$verbose ? ("Custom Field name - " . $cf_name . " Corresponds to ID: " . $cf_object->id) : false;

                $cf_id = $cf_object->id;    // capture the CF ID from object
                
                break;                                  // we found our match and so we exit the loop
            } 
        endforeach;

        return $cf_id;
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$status_name is the exact name of the status whose id we desire
     *  @return int$status_id is the id of the desired status that is returned If not found null is returned
     * 
     *  Given the name of an existing category, its integer ID is returned if it exists. If not, null is returned
     */
    public static function get_status_id_given_name(string  $status_name): ? int
    {
        $status_id = null;    // initialize the return variable

        // get an array of all statuses
        $status_objects = WPSC_Status::find( array( 'items_per_page' => 0 ) )['results'];

        foreach ($status_objects as $status_object):
        
            if ( $status_name == $status_object->name)
            {
                self::$verbose ? ("Status name - " . $status_name . " Corresponds to Status ID: " . $status_object->id) : false;

                $status_id = $status_object->id;    // capture the Status ID from Status object
                
                break;                              // we found our match and so we exit the loop
            } 
        endforeach;

        return $status_id;
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$category_name is the exact name of the category whose id we desire
     *  @return int$category_id is the id of the desired categoy that is returned If not found null is returned
     * 
     *  Given the name of an existing category, its integer ID is returned if it exists. If not, null is returned
     */
    public static function get_category_id_given_name( string  $category_name ): ? int
    {
        $category_id = null;    // initialize the return object

        // get an array of all statuses
        $category_objects = WPSC_Category::find( array( 'items_per_page' => 0 ) )['results'];

        foreach ($category_objects as $category_object):
        
            if ( $category_name == $category_object->name)
            {
                self::$verbose ? ("Category name - " . $category_name . " Corresponds to Category ID: " . $category_object->id) : false;

                $category_id = $category_object->id;    // capture the category ID from object
                
                break;                                  // we found our match and so we exit the loop
            } 
        endforeach;

        return $category_id;
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @return int:$ticket_id The ID of the ticket that was created based on the form submission.
     *  @param array:$form_data from the Ninja forms based on the  action below:
     * 
     *  add_action( 'ninja_forms_after_submission', array( __CLASS__, 'map_ninja_form_to_ticket' ) );
     *  The function takes the Ninja form data immdediately after submission
     *  The form data is captured into the fields of a new ticket that is to be created as a result of this submission.
     *  The agent-only fields are not updated by the form and will be null.
     *  The Admin needs to set these fields which are mostly for new users.
     *  The data is not modified in anyway except for residential-address where character "/" is replaced by "-"
     */
    public static function map_ninja_form_to_ticket( array $form_data ): ?int
    {
        // 1. Build an associative array mapping admin_label => value directly
        // This avoids array_column index mismatch issues entirely.
        $ninja_data = array();

        if ( ! empty( $form_data['fields'] ) && is_array( $form_data['fields'] ) ) {
            foreach ( $form_data['fields'] as $field ) {
                // Check both array structures depending on NF version
                $admin_label = $field['settings']['admin_label'] ?? $field['admin_label'] ?? '';
                $val         = $field['settings']['value'] ?? $field['value'] ?? '';

                if ( ! empty( $admin_label ) ) {
                    $ninja_data[ trim( $admin_label ) ] = $val;
                }
            }
        }

        $data = array();

        // Initialize variables to avoid undefined variable fatal errors later
        $student_first_name  = '';
        $student_middle_name = '';
        $student_last_name   = '';
        $category_name       = '';

        $current_user = wp_get_current_user();
        $customer     = WPSC_Customer::get_by_email( $current_user->user_email );

        if ( ! $customer ) {
            error_log( 'SupportCandy Customer not found for email: ' . $current_user->user_email );
            return null;
        }

        // Loop through all custom fields of SupportCandy
        foreach ( WPSC_Custom_Field::$custom_fields as $cf ) {

            if ( ! in_array( $cf->field, array( 'ticket', 'agentonly' ), true ) ) {
                continue;
            }

            if ( method_exists( $cf->type, 'get_default_value' ) ) {
                $data[ $cf->slug ] = $cf->type::get_default_value( $cf );
            }

            if ( 'priority' === $cf->slug ) {  
                continue;     
            }

            switch ( true ) {

                case ( 'customer_name' === $cf->name ):
                    if ( isset( $ninja_data['customer_name'] ) ) {
                    $customer_name     = $ninja_data['customer_name'];
                    $customer_name_arr = explode( ' ', $customer_name );
                    $name              = '';
                    foreach ( $customer_name_arr as $partname ) {
                        $name .= ' ' . ucfirst( strtolower( $partname ) );
                    }
                    $data[ $cf->slug ] = trim( $name );
                } else {
                    error_log( $cf->name . ' -> index not found in Ninja forms value map' );
                }
                break;

                case ( 'category' === $cf->slug ):
                    if ( isset( $ninja_data['ticket_category'] ) ) {
                        $category_name     = $ninja_data['ticket_category'];
                        $category_id       = self::get_category_id_given_name( $category_name );
                        // SupportCandy requires integer for category
                        $data[ $cf->slug ] = (int) $category_id;
                    } else {
                        error_log( 'ticket_category -> index not found in Ninja forms value map' );
                    }
                    break;

                case ( 'customer_email' === $cf->name ):
                    $data[ $cf->slug ] = $customer->email;
                    break;

                case ( 'headstart-email' === $cf->name ):
                    if ( isset( $ninja_data['primary-email'] ) ) {
                        $primary_email = $ninja_data['primary-email'];
                        if ( false !== stripos( $primary_email, '@headstart.edu.in' ) ) {
                            $data[ $cf->slug ] = $primary_email;
                        } else {
                            error_log( 'primary email in application form does NOT contain @headstart.edu.in' );
                        }
                    } else {
                        error_log( 'index for: primary-email not found in Ninja forms value map' );
                    }
                    break;

                case ( 'subject' === $cf->slug ):
                case ( 'description' === $cf->slug ):
                        $data[ $cf->slug ] = 'Admission';
                    break;

                case ( 'residential-address' === $cf->name ):
                    if ( isset( $ninja_data['residential-address'] ) ) {
                        $data[ $cf->slug ] = str_ireplace( '/', '-', $ninja_data['residential-address'] );
                    } else {
                        error_log( $cf->name . ' -> index not found in Ninja forms value map' );
                    } 
                    break;

                case ( 'student-first-name' === $cf->name ):
                    if ( isset( $ninja_data['student-first-name'] ) ) {
                        $data[ $cf->slug ]  = ucfirst( strtolower( $ninja_data['student-first-name'] ) );
                        $student_first_name = $data[ $cf->slug ];
                    } else {
                        error_log( $cf->name . ' -> index not found in Ninja forms value map' );
                    }
                    break;

                case ( 'student-middle-name' === $cf->name ):
                    if ( isset( $ninja_data['student-middle-name'] ) ) {
                        $data[ $cf->slug ]   = ucfirst( strtolower( $ninja_data['student-middle-name'] ) );
                        $student_middle_name = $data[ $cf->slug ];
                    } else {
                        error_log( $cf->name . ' -> index not found in Ninja forms value map' );
                    }
                    break;

                case ( 'student-last-name' === $cf->name ):
                    if ( isset( $ninja_data['student-last-name'] ) ) {
                        $data[ $cf->slug ]  = ucfirst( strtolower( $ninja_data['student-last-name'] ) );
                        $student_last_name = $data[ $cf->slug ];
                    } else {
                        error_log( $cf->name . ' -> index not found in Ninja forms value map' );
                    }
                    break;

                default:
                    if ( isset( $ninja_data[ $cf->name ] ) ) {
                        $val = $ninja_data[ $cf->name ];
                    
                    // SupportCandy custom field type safety:
                    // Convert array values or ensure non-null values for SupportCandy fields
                        if ( is_array( $val ) ) {
                            $data[ $cf->slug ] = array_map( 'intval', $val );
                        } else {
                            $data[ $cf->slug ] = ( string ) $val;
                        }
                    } else {
                        error_log( $cf->name . ' -> index not found in Ninja forms value map' );
                    }
                    break;

            }

        }

        $data['customer'] = (int) $customer->id;

        $student_full_name = trim( $student_first_name . ' ' . $student_middle_name . ' ' . $student_last_name );

        $description = 'Application for Admission of ' . $student_full_name . ' to: ' . $category_name;
        unset( $data['description'] );

        $description_attachments = $data['description_attachments'] ?? '';
        unset( $data['description_attachments'] );

        $data['last_reply_on'] = ( new DateTime() )->format( 'Y-m-d H:i:s' );
        $data['source']        = 'MA_HSA_plugin';
        $data['ip_address']    = WPSC_DF_IP_Address::get_current_user_ip();
        $data['browser']       = WPSC_DF_Browser::get_user_browser();
        $data['os']            = WPSC_DF_OS::get_user_platform();
        $data['user_type']     = 'registered';

        // Create the ticket
        $ticket = WPSC_Ticket::insert( $data );

        if ( ! $ticket ) {
            error_log( 'Could not create a new SC ticket from Ninja form submission, Investigate' );
            return null;
        }

        error_log( 'A new SC ticket created from Ninja form for Customer ID:' . $customer->id . ' and Ticket ID:' . $ticket->id );

        $ticket->last_reply_by = $ticket->customer->id;
        $ticket->save();

        WPSC_Thread::insert(
            array(
                'ticket'      => $ticket->id,
                'customer'    => $ticket->customer->id,
                'type'        => 'report',
                'body'        => $description,
                'attachments' => $description_attachments,
                'source'      => 'MA_plugin_code',
            )
        );

        do_action( 'wpsc_create_new_ticket', $ticket );

        return $ticket->id;
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param object:$ticket is the ticket object passed in
     *  @return string:$student_fullname is the full name formed from first, middle and last names of student in ticket
     */
    public static function get_student_full_name_from_ticket( object $ticket ) : ? string
    
    {
        $student_fullname = self::get_ticket_value_given_cf_name( $ticket,  'student-first-name' )     . " " . 
                            self::get_ticket_value_given_cf_name( $ticket,  'student-middle-name' )    . " " . 
                            self::get_ticket_value_given_cf_name( $ticket,  'student-last-name' );
        
        return $student_fullname;
    }


    /**
     *  @param int:$ticket is the ticket concerned
     *  @param int:$prev is the previous status ID of the ticket
     *  @param int:$new is the ID of the current new status of the ticket
     *  do_action( 'wpsc_change_ticket_status', self::$ticket, $prev, $new, $customer_id ) in class_wpsc_individual_ticket
     *  This is the  callback that triggers the various tasks contingent upon ticket status change to desired one
     *  When the status changes to Admission Granted, the payment process is triggered immediately
     *  When the ststus is changed to Admission Confirmed the SriToni new user account creation is triggered
     *  More can be added here as needed.
     */
    public static function my_wpsc_change_ticket_status_action_callback( $ticket, $prev, $new, $customer_id)
    {   // status change triggers the main state machine of our plugin
        // add any logoc that you want here based on new status
        $prev_status_object = new WPSC_Status( $prev ); // rebuild status object using previous status id passed in
        $prev_status_name   = $prev_status_object->name;

        $new_status_object = new WPSC_Status( $new );   // rebuild status pbject using current status id passed in
        $new_status_name   = $new_status_object->name;

        switch (true):

            // If new status is "Interaction Completed" then calculate fees and payment description and update the ticket fields
            case ( $new_status_name === 'Interaction Completed' ):

                $student_fullname = self::get_student_full_name_from_ticket( $ticket );

                // get the object from category using category id from ticket
                $category_object_from_ticket = new WPSC_Category( $ticket->category );

                // get the ticket category name from ID
                $name_of_ticket_category = $category_object_from_ticket->name;

                // the category NAME (not slug as in prev version) is used as key to get the required data
                // make sure this is adjusted correctly in the settings for fees
                $admission_fee_payable      = self::$category_fee_arr[$name_of_ticket_category];

                $product_customized_name    = self::$category_paymentdescription_arr[$name_of_ticket_category] . " " . $student_fullname;

                // update the agent fields for fee and fee description. 1st form the slug of the custom field using name
                $cf_slug = self::get_cf_slug_by_cf_name( 'admission fee payable' );
                $ticket->$cf_slug = $admission_fee_payable;
                $ticket->save();

                $cf_slug = self::get_cf_slug_by_cf_name( 'product customized name' );
                $ticket->$cf_slug = $product_customized_name;
                $ticket->save();
                
            break;

            // If new status is 'School Accounts Being Created' Create a new user account in SriToni remotely using Moodle API
            case ( $new_status_name === 'School Accounts Being Created' ):

                // Create a new user account in SriToni remotely using Moodle API. If existing user, update the profile information
                $moodle_id = self::prepare_and_create_or_update_moodle_account( $ticket );

                // the above function will change the status automatically to success or fail
            break;


            // This assumes that we have a valid sritoni account, and a valid hset-payment intranet account after ldap-sync
            case ( $new_status_name === 'Admission Payment Order Being Created' ):
                
                // self::create_payment_shop_order( $ticket );

            break;


            case ( $new_status_name === 'Admission Granted' ):

                // Emails are sent to user for making payments.
                // Once payment is made the Order is set to Processing
                // Once Admin sets Order as completed, the webhook fires on the payment site and is captured on this site
                // The captured webhook sets the status as Payment completed
                // There is no other explicit code to be executed here
                // 

             break;


            case ( $new_status_name === 'Admission Confirmed' ):

                // No explicit action in code here
                // The admin needs to trigger the next step by changing the status
            break;


            default:
                // all other cases come here and flow down with no action.
            break;

        endswitch;      // END of switch  status change actions
    }                  

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  This routine is typically called by a scheduled task from outside the class using the instance so this is public
     *  No pre-requisites. Statuses have to  exist in ticket system settings
     *  1. Get a list of all tickets having status: 'school-accounts-being-created'
     *  2. For each ticket, poll the hset-payments site and check if ticket user's user account exists
     *  3. The accounts get created on payment site by a wp-cron driven  hourly ldap sync process from sritonildapsync plugin
     *  4. If user account exists, change status of that ticket to enable PO creation: 'admission-payment-order-being-created'
     */
    public function check_if_accounts_created() : void
    {   // check if (new) accounts are sync'd and exist on payment server - if so change status to admission-payment-order-being-created
        // $this->verbose ? error_log("In function check_if_accunts_created caused by scheduled event:"): false;
        // keep status id prepared in advance to change status of selected ticket in loop

        // get all tickets that have payment status as shown. 
        $tickets = self::get_all_active_tickets_by_status_name( "School Accounts Being Created" );

        self::$verbose ? error_log("Number of tickets being processed with status 'School Accounts Being Created':" . count($tickets)): false;

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            // since we are checking for creation of new SriToniaccounts we cannot use ticket email.
            // we have to use admin given username (agent field) with our domain. So username has to be set for this.
            // since this comes after sritoni account creation we know this would have been set.
            $headstart_email = $ticket->{ self::get_cf_slug_by_cf_name( 'headstart-email' ) };

            if ( !empty($headstart_email) && stripos( $headstart_email, "@headstart.edu.in" ) !== false )
            {
                // This is a continuing head start user so use existing headstart email
                $email = $headstart_email;
            }
            else
            {
                // this is a new headstart user so compose the email
                $email = $ticket->{ self::get_cf_slug_by_cf_name( 'username' ) } . '@headstart.edu.in';
            }
            

            // check if wpuser with this email exists in site hset-payments
            $wp_user_hset_payments = self::get_wp_user_hset_payments($email, $ticket_id);

            if ($wp_user_hset_payments)
            {
                $new_status_name_desired = "Admission Payment Order Being Created";

                // we have a valid customer so go ahead and change status of this ticket to enable PO creation
                self::change_ticket_status( $ticket_id, $new_status_name_desired );

                // log the user id and displayname
                self::$verbose ? error_log("User Account with id:" . $wp_user_hset_payments->id 
                                                    . " And name:" . $wp_user_hset_payments->data->display_name): false;

            }

        endforeach;
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$email
     *  @return object:$customers[0] which is a WP user object. 
     * Pre-requisites: None
     * 1. Get wpuser object from site hset-payments with given email using Woocommerce API
     * 2. If site is unreacheable change status of ticket to error
     */
    public static function get_wp_user_hset_payments(string $email, int $ticket_id = 0 )
    {   // Get wpuser object from site hset-payments with given email using Woocommerce API

        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    self::$config['wckey'],
                                    self::$config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);


        $endpoint   = "customers";

        $params     = array( 'role'  =>'subscriber', 'email' => $email );
        // get customers with given parameters as above, there should be a maximum of 1
        try
        {
            $customers = $woocommerce->get($endpoint, $params);
        }
        catch (HttpClientException $e)
        {   // if cannot access hset-payments server error message and return 1
            $error_message = "Intranet Site: hset-payments not reacheable: " . $e->getMessage();
            if ( ! empty( $ticket_id ) )
            {
                self::change_status_error_creating_payment_shop_order( $ticket_id, $error_message );
            }
            return null;
            
        }
            
         // if you get here then you got something back from hset-payments site
         return $customers[0];
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  This works for any ticket field wether default predefined type or custom type with slug like cust_35 etc.
     */
    public static function get_ticket_value_given_cf_name( object $ticket, string $cf_name )
    {
        // get the CF object using the CF name passed in
        $cf = self::get_cf_object_by_cf_name( $cf_name );

        // extract the slug from the status object. This is the property to use in ticket object to extract field value
        $cf_slug = $cf->slug;

        $cf_value = $ticket->$cf_slug ?? null;

        // The ticket field value depends on the cf type. o we examine the cf->type to see how to handle the value

        switch (true)
        {
            // if ticket custom field type is a selction then this is an object and pick the name property to return
            case ( stripos( $cf->type,  'cf_single_select' ) !== false):
                return  $cf_value->name;
            break;

            // if ticket custom field type is a date then object is datetime and pick date property to return
            case ( stripos( $cf->type,  'cf_date' ) !== false):
                return  $cf_value->format('Y-m-d');
            break;

            // for all other types return the normal string since this is not an object but just a variable
            default:
                return $cf_value;
        }   
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$cf_name is the name of the ticket custom field that is not of the default pre-defined type
     *  This is to be used for custom ticket fields that end up having slug like cust_45 where 45 is the cf id.
     */
    public static function get_cf_slug_by_cf_name( string $cf_name ) : ? string
    {
        // get the object using the name
        $cf = self::get_cf_object_by_cf_name( $cf_name );

        // extract the slug from the ststus object
        $cf_slug = $cf->slug;

        return $cf_slug;
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$cf_name is the name of the ticket custom field that is not of the default pre-defined type
     *  This is to be used for custom ticket fields that end up having slug like cust_45 where 45 is the cf id.
     */
    public static function get_cf_object_by_cf_name( string $cf_name ) : ? object
    {
        foreach ( WPSC_Custom_Field::$custom_fields as $cf ):

            // get only ticket fields, and agent only fields
            if ( ! in_array( $cf->field, array( 'ticket', 'agentonly' ) )  ) {
                continue;
            }
            if ( $cf->name === $cf_name )   // Does the field name match passed in field name?
            {
                // we have a match, so return this object
                return $cf;
            }

        endforeach;

        // could not find a match so return null
        return null;
    }



    /**
     * VISUALLY CHECKED for SC 3.0 compatibility
     * 
     * @param object:$ticket
     * @return bool or int
     * 1. If user already has a Head Start account (detected by their email domain) update user
     * 2. Check that required data is not empty. If so, change ticket status to error
     * 3. Processd for SriToni account creation
     */
    public static function prepare_and_create_or_update_moodle_account( object $ticket )
    {   // update existing or create a new SriToni user account using data from ticket. Add user to cohort based on ticket data

        // get the headstart email from the ticket using the cf name 'headstart-email'
        $headstart_email = self::get_ticket_value_given_cf_name( $ticket, 'headstart-email' );
 
        if (stripos( $headstart_email, 'headstart.edu.in') !== false)
        {   // User already has an exising SriToni email ID and Head Start Account, just update with form info

            $successful = self::update_sritoni_account( $ticket );

            if ( !  $successful )
            {
                // there was an exception. The error flag already must be displaying the debuginfo with status change
                // so exit
                return 'exception updating user';
            }
            
            // if we get here it means user update was successful
            // add user to relevant incoming cohort for easy cohort management
            $successful = self::add_user_to_cohort( $ticket );

            if ( $successful )
            {
                // success with both user update and cohort addition schange ticket status
                $new_status_name = "User Accounts Created";

                self::change_ticket_status( $ticket->id, $new_status_name );

                return 'no exceptions';
            }
            else
            {
                // if unssuccessful, error and status change will have already occured
                return 'exception adding user to cohort after successful sritoni update';
            }
        }

        // if you are here you don't have an existing Head Start email. So Create a new SriToni account using username
        // username must be set by an agent from the support UI.
        // check if all required data for new account creation is set
        
        $username       = self::get_ticket_value_given_cf_name( $ticket, 'username' );
        $idnumber       = self::get_ticket_value_given_cf_name( $ticket, 'idnumber' );
        $studentcat     = self::get_ticket_value_given_cf_name( $ticket, 'studentcat' );
        $department     = self::get_ticket_value_given_cf_name( $ticket, 'department' );
        $institution    = self::get_ticket_value_given_cf_name( $ticket, 'institution' );
        $class          = self::get_ticket_value_given_cf_name( $ticket, 'class' );
        $moodle_phone1  = self::get_ticket_value_given_cf_name( $ticket, 'emergency-contact-number' );
        $moodle_phone2  = self::get_ticket_value_given_cf_name( $ticket, 'emergency-alternate-contact' );

        if 
        (   ! empty( $username )      &&
            ! empty( $idnumber )      &&
            ! empty( $studentcat )    &&
            ! empty( $department )    &&
            ! empty( $institution )   &&
            ! empty( $class )         &&
            ! empty( $moodle_phone1 ) &&
            ! empty( $moodle_phone2 )
        )        
        {
            // go create a new SriToni user account for this child using ticket dataa. Return the moodle id if successfull
            $moodle_id = self::create_sritoni_account( $ticket );

            if ($moodle_id)
            {
                // IF new user account is valid, add the user to appropriate cohort for easy management later on
                $successful = self::add_user_to_cohort( $ticket );

                if ( $successful )
                {
                    // success with both user update and cohort addition schange ticket status
                    $new_status_name = "User Accounts Created";

                    self::change_ticket_status( $ticket->id, $new_status_name );
                }

                return $moodle_id;
            }
            else
            {
                return 'exception creating a new sritoni user account';
            }  
        }
        else
        {
            $error_message = "Error in user creation! Ensure username, idnumber, studentcat, department, institution, class, phone1, 2 are Set";
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message);

            return $error_message;
        }
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     *  @param object:$ticket
     *  @return int:moodle user id from table mdl_user. null if error in creation
     *  1. The data is extracted from the ticket and checked to ensure it is set and not empty
     *  2. Function checks if username is not taken, only then creates new account.
     *  3. If error in creating new accoount or username is taken, ticket status changed to error.
     */
    private static function create_sritoni_account( object $ticket ) : ? int
    {   // checks if username is not taken, only then creates new account -sets ticket error status if account not created

        // read in the Moodle API config array
        $config			= self::$config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.

        // username is to be set by agent for the agentonly field. This is a new user so required to be set correctly
        $moodle_username    = self::get_ticket_value_given_cf_name( $ticket, 'username' );

        $moodle_email = $moodle_username . '@headstart.edu.in';

        // Check to see if a user with this username already exists. A pre-existing account is an error that is handled here
        $existing_ldap_user = self::get_user_account_from_sritoni( $ticket, $moodle_email,  false );

        if ( ! empty($existing_ldap_user) && $existing_ldap_user['mail'] ==  $moodle_email )
        {
            // An account with this username already exssts. So set error status so Admin can set a different username and try again
            $error_message = "This username already exists with name:" .  $existing_ldap_user['displayname'] . " and IDNUMBER:" . $existing_ldap_user['employeenumber'];

            error_log( $error_message );

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message );

            return null; 
        }

        // if you are here we have a username that does not exist yet so create a new moodle user account with this username

        $moodle_environment = self::get_ticket_value_given_cf_name( $ticket, 'environment' );
        if (empty($moodle_environment)) $moodle_environment = "NA";

        // write the data back to Moodle using REST API
        // create the users array in format needed for Moodle RSET API
        $fees_array = [];
        $fees_json  = json_encode($fees_array);

        $payments   = [];
        $payments_json = json_encode($payments);

        $virtualaccounts = [];
        $virtualaccounts_json = json_encode($virtualaccounts);

    	$users = array("users" => array(
                                        array(	"username" 	    => $moodle_username,
                                                "idnumber"      => self::get_ticket_value_given_cf_name( $ticket, "idnumber"),
                                                "auth"          => "oauth2",
                                                "firstname"     => self::get_ticket_value_given_cf_name( $ticket, "student-first-name"),
                                                "lastname"      => self::get_ticket_value_given_cf_name( $ticket, "student-last-name"),
                                                "email"         => $moodle_email,
                                                "middlename"    => self::get_ticket_value_given_cf_name( $ticket, "student-middle-name"),
                                                "institution"   => self::get_ticket_value_given_cf_name( $ticket, "institution"),
                                                "department"    => self::get_ticket_value_given_cf_name( $ticket, 'department' ),
                                                "phone1"        => self::get_ticket_value_given_cf_name( $ticket, 'emergency-contact-number' ),
                                                "phone2"        => self::get_ticket_value_given_cf_name( $ticket, 'emergency-alternate-contact' ),
                                                "address"       => self::get_ticket_value_given_cf_name( $ticket, "residential-address"),
                                                "maildisplay"   => 0,
                                                "createpassword"=> 0,
                                                "city"          => self::get_ticket_value_given_cf_name( $ticket, "city"),

                                                "customfields" 	=> array(
                                                                        array(	"type"	=>	"class",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "class"),
                                                                            ),
                                                                        array(	"type"	=>	"environment",
                                                                                "value"	=>	$moodle_environment,
                                                                            ),
                                                                        array(	"type"	=>	"emergencymob",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, 'emergency-contact-number' ),
                                                                            ),
                                                                        array(	"type"	=>	"fees",
                                                                                "value"	=>	$fees_json,
                                                                            ),
                                                                        array(	"type"	=>	"payments",
                                                                                "value"	=>	$payments_json,
                                                                            ),
                                                                        array(	"type"	=>	"virtualaccounts",
                                                                                "value"	=>	$virtualaccounts_json,
                                                                            ),
                                                                        array(	"type"	=>	"studentcat",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "studentcat"),
                                                                            ),
                                                                        array(	"type"	=>	"bloodgroup",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "blood-group") ?? 'Z',
                                                                            ),
                                                                        array(	"type"	=>	"motheremail",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-email"),
                                                                            ),
                                                                        array(	"type"	=>	"fatheremail",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-email"),
                                                                            ),
                                                                        array(	"type"	=>	"motherfirstname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-first-name"),
                                                                            ),
                                                                        array(	"type"	=>	"motherlastname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-last-name"),
                                                                            ),
                                                                        array(	"type"	=>	"fatherfirstname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-first-name"),
                                                                            ),
                                                                        array(	"type"	=>	"fatherlastname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-last-name"),
                                                                            ),
                                                                        array(	"type"	=>	"mothermobile",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-contact-number"),
                                                                            ),
                                                                        array(	"type"	=>	"fathermobile",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-contact-number"),
                                                                            ),
                                                                        array(	"type"	=>	"allergiesillnesses",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "allergies-illnesses") ?? '',
                                                                            ),
                                                                        array(	"type"	=>	"birthplace",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "birthplace") ?? '',
                                                                            ),
                                                                        array(	"type"	=>	"nationality",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "nationality") ?? '',
                                                                            ),
                                                                        array(	"type"	=>	"languages",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "languages-spoken") ?? '',
                                                                            ),
                                                                        array(	"type"	=>	"dob",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "date-of-birth"),
                                                                            ),
                                                                        array(	"type"	=>	"pin",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "pin-code"),
                                                                            ),           
                                                                        )
                                            )
                                        )
        );

        // print out users array for debugging if verbose flag is set
        self::$verbose ? error_log(print_r($users, true)) : false;

        // now to uuser  with form and agent fields
        $ret = $MoodleRest->request('core_user_create_users', $users, MoodleRest::METHOD_POST);

        // let us check to make sure that the user is created
        if ( empty($ret["exception"]) && $ret[0]['username'] == $moodle_username )
        {
            // the returned user has same name as one given to create new user so new user creation was successful
            // $ticket_error_msg = self::get_ticket_value_given_cf_name( $ticket, "error" );

            $msg = "SriToni id: " . $ret[0]['id'];

            // update error message of ticket with successful account creation moodle id
            self::change_ticket_field( $ticket->id, 'error', $msg );

            return $ret[0]['id'];
        }
        else
        {
            error_log("Create new user did NOT return expected username: " . $moodle_username);
            error_log(print_r($ret, true));

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $ret["debuginfo"] );

            return null;
        }

    }

    /**
     *  Given an email ID as a string, the method attempts to get the associated LDAP user account if it exists.
     *  If the account exists then the LDAP user account is returned as an object
     *  If account does not exist, a null is returned
     */
    private static function get_user_account_from_ldap( string $email ): ? array
    {
        /*
        Array ( [uid] => moodle username is used as uid 
                [cn] => firstname lastname 
                [displayname] => first middle last
                [givenname] =>  firstname
                [sn] => lastname 
                [mail] => email ID on domain name
                [userpassword] => LDAP password 
                [o] => organization 
                [employeenumber] => SriToni IDNUMBER
                [ou] => StudentActive 
                [telephonenumber] => 123456789 
                [mobile] => 123456789 
                [departmentnumber] => none 
                [homepostaladdress] => 
                [objectclass] => inetOrgPerson 
                [telexnumber] => moodle user id (integer)
                [businesscategory] => student category such as General 
                [audio] => [] 
                [carlicense] => [] 
                [description] => {} payment details json
            )
        */
        // update the configuration in case changes have been made
        $config			= self::$config;

        $ldapserver = $config['ldaps_server'];
        $ldapuser   = $config['ldap_admin'];
        $ldappass   = $config['ldap_password'];
        $ldaptree   = $config['ldap_tree'];

        $ldapfilter = '(&(objectClass=inetOrgPerson)(mail=' . $email . '))';

         // connect to LDAP server
        $ldapconn = ldap_connect($ldapserver) or die("Could not connect to LDAP server.");

        // if we are here then we did not die so we must have connected to LDAP server
        // but first set protocol version
            ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);

            // bind using admin account
        $ldapbind = ldap_bind($ldapconn, $ldapuser, $ldappass);

        // verify binding and if good search and download entries based on filter set below
        if ($ldapbind)
        {
            // echo nl2br("LDAP Connection and Authenticated bind successful...\n");

            $result = ldap_search($ldapconn, $ldaptree, $ldapfilter); // or 
            if ( $result === false) 
            { 
                error_log("Error in search query: " . ldap_error($ldapconn) );
                return null;
            }

            $data   = ldap_get_entries($ldapconn, $result);

            // print number of entries found
            $ldapcount = ldap_count_entries($ldapconn, $result);

            // echo nl2br("Number of entries found in LDAP directory: " . $ldapcount . "\n");

            // convert entries to associative type using cleanup function as given on php manual
    	    $ldap_user_accounts = self::cleanUpEntry($data);
            
            if ( $ldapcount === 0 )
            {
                error_log("Could not get any LDAP user accounts with given email: $email");
                return null;
            }

            foreach ($ldap_user_accounts as $key => $ldap_user_account) {
                // we know there should be at least one entry, the most probable correct entry
                
                if ( $ldap_user_account['mail'] == $email )
                {
                    // our desired user LDAP user account. See at top for array contents
                    return $ldap_user_account;
                }
                else
                {
                    error_log("Could not get any LDAP user accounts with given email: $email");
                    return null;
                }
            }
        }
        else
        {
            error_log ( "LDAP bind failed " . ldap_error($ldapconn) );
        }

        return null;
    }


    private static function cleanUpEntry($entry)
    {
      $retEntry = array();
      for ( $i = 0; $i < $entry['count']; $i++ ) {
        if (is_array($entry[$i])) {
          $subtree = $entry[$i];
          //This condition should be superfluous so just take the recursive call
          //adapted to your situation in order to increase perf.
          if ( ! empty($subtree['dn']) and ! isset($retEntry[$subtree['dn']])) {
            $retEntry[$subtree['dn']] = self::cleanUpEntry($subtree);
          }
          else {
            $retEntry[] = self::cleanUpEntry($subtree);
          }
        }
        else {
          $attribute = $entry[$i];
          if ( $entry[$attribute]['count'] == 1 ) {
            $retEntry[$attribute] = $entry[$attribute][0];
          } else {
            for ( $j = 0; $j < $entry[$attribute]['count']; $j++ ) {
              $retEntry[$attribute][] = $entry[$attribute][$j];
            }
          }
        }
      }
      return $retEntry;
    }


    /**
     * @param object:$ticket is the ticket object passed in
     * @param string:$moodle_email is the email of the user we are interested in getting from SriToni server
     * @return object:$hset_payments_user: The WooCommerce user object is returned
     * If user account in LDAP server exists then it is retrieved as an array
     *  
     * If account does not exist, a null is returned
     * If account exists and flag is true then ticket error status is set that account already exists
     * If account does NOT exist flag is don't care and no ticket status is set
     */
    private static function get_user_account_from_sritoni( object $ticket = null, string  $moodle_email, bool $err_flag = false ) : ? array
    {
        // We need the Moodle id of the user. This is not possible to get directly from the Moodle Server
        // So we will use the email to get the user details from the LDAP server
        // once we get the moodle id we can then get the moodle user account from the Moodle Server if we need to
        $ldap_user_arr = self::get_user_account_from_ldap( $moodle_email );

        // verify that a user account was returned
        if ( $ldap_user_arr )
        {
            // we do have a user account array here
            $moodle_id = (int) $ldap_user_arr['telexnumber'];

            // If not a valid moodle ID then flag an error
            if ( ! is_numeric($moodle_id) )
            {
                error_log("A SriToni moodle ID could npt be found in account with email: " . $moodle_email);
                error_log(print_r($ldap_user_arr, true));

                return null;
            }
            else
            {
                // A valid LDAP user with desired email exists in the LDAP server
                error_log("A LDAP user account Does exist with email: " . $moodle_email);

                if ( $err_flag && $ticket ) 
                {
                    // Account exists and flag is true so set error status for ticket
                    self::change_status_error_creating_sritoni_account( $ticket->id, 'A SriToni account with this username already exists' );
                }
                return $ldap_user_arr;
                }
        }
        else
        {
            // nothing returned from LDAP query. Either couldn't connect or no account with this email exists
            return null;
        }
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param object:$ticket
     *  @return array:$ret returned array from Moodle update user API call
     *  1. Extracts the username from the headstart email field in ticket
     *  2. If the email dooes not meet conditions returns with an error and changes the ticket status
     *  3. An API call is made to the hset-payment server to get the user account array based on email and WC API
     *  4. If the call is successful default data for idnumber, department, etc., is extracted
     *  5. Ticket data is used to update the user account. For some fields if ticket data is empty, defaults from above are used
     *  6. The updated user array returned from Moodle server is returned on successful update
     *  The calling routine should check for $ret['exception'] to verify is call was OK or not
     */
    private static function update_sritoni_account( object $ticket ) : bool
    {
        // Check the email propriety
        // Presume Existing user, so the username needs to be extracted from the headstart-email ticket field
        $moodle_email       = self::get_ticket_value_given_cf_name( $ticket, "headstart-email" );
        
        // validate the email
        if ( ( $moodle_email  = filter_var( $moodle_email, FILTER_VALIDATE_EMAIL) ) !== false ) 
        {   
            // extract everything before @headstart.edu.in. Following is case-sensitive
            $moodle_username = strstr($moodle_email, '@', true);
        }
        else
        {
            // Something wrong with extraction of email from user supplied headstart email
            error_log("Headstart email given is not proper: " . $moodle_email);

            $error_message = 'Check user supplied headstart email - could not validate email';

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message );

            return false;
        }

        // If we are here, the email is validated to be proper. Chekck that extracted username is not empty
        if ( empty( $moodle_username ) )
        {
            // Something wrong with extraction of email from user supplied headstart email
            error_log("Something wrong with extraction of username from email: " . $moodle_email);

            $error_message = 'Problem extracting username from email - check user supplied headstart email';

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message );

            return false;
        }

        // If we got here , a valid email and username were extracted successfully from the ticket fields
        // Get the user from LDAP server with dsame email as passed parameter
        $ldap_user = self::get_user_account_from_ldap( $moodle_email );

        if ( $ldap_user['mail'] !=  $moodle_email )
        {
            // The user account does not exist! but is expected since this is supposed to be an existing user
            $error_message = "check for an existing user failed -  LDAP user - extracted mail not matching: "
            . $moodle_email . "-->" . $ldap_user['mail'];

            error_log($error_message);

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message );

            return false;
        }
        // we have an existing user confirmed
        // extract needed user data
        $sritoni_idnumber_from_ldap     = $ldap_user['employeenumber'];
        $grade_or_class_from_ldap       = $ldap_user['departmentnumber'];             
        $sritoni_student_category_ldap  = $ldap_user['businesscategory'];
        $ou_ldap                        = $ldap_user['ou'];

        // We can extract the department from the ou field above. It will contain StudentActive for a student
        if ( stripos( $ou_ldap, 'Student' ) !== false )
        {
            // 'Student' is contained in the ou extracted field
            $department_ldap_extracted = 'Student';
        }

        // before coming here, check that ticket fields such as idnumber, department, etc., are not empty
        // if ticket values are not set, the user's previous values are reused in the update for these fields
        $moodle_idnumber    = self::get_ticket_value_given_cf_name( $ticket, "idnumber"); // ?? $sritoni_idnumber_wc

        if ( empty( $moodle_idnumber ) )
        {
            //  sritoni idnumber is empty
            $error_message = 'SriToni IDNUMBER is empty';

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message );

            return false;
        }

        $moodle_department  = self::get_ticket_value_given_cf_name( $ticket, 'department' ) ?? 'Student';

        // get the Moodle user ID (int) from the LDAP extracted array of user account matching our email
        $moodle_id = $ldap_user['telexnumber'];

        // read in the Moodle API config array and setup the API object
        {
            $config			= self::$config;
            $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
            $moodle_token	= $config["moodle_token"];

            // prepare the Moodle Rest API object
            $MoodleRest = new MoodleRest();
            $MoodleRest->setServerAddress($moodle_url);
            $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
            $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.
        }
        
        // We have a valid Moodle user id, form the array to updatethis user. The ticket custom field name must be exactly as shown.
        $users = array("users" => array(
                                        array(	"id" 	        => $moodle_id,          // extracted from LDAP retrieved array
                                                "idnumber"      => $moodle_idnumber,    // got from ticket since this can change in update
                                        //      "auth"          => "oauth2",            // MUST NOT CHANGE
                                                "firstname"     => self::get_ticket_value_given_cf_name( $ticket, "student-first-name" ),
                                                "lastname"      => self::get_ticket_value_given_cf_name( $ticket, "student-last-name" ),
                                        //      "email"         => $moodle_email,       // no change
                                                "middlename"    => self::get_ticket_value_given_cf_name( $ticket, "student-middle-name" ),
                                                "institution"   => self::get_ticket_value_given_cf_name( $ticket, "institution"),
                                        //      "department"    => $moodle_department,  // Usually no change
                                                "phone1"        => self::get_ticket_value_given_cf_name( $ticket, "emergency-contact-number" ),
                                                "phone2"        => self::get_ticket_value_given_cf_name( $ticket, "emergency-alternate-contact" ),
                                                "address"       => self::get_ticket_value_given_cf_name( $ticket, "residential-address" ),
                                         //     "maildisplay"   => 0,                   // MUST NOT CHANGE
                                         //     "createpassword"=> 0,                   // MUST NOT CHANGE
                                                "city"          => self::get_ticket_value_given_cf_name( $ticket, "city" ),

                                                "customfields" 	=> array(                                                                            
                                                                        array(	"type"	=>	"bloodgroup",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "blood-group" ),
                                                                            ),
                                                                        array(	"type"	=>	"motheremail",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-email" ),
                                                                            ),
                                                                        array(	"type"	=>	"fatheremail",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-email" ),
                                                                            ),
                                                                        array(	"type"	=>	"motherfirstname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-first-name" ),
                                                                            ),
                                                                        array(	"type"	=>	"motherlastname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-last-name" ),
                                                                            ),
                                                                        array(	"type"	=>	"fatherfirstname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-first-name" ),
                                                                            ),
                                                                        array(	"type"	=>	"fatherlastname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-last-name" ),
                                                                            ),
                                                                        array(	"type"	=>	"mothermobile",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-contact-number" ),
                                                                            ),
                                                                        array(	"type"	=>	"fathermobile",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-contact-number" ), 
                                                                            ),
                                                                        array(	"type"	=>	"allergiesillnesses",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "allergies-illnesses" ),
                                                                            ),
                                                                        array(	"type"	=>	"birthplace",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "birthplace" ), 
                                                                            ),
                                                                        array(	"type"	=>	"nationality",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "nationality" ),
                                                                            ),
                                                                        array(	"type"	=>	"languages",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "languages-spoken" ),
                                                                            ),
                                                                        array(	"type"	=>	"dob",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "date-of-birth" ),
                                                                            ),
                                                                        array(	"type"	=>	"pin",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "pin-code" ),
                                                                            ),           
                                                                        )
                                            )
                                    )
                            );
        
        // Make the API call  to SriToni server to update users
        $ret = $MoodleRest->request('core_user_update_users', $users, MoodleRest::METHOD_POST);

        if ($ret["exception"])
        {
            // There was a problem with the user update, log the details
            error_log("SriToni User Update returned variable dump");
            error_log(print_r($ret, true));

            $msg = "Problem with  SriToni user update - debuginfo - " . $ret["debuginfo"];

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $msg );

            return false;
        }
        else
        {
            // Update went well we can change the status of the ticket below if we wish
            error_log("SriToni existing user Update successful for user: " . $moodle_email);

            // update agent field error message with the passed in error message
            self::change_ticket_field( $ticket->id, 'error', 'User SriToni Account updated successfully' );

            return true;
        }
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param object:$ticket
     *  @param array:$cohort_ret is the array returned after adding the member to the cohort from SriToni server
     *  You must have the data pbject ready before coming here
     *  The user could be a new user or a continuing user
     */
    private static function add_user_to_cohort( object $ticket ) : bool
    {   // adds user to cohort based on settings cohortid array indexed by ticket category

        // read in the Moodle API config array
        $config			= self::$config;

        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        $ticket_headstart_email       = self::get_ticket_value_given_cf_name( $ticket, "headstart-email" );        


        // check if ticket field for headstart email contains the domain name
        if (stripos( $ticket_headstart_email, '@headstart.edu.in') !==false)
        {
            // Continuing user, the username needs to be extracted from the headstart-email
            $moodle_email       = $ticket_headstart_email;

            // get 1st part of the email as the username
            $moodle_username    = explode( "@headstart.edu.in", $moodle_email, 2 )[0];
        }
        else
        {
            // New account just created so get info from ticket fields
            $moodle_username    = self::get_ticket_value_given_cf_name( $ticket, "username" );
            $moodle_email       = $moodle_username . "@headstart.edu.in";
        }
        

        $category_id = $ticket->category->id;   // since this is a default ticket field the slug is pre-defined

        // from category ID get the category name
        $category_object = new WPSC_Category( $category_id );

        $category_name = $category_object->name;

        // get the cohortid for this ticket based on category-cohortid mapping from settings
        $cohortidnumber = self::$category_cohortid_arr[$category_name];

        // for debugging purposes log the cohortname and id number
        error_log("Cohort Name identified for new user per ticket category: " . $category_name);
        error_log("Cohort idnumber identified for new user per ticket category: " . $cohortidnumber);

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.

        // the cohort id is the id in the cohort table. Nothing else seems to work. This is what needs to be in the settings
        $parameters   = array("members"  => array(array("cohorttype"    => array(  'type' => 'id',
                                                                                   'value'=> $cohortidnumber
                                                                                ),
                                                        "usertype"      => array(   'type' => 'username',
                                                                                    'value'=> $moodle_username
                                                                                )
                                                        )
                                                )
                            );  

        $cohort_ret = $MoodleRest->request('core_cohort_add_cohort_members', $parameters, MoodleRest::METHOD_GET);

        error_log("The return array from attempt to add username: " . $moodle_username . " to Cohort idnumber: " . $cohortidnumber );
        error_log(print_r($cohort_ret, true));

        if ( empty($cohort_ret["exception"]) )
        {
            // there were no exceptions so cohort addition was successful
            // get existing ticket error msg
            // $ticket_error_msg = self::get_ticket_value_given_cf_name( $ticket, 'error' );

            $ticket_error_msg = " Added to Cohort--" . $cohortidnumber;

            self::change_ticket_field( $ticket->id, 'error', $ticket_error_msg );

            return true;
        }
        else
        {
            // there was an exception
            $ticket_error_msg = 'Error in adding to cohort-' . $cohort_ret["debuginfo"];

            self::change_ticket_field( $ticket->id, 'error', $ticket_error_msg );

            return false;
        }
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    public static function add_my_menu()
    {
        add_submenu_page(
            'users.php',	                    // string $parent_slug
            'Admissions',	                    // string $page_title
            'Admissions',                       // string $menu_title
            'manage_options',                   // string $capability
            'admissions',                       // string $menu_slug
            [__CLASS__, 'render_admissions_page'] );// callable $function = ''

        // add submenu page for testing various application API needed for SriToni operation
        add_submenu_page(
            'users.php',	                     // parent slug
            'SriToni Tools',                     // page title
            'SriToni Tools',	                 // menu title
            'manage_options',	                 // capability
            'sritoni-tools',	                 // menu slug
            [__CLASS__, 'sritoni_tools_render']);    // callback
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    public static function render_admissions_page()
    {
        echo "This is the admissions page where stuff about admissions can be displayed";
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    public static function sritoni_tools_render()
    {
        // this is for rendering the API test onto the sritoni_tools page
        ?>
            <h1> Input appropriate ID (moodle/customer/Ticket/Order) and or username and Click on desired button to test</h1>
            <form action="" method="post" id="mytoolsform">
                <input type="text"   id ="id" name="id"/>
                <input type="text"   id ="username" name="username"/>
                <input type="submit" name="button" 	value="get_sritoni_user_using_username"/>
                <input type="submit" name="button" 	value="test_cashfree_connection"/>
                <input type="submit" name="button" 	value="test_woocommerce_customer"/>
                <input type="submit" name="button" 	value="test_custom_code"/>
                <input type="submit" name="button" 	value="test_get_data_object_from_ticket"/>

                
                <input type="submit" name="button" 	value="test_get_ticket_data"/>
                <input type="submit" name="button" 	value="test_get_wc_order"/>
                
                
                <input type="submit" name="button" 	value="trigger_payment_order_for_error_tickets"/>
                <input type="submit" name="button" 	value="trigger_sritoni_account_creation_for_error_tickets"/>

            </form>


        <?php

        $id = sanitize_text_field( $_POST['id'] );
        $username = sanitize_text_field( $_POST['username'] );

        switch ($_POST['button'])
        {
            case 'get_sritoni_user_using_username':
                self::test_ldap_and_moodle_connection($username);
            break;

            case 'test_cashfree_connection':
                // $this->test_cashfree_connection($id);
            break;

            case 'test_woocommerce_customer':
                self::test_woocommerce_customer($username);
            break;

            case 'test_get_ticket_data':
                self::test_get_ticket_data($id);
            break;

            case 'test_get_wc_order':
                self::get_wc_order($id);
            break;

            case 'trigger_payment_order_for_error_tickets':
                self::trigger_payment_order_for_error_tickets();
            break;

            case 'trigger_sritoni_account_creation_for_error_tickets':
                self::trigger_sritoni_account_creation_for_error_tickets();
            break;

            case 'test_get_data_object_from_ticket':
                // $this->test_get_data_object_from_ticket($id);
            break;

            case 'test_custom_code':
                $email = $username . '@headstart.edu.in';
                $ldap_user = self::get_user_account_from_ldap($email);
                print_r($ldap_user);
            break;

            default:
                // do nothing
                break;
        }
        
    }

    /**
     * 
     */
    public static function test_get_ticket_data($ticket_id)
    {
        $ticket = new WPSC_Ticket( $ticket_id );

        echo '<pre>';
        print_r($ticket);
        echo  '</pre>';

        $status_id = $ticket->status->id;

        $status_object = new WPSC_Status( $status_id );

        echo '<pre>';
        print( "The status object of above ticket is displayed below using its ID obtained from ticket details" );
        print_r($status_object);
        echo  '</pre>';

        $category_id = $ticket->category->id;

        $category_object = new WPSC_Category( $category_id );

        echo '<pre>';
        print( "The Category object of above ticket is displayed below using its ID obtained from ticket details" );
        print_r($category_object);
        echo  '</pre>';
    }

        
    

    /**
     * @param integer:$ticket_id
     * @param array:$threads
     */
    public function get_ticket_threads( int $ticket_id): ?array
    {   // returns all threads of a ticket from the given ticket_id
        $threads = get_posts(array(
            'post_type'      => 'wpsc_ticket_thread',
            'post_status'    => 'publish',
            'posts_per_page' => '1',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                                        'relation' => 'AND',
                                        array(
                                            'key'     => 'ticket_id',
                                            'value'   => $ticket_id,
                                            'compare' => '='
                                            ),
                                        array(
                                            'key'     => 'thread_type',
                                            'value'   => 'reply',
                                            'compare' => '='
                                            ),
                                    ),
            )
        );

        return $threads;
    }

    /**
     * @return void
     * Get a list of all tickets that have error's while creating payment orders
     * The errors could be due to server down issues or data setup issues
     * For each ticket get the user details and create a payment order in the hset-payments site
     * After successful completion, change the status of the ticket appropriately
     */

    public function trigger_sritoni_account_creation_for_error_tickets()
    {   // for all tickets with error status for sritoni, initiate an update-create sritoni account again
        global $wpscfunction, $wpdb;

        // get all tickets that have payment status as shown. 
        $tickets = $this->get_all_active_tickets_by_status_slug('error-creating-sritoni-account');

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            $this->prepare_and_create_or_update_moodle_account($ticket_id);
            
        endforeach;
    }


    /**
     * @return nul
     * Get a list of all tickets that have error's while creating payment orders
     * The errors could be due to server down issues or data setup issues
     * For each ticket get the user details and create a payment order in the hset-payments site
     * After successful completion, change the status of the ticket appropriately
     */

    public function trigger_payment_order_for_error_tickets()
    {
        global $wpscfunction, $wpdb;

        // get all tickets that have payment status as shown. 
        $tickets = $this->get_all_active_tickets_by_status_slug('error-creating-payment-shop-order');

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            $this->create_payment_shop_order($ticket_id);
            
        endforeach;
    }

    


    /**
     * 
     */

    public static function test_woocommerce_customer( string $email ) 
    {
        $wpuserobj = self::get_wp_user_hset_payments( $email );

        $sritoni_idnumber = array_column($wpuserobj->meta_data, 'value')[array_search('sritoni_idnumber', array_column($wpuserobj->meta_data, 'key') )];

        $grade_or_class = array_column($wpuserobj->meta_data, 'value')[array_search('grade_or_class', array_column($wpuserobj->meta_data, 'key') )];

        $sritoni_student_category = array_column($wpuserobj->meta_data, 'value')[array_search('sritoni_student_category', array_column($wpuserobj->meta_data, 'key') )];
        

        
        echo "<pre>" . print('Get meta data value for key grade_or_class: ' . $grade_or_class) ."</pre>";
               
        echo "<pre>" . print('Get meta data value for key sritoni_student_category: ' . $sritoni_student_category) ."</pre>";

        echo "<pre>" . print('Get meta data value for key sritoni_idnumber: ' . $sritoni_idnumber) ."</pre>";


        echo "<pre>" . print_r($wpuserobj, true) ."</pre>";

    }



    public static function test_custom_code($ticket_id)
    {
        // lets check to see if we can simulate sending mail on ticket creation

        // form the ticket object using passed id
        $ticket = new WPSC_Ticket( $ticket_id );
    }

        

    public static function test_ldap_and_moodle_connection( string $moodle_username )
    {
        $email = $moodle_username . '@headstart.edu.in';

        $ldap_user = self::get_user_account_from_ldap($email);

        // extract moodle user id from LDAP user array retrieved
        $ldap_user_moodle_id = $ldap_user['telexnumber'];

        // use the moodle user ID to retrieve Moodle user object
        // read in the Moodle API config array
        $config			= self::$config;

        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.
        // $MoodleRest->setDebug();
        // get moodle user details associated with this completed order from SriToni
        $parameters   = array("criteria" => array(array("key" => "id", "value" => $ldap_user_moodle_id) ) );

        // get moodle user satisfying above criteria
        $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);

        if ($moodle_users["exception"])
        {
            // There was a problem with the user update, log the details
            error_log("SriToni User Update returned variable dump");
            error_log(print_r($moodle_users, true));

        }
        else
        {
            echo "<h3>Connection to moodle server was successfull: Here are the details of Moodle user object for id:73</h3>";
            $moodle_user   = $moodle_users["users"][0];
            echo "<pre>" . print_r($moodle_user, true) ."</pre>";
        }
        
        
    }



    private function test_cashfree_connection($moodle_id)
    {
        // since wee need to interact with Cashfree , create a new API instamve.
        // this will also take care of getting  your API creedentials automatically.
        // the configfile path must always be the plugin name
        $configfilepath  = $this->plugin_name . "_config.php";
        $cashfree_api    = new CfAutoCollect($configfilepath); // new cashfree Autocollect API object

        $vAccountId = str_pad($moodle_id, 4, "0", STR_PAD_LEFT);

        // $va_id = "0073";	// VAID of sritoni1 moodle1 user

        // So first we get a list of last 3 payments made to the VAID contained in this HOLD order
        $payments        = $cashfree_api->getPaymentsForVirtualAccount($vAccountId, 1);

        
        echo "<h3> Payments made by userid: " . $vAccountId . "</h3>";
        echo "<pre>" . print_r($payments, true) ."</pre>";

        echo "<h3> PaymentAccount details of userid: " . $vAccountId . "</h3>";
        $vAccount = $cashfree_api->getvAccountGivenId($vAccountId);
        echo "<pre>" . print_r($vAccount, true) ."</pre>";
    }

    

 


    /**
     *
     */
    private function test_update_wc_product()
    {
        // run this since we may be changing API keys. Once in production remove this
        $this->get_config();

        $ticket_id = 23;

        $this->get_data_object_from_ticket($ticket_id);

        $data_object = $this->data_object;

        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    $this->config['wckey'],
                                    $this->config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);

        // Admission fee to HSET product ID
        $product_id = 581;

        $endpoint   = "products/" . $product_id;

        $product_data = [
                            'name'          => $data_object->product_customized_name,
                            'regular_price' => $data_object->admission_fee_payable
                        ];

        $product = $woocommerce->put($endpoint, $product_data);
        echo "<pre>" . print_r($product, true) ."</pre>";
    }



    private function test_create_wc_order($ticket_id)
    {
        // $ticket_id = 3;

        $this->get_data_object_from_ticket($ticket_id);

        $order_created = $this->create_wc_order_site_hsetpayments();

        echo "<pre>" . print_r($order_created, true) ."</pre>";
    }



    private function test_get_data_object_from_ticket($ticket_id)
    {
        // $ticket_id = 8;

        $data_object = $this->get_data_object_from_ticket($ticket_id);

        echo "<pre>" . print_r($data_object, true) ."</pre>";

        $category_id = $data_object->ticket_meta['ticket_category'];

        // from category ID get the category name
        $term = get_term_by('id',$category_id,'wpsc_categories');
        echo "<pre>" . "Category slug and category name - " . $term->slug . " : " . $term->name . "</pre>";
    }




    private function test_sritoni_account_creation()
    {
        $ticket_id = 3;

        $this->get_data_object_from_ticket($ticket_id);

        $ret = $this->create_sritoni_account();

        echo "<pre>" . print_r($ret, true) ."</pre>";
    }

    /**
     *
     */
    private static function change_status_error_creating_payment_shop_order( $ticket_id, $error_message )
    {
        $new_status_name = "Error Creating Payment Shop Order";

        self::change_ticket_status( $ticket_id, $new_status_name );

        // update agent field error message with the passed in error message
        if ( ! empty($error_message) )
        {
            self::change_ticket_field( $ticket_id, 'error', $error_message );
        }
    }




    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    private static function change_status_error_creating_sritoni_account( int $ticket_id,  string $error_message ):void
    {
        $new_status_name = "Error Creating SriToni Account";

        self::change_ticket_status( $ticket_id, $new_status_name );

        // update agent field error message with the passed in error message
        if (!empty($error_message))
        {
            self::change_ticket_field( $ticket_id, 'error', $error_message );
        }

    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param integer:$order_id
     *  @return object:$order
     * Uses the WooCommerce API to get back the order object for a given order_id
     * It prints outthe order object but this is only visible in a test page and gets overwritten by a short code elsewhere
     */
    public static function get_wc_order( int $order_id): ? object
    {
        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    self::$config['wckey'],
                                    self::$config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);


        $endpoint   = "orders/" . $order_id;
        $params     = array($order_id);
        $order      = $woocommerce->get($endpoint);

        echo "<pre>" . print_r($order, true) ."</pre>";

        return $order;
    }



    

    /**
     *  Since SUpport Candy update 3.0 the ueser cannot set the slug of the custom field. So we are forced to use the status name instead
     *  @param string:$status_name
     *  @return array:$tickets
     */
    public static function get_all_active_tickets_by_status_name( $status_name ) : array
    {
        $tickets = [];  // initialize empty array

        // first we need to get the id of the desired status based on its name passed in as the parameter
        $status_id_of_ticket = self::get_status_id_given_name( $status_name );

        $filter_array = array(
                                'meta_query' => array(
                                                        'relation' => 'AND',
                                                        array(
                                                            'slug'    => 'status',
                                                            'compare' => '=',
                                                            'val'     => $status_id_of_ticket,
                                                        ),
                            )
        );
    
        $tickets = WPSC_Ticket::find( $filter_array );

        return $tickets;
    }

}   // end of class bracket

headstart_admission::init();
