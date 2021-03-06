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
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @author     Madhu Avasarala
 */

//setup for the WooCommerce REST API
require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

class class_headstart_admission
{
	// The loader that's responsible for maintaining and registering all hooks that power
	protected $loader;

	// The unique identifier of this plugin.
	protected $plugin_name;

	 // The current version of the plugin.
	protected $version;

    //
    protected $config;

    /**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct()
    {
		if ( defined( 'HEADSTART_ADMISSION_VERSION' ) )
        {
			$this->version = HEADSTART_ADMISSION_VERSION;
		} else
        {
			$this->version = '2.0.0';
		}

		$this->plugin_name = 'headstart_admission';

        // load actions for admin
		if (is_admin()) $this->define_admin_hooks();

        // load public facing actions
		$this->define_public_hooks();

        // read the config file and build the secrets array
        $this->get_config();

        $this->verbose = true;

        // read the fee and description pairs from settings and form an associative array
        $this->admission_settings();
	}

    /**
     * This function is sually called once, from the class constructor
     * Get settings values for fee and description based on category
     * This array will be used to look up fee and payment description agent fields, based on category
     */
    private function admission_settings()
    {
        $setting_category_fee = get_option('headstart_admission_settings')['category_fee'];

        // each key=>value pair is on a separate line. So by exploding on EOL we get a numerical array with key=>value pairs
        $array1 = explode(PHP_EOL, $setting_category_fee); 

        // array_map takes array1 and makes it into an array of arrays by exploding => separator.
        // finally use the array_column to index the column 1 of each subarray indexed by column 0
        $this->category_fee_arr = array_column(array_map(function($row){
                                                                        return explode("=>", $row);
                                                                       }, $array1), 1, 0);

        // $this->verbose ? error_log(print_r($this->category_fee_arr, true)) : false;

        $setting_category_paymentdescription = get_option('headstart_admission_settings')['category_paymentdescription'];

        $array2 = explode(PHP_EOL, $setting_category_paymentdescription); 

        $this->category_paymentdescription_arr = array_column(array_map(function($row){
                                                                                        return explode("=>", $row);
                                                                                      }, $array2), 1, 0);

        $setting_category_cohortid = get_option('headstart_admission_settings')['category_cohortid'];

        // array is an array each entry corresponding to a line of text: category=>cohortid
        $array3 = explode(PHP_EOL, $setting_category_cohortid); 

        // array_map takes array3 and makes it into an array of arrays by exploding => separator.
        // finally use the array_column to index the column 1 of each subarray indexed by column 0
        $this->category_cohortid_arr = array_column(array_map(function($row){
                                                                                return explode("=>", $row);
                                                                            }, $array3), 1, 0);
    }


    /**
     * @return nul
     * This is the function that processes the webhook coming from hset-payments on any order that is completed
     * It extracts the order ID and then gets the order from the hset-payments site.
     * From the order the ticket_id is extracted and it's status is updated.
     */
    public function webhook_order_complete_process()
    {
        global $wpscfunction;

        // add these as properties of object
		$this->wc_webhook_secret    = $this->config['wc_webhook_secret'];

        if ( $_SERVER['REMOTE_ADDR']              == '68.183.189.119' &&
             $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE'] == 'https://sritoni.org/hset-payments/'     
           )
        {
            // if here it means that origin IP and domain have been verified

            $this->signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'];

            $request_body = file_get_contents('php://input');

            $signature_verified = $this->verify_webhook_signature($request_body);

            if ($signature_verified)
            {
                $this->verbose ? error_log("HSET order completed webhook signature verified") : false;

                $data = json_decode($request_body, false);  // decoded as object

                if ($data->action = "woocommerce_order_status_completed")
                {
                    $order_id = $data->arg;

                    $this->verbose ? error_log($data->action . " " . $order_id) : false;

                    // so we now have the order id for the completed order. Fetch the order object!
                    $order = $this->get_wc_order($order_id);

                    // from the order, extract the admission number which is our ticket id
                    $ticket_id = $order->admission_number;

                    // using the ticket id, update the ticket status to payment process completed
                    $status_id =  136; // admission-payment-process-completed
                    $wpscfunction->change_status($ticket_id, $status_id);

                    $transaction_id = str_replace (['{', '}'], ['', ''], $order->transaction_id);
 
                    // update the agent only fields payment-bank-reference which is really thee transaction_id
                    $wpscfunction->change_field($ticket_id, 'payment-bank-reference', $transaction_id);

                    // return after successful termination of webhook
                    return;
                }
            }
            else 
            {
                $this->verbose ? error_log("HSET order completed webhook signature NOT verified") : false;
                die;
            }
        }
        else
        {
            $this->verbose ? error_log("HSET order completed webhook source NOT verified") : false;
            $this->verbose ? error_log($_SERVER['REMOTE_ADDR'] . " " . $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE']) : false;

            die;
        }
    }

    private function verify_webhook_signature($request_body)
    {
        $signature    = $this->signature;
        $secret       = $this->wc_webhook_secret;

        return (base64_encode(hash_hmac('sha256', $request_body, $secret, true)) == $signature);
    }

    /**
     *  reads in a config php file and gets the API secrets. The file has to be in gitignore and protected
     *  The information is read into an associative arrray automatically by the nature of the process
     *  1. Key and Secret of Payment Gateway involved needed to ccheck/create VA and read payments
     *  2. Moodle token to access Moodle Webservices
     *  3. Woocommerce Key and Secret for Woocommerce API on payment server
     *  4. Webhook secret for order completed, from payment server
     */
    private function get_config()
    {
      $this->config = include( __DIR__."/" . $this->plugin_name . "_config.php");
    }


    /**
     *  @param integer:$order_id
     *  @return object:$order
     * Uses the WooCommerce API to get back the order object for a given order_id
     * It prints outthe order object but this is only visible in a test page and gets overwritten by a short code elsewhere
     */
    private function get_wc_order($order_id)
    {
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


        $endpoint   = "orders/" . $order_id;
        $params     = array($order_id);
        $order      = $woocommerce->get($endpoint);

        echo "<pre>" . print_r($order, true) ."</pre>";

        return $order;
    }


    /**
     * Define all of the admin facing hooks and filters required for this plugin
     * @return null
     */
    private function define_admin_hooks()
    {   // create a sub menu called Admissions in the Users menu
        add_action('admin_menu', [$this, 'add_my_menu']);
    }

    /**
     * Define all of the public facing hooks and filters required for this plugin
     * @return null
     */
    private function define_public_hooks()
    {
        // on admission form submission update user meta with form data
        // add_action( 'wpsc_ticket_created', [$this, 'update_user_meta_form'], 10, 1 );

        // do_action('wpsc_set_change_status', $ticket_id, $status_id, $prev_status);
        // This is the main state control for this App
        add_action('wpsc_set_change_status',        [$this, 'action_on_ticket_status_changed'], 10,3);

        // check Ninja form data before it is saved
        // add_filter( 'ninja_forms_submit_data',      [$this, 'action_validate_ninja_form_data'] );

        // after a NInja form submission, its data is mapped to a support ticket
        // This is the principal source of data for subsequent actions such as account creation
        add_action( 'ninja_forms_after_submission', [$this, 'map_ninja_form_to_ticket'] );

        // Just after a reply thread is submitted and inserted into the database, this action triggers
        // do_action('wpsc_after_submit_thread_request',$args,$thread_id)
        // add_action( 'wpsc_after_submit_thread_request', [$this, 'action_on_reply_submission'], 10, 2 );

        // add_action('wpsc_set_change_fields', [$this, 'action_on_ticket_field_changed'], 10,4);
    }

    /**
     *  NOT USED
     * 
     *  Was meant to be used when a given ticket field was updated and some action needed to be taken on that
     */
    public function action_on_ticket_field_changed($ticket_id, $field_slug, $field_val, $prev_field_val)
    {
        // we are only interested if the field that changed is non-empty payment-bank-reference. For all others return
        if ($field_slug !== "payment-bank-reference" || empty($field_val)) return;

        // if you get here this field is payment-bank-reference ad the value is non-empty
        // so we can go ahead and change the associated order's meta value to this new number
        // get the order-id for this ticket. If it doesn't exist then return
        $this->get_data_object_from_ticket($ticket_id);

        $order_id = $this->data_object->ticket_meta['order-id'];

        if (empty($order_id)) return;

        // check status of order to make sure it is still ON-HOLD
        $order = $this->get_wc_order($order_id);

        if (empty($order)) return;

        if ($order->status == "completed"  || $order->status == "processing")
        {
            return;
        }
        else
        {
            // lets now prepare the data for the new order to be created
            $order_data = [
                            'meta_data' => [
                                                [
                                                    'key' => 'bank_reference',
                                                    'value' => $field_val
                                                ]
                                            ]
                        ];
            $order_updated = $this->update_wc_order_site_hsetpayments($order_id, $order_data);

            $this->verbose ? error_log("Read back bank reference from Updated Order ID:" . $order_id . " is: " . $order_updated->bank_reference): false;
        }
    }


    /**
     *  Given a keyword, looks through the array and returns the index for a partial match. Returns false if no match found
     *  @param array:$arr is the array to be serached
     *  @param string:$keyword is the key to be serached for even a partial match
     *  @return integer:$index the index of the array whose value matches at least partially with the given keyword
     */
    public function array_search_partial($arr, $keyword) 
    {   // Given a keyword, looks through the array and returns the index for a partial match. Returns false if no match found
        foreach($arr as $index => $string) 
        {
            if (stripos($string, $keyword) !== FALSE)
                return $index;
        }
        return false;
    }


    /**
     *  NOT USED
     *  The AJAX in the form was not working correctly, so abandoned
     *  @param array:$form_data is the form data Ninja forms
     *  1. if category contains internal then the email must contain headstart.edu.in, otherwise form should be corrected
     */
    public function action_validate_ninja_form_data( $form_data )
    {
        // extract the fields array from the form data
        $fields_ninjaforms = $form_data['fields'];

        // extract a single column from all fields containing the admin_label key
        $key_array         = array_column($fields_ninjaforms, 'key');

        // extract the corresponding value array. They both will share the same  numerical index.
        $value_array       = array_column($fields_ninjaforms, 'value');

        $field_id_array    = array_column($fields_ninjaforms, 'id');

        // Check if form category contains internal or not. The category is a hidden field
        // look for the mapping slug in the ninja forms field's admin label
        $index_ticket_category  = $this->array_search_partial( $key_array, 'ticket_category' );
        $category_name          = $value_array[$index_ticket_category];

        $index_address  = $this->array_search_partial( $key_array, 'residential_address' );
        $address        = $value_array[$index_address];

        $index_email    = $this->array_search_partial( $key_array, 'primary_email' );
        $email          = $value_array[$index_email];


        if ( stripos($category_name, "internal") !== false )
        {
            // the forms's hidden field for category does contain substring internal so we need to check  for headstart domain
            // look for the mapping slug in the ninja forms field's admin label for email field

            // check if the email contains headstart.edu.in
            if ( stripos( $email, "headstart.edu.in") === false)
            {
                $this->verbose ? error_log("validating email - Internal user, expecting @headstart.edu.in, didnt find it"): false;
                // our form's category is internal but does not contain desired domain so flag an error in form

                //
                $form_data['errors']['fields'][$field_id_array[$index_email]] = 'Email must be of Head Start domain, because continuing student';
            }

        }
        if ( stripos($address, "/") !== false )
        {
            $this->verbose ? error_log("validating address - does contain forbidden character '/'."): false;

            $errors = [
                'fields' => [
                $field_id_array[$index_address]   => 'Addtress must not contain "/" please correct'
                ],
              ];

            $response = [
            'errors' => $errors,
            ];
            
            wp_send_json( $response );
            wp_die(); // this is required to terminate immediately and return a proper response
        }
        
        return $form_data;


    }

    /**
     *  @return void Nothing is returned
     *  @param array $form_data from the Ninja forms based on an action callback
     *  The function takes the Ninja form immdediately after submission
     *  The form data is captured into the fields of a new ticket that is to be created as a result of this submission.
     *  The agent only fields are not updated by the form and will be null.
     *  The Admin needs to set these fields which are mostly for new users.
     *  The data is not modified in anyway except for  residential-address where / is replaced by -
     */
    public function map_ninja_form_to_ticket( $form_data )
    {
        global $wpscfunction;

        // $form_data['fields']['id']['seetings']['admin_label']
        // $form_data['fields']['id'][''value']
        // Loop through each of the ticket fields, match its slug to the admin_label and get its corresponding value
        $current_user       = wp_get_current_user();
        $registered_email   = $current_user->user_email;

        // Initialize the new ticket values array needed for a new ticket creation
        $ticket_args = [];

        // extract the fields array from the form data
        $fields_ninjaforms = $form_data['fields'];

        // extract a single column from all fields containing the admin_label key
        $admin_label_array = array_column(array_column($fields_ninjaforms, 'settings'), 'admin_label');

        // extract the corresponding value array. They both will share the same  numerical index.
        $value_array       = array_column(array_column($fields_ninjaforms, 'settings'), 'value');

        // get the ticket field objects using term search. We are getting only the non-agent ticket fields here for mapping
        // since the form only contains user input
        $ticket_fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',
            'meta_query' => array(
                                    'relation' => 'AND',
                                    array(
                                        'key'       => 'agentonly',
                                        'value'     => '0',
                                        'compare'   => '='
                                    ),
                                )
        ]);

        foreach ($ticket_fields as $ticket_field):

            if ($ticket_field->slug == 'ticket_priority')
            {
                continue;     // we don't modify these fields values, so skip
            }

            // capture the ones of interest to us
            switch (true):

                // customer_name ticket field mapping. The form must have an admin label same as this
                case ($ticket_field->slug == 'customer_name'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('customer_name', $admin_label_array);

                    if ($key !== false)
                    {
                        // get the form-captured field value for customer-name
                        $customer_name = $value_array[$key];
                        // We want to format it with 1st letter apital and rest in lowercase
                        $customer_name_arr = explode(" ", $customer_name);

                        $name = "";
                        foreach ($customer_name_arr as $partname)
                        {
                            $name .= " " . ucfirst(strtolower($partname));
                        }

                        // remove extraneous spaces at beginning and or end
                        $ticket_args[$ticket_field->slug]= trim($name);
                    }
                    else
                    {
                        $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms value array") : false;
                        
                    }
                    break;



                    // ticket_category custom field gets mapped to ticket_category in Ninja forms but need id not slug
                case ($ticket_field->slug == 'ticket_category'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('ticket_category', $admin_label_array);

                    $category_name = $value_array[$key];

                    // now to get the category id using the slug we got from the ninja form field
                    $term = get_term_by('slug', $category_name, 'wpsc_categories');

                    // we give the category's term_id, not its name, when we create the ticket.
                    $ticket_args[$ticket_field->slug]= $term->term_id;

                    break;



                    // customer email in ticket maps to the user registered email.
                    // This is done so that all communication is on the registered email.
                case ($ticket_field->slug == 'customer_email'):

                    $ticket_args[$ticket_field->slug]= $registered_email;

                    break;


                    // map the ticket field 'headstart-email' to Ninja forms 'primary-email' field
                case ($ticket_field->slug == 'headstart-email'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('primary-email', $admin_label_array);

                    if ($key !== false)
                    {
                        $ticket_args[$ticket_field->slug]= $value_array[$key];
                    }
                    else
                    {
                        $this->verbose ? error_log( "index for: primary-email, not found in Ninja forms value array" ) : false;
                    }

                    break;


                    // the subject is fixed to Admission
                case ($ticket_field->slug == 'ticket_subject'):

                    // default for all users
                    $ticket_args[$ticket_field->slug]= 'Admission';

                    break;

                    // Description is a fixed string
                case ($ticket_field->slug == 'ticket_description'):

                    // default for all users
                    $ticket_args[$ticket_field->slug]= 'Admission';

                    break;

                    // ensure that the address does not contain forbidden characters.
                    // replace a / with a - otherwise the ticket creation will not happen
                case ($ticket_field->slug == 'residential-address'):
                    // set the search and replace strings
                    $find       = "/";
                    $replace    = "-";

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('residential-address', $admin_label_array);

                    if ($key !== false)
                    {
                        // get the custmeer entered residential address using the key
                        $value    = $value_array[$key];

                        // set the value for the ticket custom field by search and replace of potential bad character "/"
                        $ticket_args[$ticket_field->slug] = str_ireplace($find, $replace, $value);
                    }
                    else
                    {
                        $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms map to Ticket") : false;
                    }
                    
                    break;


                case ($ticket_field->slug == 'student-first-name'):
                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('student-first-name', $admin_label_array);

                    if ($key !== false)
                    {
                        // get the custmeer entered students first name
                        $value    = $value_array[$key];

                        // Convert to lowercase and Capitalize the 1st letter
                        $ticket_args[$ticket_field->slug] = ucfirst(strtolower($value));
                    }
                    else
                    {
                        $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms map to Ticket") : false;
                    }
                    break;


                case ($ticket_field->slug == 'student-middle-name'):
                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('student-middle-name', $admin_label_array);

                    if ($key !== false)
                    {
                        // get the custmeer entered students first name
                        $value    = $value_array[$key];

                        // Convert to lowercase and Capitalize the 1st letter
                        $ticket_args[$ticket_field->slug] = ucfirst(strtolower($value));
                    }
                    else
                    {
                        $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms map to Ticket") : false;
                    }
                    break;

                case ($ticket_field->slug == 'student-last-name'):
                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('student-last-name', $admin_label_array);

                    if ($key !== false)
                    {
                        // get the custmeer entered students first name
                        $value    = $value_array[$key];

                        // Convert to lowercase and Capitalize the 1st letter
                        $ticket_args[$ticket_field->slug] = ucfirst(strtolower($value));
                    }
                    else
                    {
                        $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms map to Ticket") : false;
                    }
                    break;


                default:

                    // from here on the ticket slug is same as form field slug so mapping is  easy.
                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search($ticket_field->slug, $admin_label_array);

                    if ($key !== false)
                    {
                        $ticket_args[$ticket_field->slug]= $value_array[$key];
                    }
                    else
                    {
                        $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms map to Ticket") : false;
                    }

                    break;

            endswitch;          // end switching throgh the ticket fields looking for a match

        endforeach;             // finish looping through the ticket fields for mapping Ninja form data to ticket

        // we have all the necessary ticket fields filled from the Ninja forms, now we can create a new ticket
        $ticket_id = $wpscfunction->create_ticket($ticket_args);
    }


    /**
     *  This is the  callback that triggers the various tasks contingent upon ticket status change to desired one
     *  When the status changes to Admission Granted, the payment process is triggered immediately
     *  When the ststus is changed to Admission Confirmed the SriToni new user account creation is triggered
     *  More can be added here as needed.
     */
    public function action_on_ticket_status_changed($ticket_id, $status_id, $prev_status)
    {   // status change triggers the main state machine
        global $wpscfunction;

        // add any logoc that you want here based on new status
        switch (true):

            case ($wpscfunction->get_status_name($status_id) === 'Interaction Completed'):

                // we set the product description and its amount from information in the settings
                $this->get_data_object_from_ticket($ticket_id);

                // get the category id from the ticket
                $ticket_category_id = $this->data_object->ticket_meta['ticket_category'];
                $fullname           = $this->data_object->ticket_meta['student-first-name']  . " " . 
                                      $this->data_object->ticket_meta['student-middle-name'] . " " .
                                      $this->data_object->ticket_meta['student-last-name'];
                // get the ticket category name from ID
                $term_category              = get_term_by('id', $ticket_category_id, 'wpsc_categories');

                // the category slug is used as key to get the required data
                $admission_fee_payable      = $this->category_fee_arr[$term_category->slug];

                $product_customized_name    = $this->category_paymentdescription_arr[$term_category->slug] . " " . $fullname;

                // update the agent fields for fee and fee description
                $wpscfunction->change_field($ticket_id, 'admission-fee-payable', $admission_fee_payable);

                $wpscfunction->change_field($ticket_id, 'product-customized-name', $product_customized_name);
                
                 break;

            case ($wpscfunction->get_status_name($status_id) === 'School Accounts Being Created'):

                // Create a new user account in SriToni remotely
                $moodle_id = $this->create_update_user_account($ticket_id);

                // if successful in sritoni account creation change to next status - admission-payment-order-being-created
                if ($moodle_id)
                {
                    // TODO: update an agent only field with this information so that the sritoni idnumber can be same as this
                    // $this->update_sritoni_idnumber($moodle_id);
                }
            break;



            case ($wpscfunction->get_status_name($status_id) === 'Admission Payment Order Being Created'):

                // This assumes that we have a valid sritoni account, a valid hset-payment account
                $this->create_payment_shop_order($ticket_id);

            break;


            case ($wpscfunction->get_status_name($status_id) === 'Admission Granted'):

                // Emails are sent to user for making payments.
                // Once payment is made the Order is set to Processing
                // Once Admin sets Order as completed, the webhook fires on the payment site and is captured on this site
                // The captured webhook sets the status as Payment completed
                // There is no other explicit code to be executed here

             break;


            case ($wpscfunction->get_status_name($status_id) === 'Admission Confirmed'):

                // No explicit action in code here
                // The admin needs to trigger the next step by changing the status
            break;


            default:
                // all other cases come here and flow down with no action.
            break;

        endswitch;      // END of switch  status change actions
    }                  

    /**
     *  This routine is typically called by a scheduled task from outside the class using the instance so this is public
     *  No pre-requisites. Statuses have to  exist in ticket system settings
     *  1. Get a list of all tickets having status: 'school-accounts-being-created'
     *  2. For each ticket, poll the hset-payments site and check if ticket user's user account exists
     *  3. If user account exists, change status of that ticket to enable PO creation: 'admission-payment-order-being-created'
     */
    public function check_if_accounts_created()
    {   // check if (new) accounts are sync'd and exist on payment server - if so change status to admission-payment-order-being-created
        global $wpscfunction, $wpdb;

        // $this->verbose ? error_log("In function check_if_accunts_created caused by scheduled event:"): false;

        // keep status id prepared in advance to change status of selected ticket in loop
        $status_id      = get_term_by('slug','admission-payment-order-being-created','wpsc_statuses')->term_id;

        // get all tickets that have payment status as shown. 
        $tickets = $this->get_all_active_tickets_by_status_slug('school-accounts-being-created');

        $this->verbose ? error_log("Number of tickets being processed with status 'school-accounts-being-created':" . count($tickets)): false;

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            $data_object = $this->get_data_object_from_ticket($ticket_id);

            // since we are checking for creation of new accounts we cannot use ticket email.
            // we have to use admin given username (agent field) with our domain. So username has to be set for this.
            // since this comes after sritoni account creation we know this would have been set.
            $headstart_email = $data_object->ticket_meta['headstart-email'];

            if ( !empty($headstart_email) && stripos( $headstart_email, "@headstart.edu.in" ) !== false )
            {
                // This is a continuing head start user so use existing headstart email
                $email = $headstart_email;
            }
            else
            {
                // this is a new headstart user so compose the email
                $email = $data_object->ticket_meta['username'] . '@headstart.edu.in';
            }
            

            // check if wpuser with this email exists in site hset-payments
            $wp_user_hset_payments = $this->get_wp_user_hset_payments($email, $ticket_id);

            if ($wp_user_hset_payments)
            {
                // we have a valid customer so go ahead and change status of this ticket to enable PO creation
                $wpscfunction->change_status($ticket_id, $status_id);

                // log the user id and displayname
                $this->verbose ? error_log("User Account with id:" . $wp_user_hset_payments->id 
                                                    . " And name:" . $wp_user_hset_payments->data->display_name): false;

            }

        endforeach;
    }


    /**
     *  @param string:$email
     *  @return object:$customers[0]
     * Pre-requisites: None
     * 1. Get wpuser object from site hset-payments with given email using Woocommerce API
     * 2. If site is unreacheable change status of ticket to error
     */
    public function get_wp_user_hset_payments($email, $ticket_id)
    {   // Get wpuser object from site hset-payments with given email using Woocommerce API
        global $wpscfunction;

        // $data_object = $this->data_object;

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


        $endpoint   = "customers";

        $params     = array( 'role'  =>'subscriber', 'email' => $email );
        // get customers with given parameters as above, there should be a maximum of 1
        try
        {
            $customers = $woocommerce->get($endpoint, $params);
        }
        catch (HttpClientException $e)
        {   // if cannot access hset-payments server error message and return 1
            $error_message = "Could NOT access hset-payments site to get customer details: " . $e->getMessage();
            $this->change_status_error_creating_payment_shop_order($ticket_id, $error_message);

            return null;
        }
            
         // if you get here then you got something back from hset-payments site
         return $customers[0];
    }


    /**
     * @param integer:$ticket_id
     * @return void
     * Takes the data object from the ticket using the ticket_id
     * Get customer_id from hset-payments site using Woocommerce API
     * Create a new Payment Order using all the data for the given customer_id
     * Update the ticket field with the order_id created
     */
    public function create_payment_shop_order($ticket_id)
    {   // from email get customer_id and create a new Payment Order - update ticket field with order_id
        global $wpscfunction;

        // buuild an object containing all relevant data from ticket useful for crating user accounts and payments
        $this->get_data_object_from_ticket($ticket_id);

        // get the category id from the ticket
        $ticket_category_id = $this->data_object->ticket_meta['ticket_category'];
        
        // get the ticket category name from ID
        $term_category      = get_term_by('id', $ticket_category_id, 'wpsc_categories');

        /*
        1. Get customer object from site hset-payments using the email
        2. Check if VA data in the user meta is valid. If not update if VA exists. If VA does not exist, create new VA
        3. If VA updated or created, update the site hset-payments with updated user meta for VA data.
        */
        $wp_user_hset_payments = $this->get_wpuser_hset_payments_check_create_cfva();

        if (empty($wp_user_hset_payments)) return;  // safety catch

        $customer_id = $wp_user_hset_payments->id;

        // if you got here you must be a head start user with a valid VA and customer_id and valid customer object

        // let's write this customer id to the ticket's agent only field for easy reference
        $wpscfunction->change_field($ticket_id, 'wp-user-id-hset-payments', $customer_id);
        
        // Assign the customer as a property of the class in case we need it later on
        $this->data_object->wp_user_hset_payments = $wp_user_hset_payments;

        // check that admission-fee-payable and product-customized-name  fields are set
        $product_customized_name    = $this->data_object->ticket_meta["product-customized-name"];
        if ( empty( $product_customized_name ) )
        {
            // means agent has not set it so get the value from the settings
            $fullname       = $this->data_object->ticket_meta['student-first-name']  . " " . 
                              $this->data_object->ticket_meta['student-middle-name'] . " " .
                              $this->data_object->ticket_meta['student-last-name'];
            $product_customized_name = $this->category_paymentdescription_arr[$term_category->slug] . " " . $fullname;
        }
        
        $regular_price = $this->data_object->ticket_meta["admission-fee-payable"];
        if ( empty( $regular_price ) )
        {
            // agent has not set this so use the value from settings based on ticket category
            $regular_price = $this->category_fee_arr[$term_category->slug];
        }


        if (empty($regular_price) || empty($product_customized_name))
        {
            // change ticket status to error with an error message
            $error_message = "Admission-fee-payable and or the product-customized-name fields need to be set";

            $this->change_status_error_creating_payment_shop_order($ticket_id, $error_message);

            return;
        }

        $new_order = $this->create_wc_order_site_hsetpayments();

        $this->verbose ? error_log($new_order->id . " ID of newly created payment SHOP Order") : false;

        // update the agent field with the newly created order ID
        $wpscfunction->change_field($ticket_id, 'order-id', $new_order->id);
    }


    /**
     * 1. This function grabs all ticket fields (agent and non-agent) data from a given ticket id
     * 2. It then creates a new data_object that contains all of the ticket data,for ease of access
     * 3. This data_object is also set as a property of $this class
     *  @param integer:$ticket_id
     *  @return object:$data_object
     */
    private function get_data_object_from_ticket($ticket_id)
    {   // create a data object from ticket fields from ticket using ticket_id
        global $wpscfunction;

        $this->ticket_id    = $ticket_id;
        $this->ticket_data  = $wpscfunction->get_ticket($ticket_id);

        $data_object = new stdClass;

        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',

            'meta_query' => array(
                                    array(
                                        'key'       => 'agentonly',
                                        'value'     => ["0", "1"],  // get all ticket meta fields
                                        'compare'   => 'IN',
                                        ),
                                ),

        ]);

        // create a new associative array that holds the ticket field object keyed by slug. This way we can get it on demand
        $ticket_meta = [];

        foreach ($fields as $field)
        {
            if ($field->slug == "ticket_category"   ||
                $field->slug == "ticket_status"     ||
                $field->slug == "ticket_priority"   ||
                $field->slug == "ticket_subject"    ||
                $field->slug == "customer_name"     ||
                $field->slug == "customer_email"
            )
            {
                // this data is avaulable directly from ticket data and is blank in ticket meta so this work around
                $ticket_meta[$field->slug] = $this->ticket_data[$field->slug];
            }
            else
            {
                $ticket_meta[$field->slug] = $wpscfunction->get_ticket_meta($ticket_id, $field->slug, true);
            }
        }

        $data_object->ticket_id      = $ticket_id;
        $data_object->ticket_data    = $this->ticket_data;
        $data_object->ticket_meta    = $ticket_meta;        // to access: $data_object->ticket_meta[fieldslug]

        // ticket_meta data for category, status, subject, description, applicant name and email are blank
        // use only for stuff like student name, etc.
        // get that other data from ticket_data array if needed.
        $this->data_object           = $data_object;

        return $data_object;
    }



    /**
     *  pre-reqiosites before coming here:
     * 1. $this->get_data_object_from_ticket($ticket_id) to get the data from ticket
     * 
     *  Gets customer user object from payments site and returns it. No Cashfree account check or creation.
     *
     * @return obj:woocommerce customer object - null returned in case of server error or if user does not exist or bad email
     */
    private function get_wpuser_hset_payments_check_create_cfva()
    {   // Get customer User Object from payments site using email. No Cashfree API used anymore
        global $wpscfunction;

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

        $data_object = $this->data_object;

        $category_id = $data_object->ticket_meta['ticket_category'];

        // from category ID get the category name
        $category_slug = get_term_by('id', $category_id, 'wpsc_categories')->slug;

        // for Internal users get email directly from ticket->headstart-email - for new users, use agent assigned username
        if (stripos($category_slug, "internal") === false)
        {       // Category slug does NOT contain 'internal' so external user application so use agent assigned username
            $email = $data_object->ticket_meta['username'] . '@headstart.edu.in'; 
        }
        else
        {       // headstart user so use form/ticket email directly
            $email = $data_object->ticket_meta['headstart-email'];

            if (stripos($email, "headstart.edu.in") === false)
            {   // email is NOT headstart domain, cannot process further
                $this->verbose? error_log("Email is NOT of Head Start Domain: " . $email) : false;
                $error_message = "Email is NOT Head Start Domain: " . $email;
                $this->change_status_error_creating_payment_shop_order($data_object->ticket_id, $error_message);

                return null;
            }
        }
        

        // get the  WP user object from the hset-payments site using woocommerce API, set error status if not successfull
        $wp_user_hset_payments = $this->get_wp_user_hset_payments($email, $data_object->ticket_id);

        return $wp_user_hset_payments;

        /* get the moodle_id which is same as wpuser's login at the hset-payments site
        $moodle_id = $wp_user_hset_payments->username;

        // pad the moodleuserid with leading 0's if length less than 4. If not leave alone
        $vAccountId = str_pad($moodle_id, 4, "0", STR_PAD_LEFT);

        // extract VA data from the WP customer object meta, obtained from site hset-payments
        // form array_keys and array_values from user meta to search for user meta data
        $array_meta_key    = array_column($wp_user_hset_payments->meta_data, "key");
        $array_meta_value  = array_column($wp_user_hset_payments->meta_data, "value");

        $index_va_id            = array_search("va_id", $array_meta_key);
        $index_account_number   = array_search("account_number", $array_meta_key);
        $index_beneficiary_name = array_search("beneficiary_name", $array_meta_key);
        $index_va_ifsc_code     = array_search("va_ifsc_code", $array_meta_key);

        $va_id              = $array_meta_value[$index_va_id]               ?? null;
        $account_number     = $array_meta_value[$index_account_number]      ?? null;
        $beneficiary_name   = $array_meta_value[$index_beneficiary_name]    ?? null;
        $va_ifsc_code       = $array_meta_value[$index_va_ifsc_code]        ?? null;
        */
    }



    /**
     *  Creates a new Order on the payments site
     *  Prerequisites:
     *  1. Valid data_object for this ticket: $this->get_data_object_from_ticket($ticket_id)
     *  2. $this->get_wpuser_hset_payments_check_create_cfva() to get valid customet object and also valid VA
     *  
     *  This function creates a new Order at the payments site using information derived from ticket and customer object
     *  @return obj:$order_created
     */
    private function create_wc_order_site_hsetpayments()
    {   // creates a new Order at the payments site using information derived from ticket and customer object
        global $wpscfunction;
        
        $array_meta_key     = [];
        $array_meta_value   = [];

        $index              = null;

        // before coming here the create account object is already created. We jsut use it here.
        $data_object = $this->data_object;

        // fix the customer_id and corresponding va_id
        if ($this->data_object->wp_user_hset_payments)
        {
            // customer object is not null, so should contain valid customer ID and va_id
            // customer ID is the WP id of this user in site hset-payments
            $customer_id = $data_object->wp_user_hset_payments->id;

            $moodle_id = $data_object->wp_user_hset_payments->username;

            $va_id = str_pad($moodle_id, 4, "0", STR_PAD_LEFT);
        }
        else
        {
            $this->change_status_error_creating_payment_shop_order($data_object->ticket_id, 'Null customer object found at line 1054 -  No PO created');
            $this->verbose ? error_log("Null wp user object found at line 1045 -  No PO created for ticket:" . $data_object->ticket_id): false;
            return;
        }
        
        // derive the fee and description from ticket field/settings based on agent override
        // get the category id from the data object ticket meta
        $ticket_category_id = $this->data_object->ticket_meta['ticket_category'];
        
        // get the ticket category name from ID
        $term_category      = get_term_by('id', $ticket_category_id, 'wpsc_categories');
		
		// check if admission-fee-payable and product-customized-name  fields are set by agent
        $product_customized_name    = $this->data_object->ticket_meta["product-customized-name"];
        if ( empty( $product_customized_name ) )
        {
            // means agent has not set it so get the value from the settings
            $fullname       = $this->data_object->ticket_meta['student-first-name']  . " " . 
                              $this->data_object->ticket_meta['student-middle-name'] . " " .
                              $this->data_object->ticket_meta['student-last-name'];
            $product_customized_name = $this->category_paymentdescription_arr[$term_category->slug] . " " . $fullname;
        }
        
        $regular_price = $this->data_object->ticket_meta["admission-fee-payable"];
        if ( empty( $regular_price ) )
        {
            // agent has not set this so use the value from settings based on ticket category
            $regular_price = $this->category_fee_arr[$term_category->slug];
        }

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

        // Admission fee to HSET product ID. This is the admission product whose price and description can be customized
        $product_id = 581;

        $endpoint   = "products/" . $product_id;

        // customize the Admission product for this user
        $product_data = [
                            'name'          => $product_customized_name,
                            'regular_price' => $regular_price,
                        ];
        // TODO use try catch here
        $product = $woocommerce->put($endpoint, $product_data);

        // lets now prepare the data for the new order to be created for this user
        // we use customer_email as billing email to not acommunicate to headstart-mail.
        $order_data = [
            'customer_id'           => $customer_id,        // this is important, needs to pre-exist on site
            'payment_method'        => 'vabacs',
            'payment_method_title'  => 'Offline Direct bank transfer to Head Start Educational Trust',
            'set_paid'              => false,
            'status'                => 'on-hold',
            'billing' => [
                'first_name'    => $data_object->ticket_meta['customer_name'],
                'last_name'     => '',
                'address_1'     => $data_object->ticket_meta['residential-address'],
                'address_2'     => '',
                'city'          => $data_object->ticket_meta['city'],
                'state'         => $data_object->ticket_meta['state'],
                'postcode'      => $data_object->ticket_meta['pin-code'],
                'country'       => $data_object->ticket_meta['country'],
                'email'         => $data_object->ticket_meta['customer_email'], // used for payment communications
                'phone'         => $data_object->ticket_meta['emergency-contact-number'],
            ],
            'shipping' => [
                'first_name'    => $data_object->ticket_meta['customer_name'],
                'last_name'     => '',
                'address_1'     => $data_object->ticket_meta['residential-address'],
                'address_2'     => '',
                'city'          => $data_object->ticket_meta['city'],
                'state'         => $data_object->ticket_meta['state'],
                'postcode'      => $data_object->ticket_meta['pin-code'],
                'country'       => $data_object->ticket_meta['country'],
            ],
            'line_items' => [
                [
                    'product_id'    => 581,
                    'quantity'      => 1
                ],
            ],
            'meta_data' => [
                [
                    'key' => 'va_id',
                    'value' => $va_id
                ],
                [
                    'key' => 'sritoni_institution',
                    'value' => 'admission'
                ],
                [
                    'key' => 'grade_for_current_fees',
                    'value' => 'admission'
                ],
                [
                    'key' => 'name_on_remote_order',
                    'value' => $data_object->ticket_meta['student-first-name']  . " "   .
                               $data_object->ticket_meta['student-middle-name'] . " "   .
                               $data_object->ticket_meta['student-last-name']
                ],
                [
                    'key' => 'payer_bank_account_number',
                    'value' => $data_object->ticket_meta['payer-bank-account-number']
                ],
                [
                    'key' => 'admission_number',
                    'value' => $data_object->ticket_id,
                ],

            ],
        ];

        // finally, lets create the new order using the Woocommerce API on the remote payment server
        $order_created = $woocommerce->post('orders', $order_data);

        // check if the order has been created and if so what is the order ID
        if (!empty($order_created->id))
        {
            // Blank any pre-existing error message since we are successful
            if ( !empty($data_object->ticket_meta["error"]) )
            {
                $wpscfunction->change_field($data_object->ticket_id, 'error', "");
            }
            return $order_created;
        }
        else
        {
            // there was an error in creating the prder. Update the status and the error message for the ticket
            $this->change_status_error_creating_payment_shop_order($data_object->ticket_id, 'could NOT create payment order, check');
        }
    }


    /**
     *  @param int:$order_id
     *  @param array:$order_data
     *  Update the order with the given order data. The order_data has to be constructed as per requirements
     */
    public function update_wc_order_site_hsetpayments($order_id, $order_data)
    {   // Update the order with the given order data. The order_data has to be constructed as per requirements
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
                                    

        $endpoint       = "orders/" . $order_id;

        $order_updated  = $woocommerce->put($endpoint, $order_data);

        return $order_updated;
    }


    /**
     * @param integer $ticket_id
     * @return void
     * 1. Get the data object for account creation from ticket
     * 2. If user already has a Head Start account (detected by their email domain) update user
     * 3. Check that required data is not empty. If so, change ticket status to error
     * 4. Processd for SriToni account creation
     */

    public function create_update_user_account($ticket_id)
    {   // update existing or create a new SriToni user account using data from ticket. Add user to cohort based on ticket data
        global $wpscfunction;

        // buuild an object containing all relevant data from ticket useful for crating user accounts and payments
        $this->get_data_object_from_ticket($ticket_id);

        if (stripos($this->data_object->ticket_meta['headstart-email'], 'headstart.edu.in') !== false)
        {
            // User already has an exising SriToni email ID and Head Start Account, just update with form info
            // does not need any agent only fields to be set as they are not involved in the update
            $this->update_sritoni_account();
            
            // add user to relevant incoming cohort for easy cohort management
            $this->add_user_to_cohort();

            return;
        }

        // if you are here you don't have an existing Head Start email. So Create a new SriToni account using username
        // check if all required data for new account creation is set
        if 
        (   !empty( $this->data_object->ticket_meta['username'] )      &&
            !empty( $this->data_object->ticket_meta['idnumber'] )      &&
            !empty( $this->data_object->ticket_meta['studentcat'] )    &&
            !empty( $this->data_object->ticket_meta['department'] )    &&
            !empty( $this->data_object->ticket_meta['institution'] )   &&
            !empty( $this->data_object->ticket_meta['class'] )
        )        
        {
            // go create a new SriToni user account for this child using ticket dataa. Return the moodle id if successfull
            $moodle_id = $this->create_sritoni_account();

            // after creation of SriToni user account add the user to appropriate cohort for easy management later on
            $this->add_user_to_cohort();

            return $moodle_id;
        }
        else
        {
            $error_message = "Sritoni Account NOT CREATED! Ensure username, idnumber, studentcat, department, and institution fields are Set";
            $this->change_status_error_creating_sritoni_account($this->data_object->ticket_id, $error_message);

            return null;
        }
    }


    /**
     *  @return integer moodle user id from table mdl_user. null if error in creation
     *  This is to be called after the data_object has been already created.
     *      AND the data checked to ensure it is set and not empty
     *  1. Function checks if username is not taken, only then creates new account.
     *  2. If error in creating new accoount or username is taken ticket status changed to error.
     */
    private function create_sritoni_account()
    {   // checks if username is not taken, only then creates new account -sets ticket error if account not created
        // before coming here the create account object should be already created. We jsut use it here.
        global $wpscfunction;

        $data_object = $this->data_object;

        // run this again since we may be changing API keys. Once in production remove this
        // $this->get_config();

        // read in the Moodle API config array
        $config			= $this->config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        $moodle_username    = $data_object->ticket_meta["username"];
        $moodle_email       = $moodle_username . "@headstart.edu.in";

        $moodle_environment = $data_object->ticket_meta["environment"];
        if (empty($moodle_environment)) $moodle_environment = "NA";

        $moodle_phone1      = $data_object->ticket_meta["emergency-contact-number"] ?? "1234567890";
        $moodle_phone2      = $data_object->ticket_meta["emergency-alternate-contact"] ?? "1234567890";
        $moodle_department  = $data_object->ticket_meta["department"] ?? "Student";

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.
        // $MoodleRest->setDebug();
        // get moodle user details associated with this completed order from SriToni
        $parameters   = array("criteria" => array(array("key" => "username", "value" => $moodle_username)));

        // get moodle user satisfying above criteria if any
        $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);

        if ( ( $moodle_users["users"][0] ) )
        {
            // An account with this username already exssts. So set error status so Admin can set a different username and try again
            $error_message = "This username already exists! Is this an existing user? If not change username and retry";

            $this->verbose ? error_log($error_message) : false;

            // change the ticket status to error
            $this->change_status_error_creating_sritoni_account($data_object->ticket_id, $error_message);

            return;
        }

        // if you are here we have a username that does not exist yet so create a new moodle user account with this username

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
                                                    "idnumber"      => $data_object->ticket_meta["idnumber"],
                                                    "auth"          => "oauth2",
                                                    "firstname"     => $data_object->ticket_meta["student-first-name"],
                                                    "lastname"      => $data_object->ticket_meta["student-last-name"],
                                                    "email"         => $moodle_email,
                                                    "middlename"    => $data_object->ticket_meta["student-middle-name"],
                                                    "institution"   => $data_object->ticket_meta["institution"],
                                                    "department"    => $moodle_department,
                                                    "phone1"        => $moodle_phone1,
                                                    "phone2"        => $moodle_phone2,
                                                    "address"       => $data_object->ticket_meta["residential-address"],
                                                    "maildisplay"   => 0,
                                                    "createpassword"=> 0,
                                                    "city"          => $data_object->ticket_meta["city"],

                                                    "customfields" 	=> array(
                                                                                array(	"type"	=>	"class",
                                                                                        "value"	=>	$data_object->ticket_meta["class"],
                                                                                    ),
                                                                                array(	"type"	=>	"environment",
                                                                                        "value"	=>	$moodle_environment,
                                                                                    ),
                                                                                array(	"type"	=>	"emergencymob",
                                                                                        "value"	=>	$moodle_phone1,
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
                                                                                        "value"	=>	$data_object->ticket_meta["studentcat"],
                                                                                    ),
                                                                                array(	"type"	=>	"bloodgroup",
                                                                                        "value"	=>	$data_object->ticket_meta["blood-group"],
                                                                                    ),
                                                                                array(	"type"	=>	"motheremail",
                                                                                        "value"	=>	$data_object->ticket_meta["mothers-email"],
                                                                                    ),
                                                                                array(	"type"	=>	"fatheremail",
                                                                                        "value"	=>	$data_object->ticket_meta["fathers-email"],
                                                                                    ),
                                                                                array(	"type"	=>	"motherfirstname",
                                                                                        "value"	=>	$data_object->ticket_meta["mothers-first-name"],
                                                                                    ),
                                                                                array(	"type"	=>	"motherlastname",
                                                                                        "value"	=>	$data_object->ticket_meta["mothers-last-name"],
                                                                                    ),
                                                                                array(	"type"	=>	"fatherfirstname",
                                                                                        "value"	=>	$data_object->ticket_meta["fathers-first-name"],
                                                                                    ),
                                                                                array(	"type"	=>	"fatherlastname",
                                                                                        "value"	=>	$data_object->ticket_meta["fathers-last-name"],
                                                                                    ),
                                                                                array(	"type"	=>	"mothermobile",
                                                                                        "value"	=>	$data_object->ticket_meta["mothers-contact-number"],
                                                                                    ),
                                                                                array(	"type"	=>	"fathermobile",
                                                                                        "value"	=>	$data_object->ticket_meta["fathers-contact-number"],
                                                                                    ),
                                                                                array(	"type"	=>	"allergiesillnesses",
                                                                                        "value"	=>	$data_object->ticket_meta["allergies-illnesses"],
                                                                                    ),
                                                                                array(	"type"	=>	"birthplace",
                                                                                        "value"	=>	$data_object->ticket_meta["birthplace"],
                                                                                    ),
                                                                                array(	"type"	=>	"nationality",
                                                                                        "value"	=>	$data_object->ticket_meta["nationality"],
                                                                                    ),
                                                                                array(	"type"	=>	"languages",
                                                                                        "value"	=>	$data_object->ticket_meta["languages-spoken"],
                                                                                    ),
                                                                                array(	"type"	=>	"dob",
                                                                                        "value"	=>	$data_object->ticket_meta["date-of-birth"],
                                                                                    ),
                                                                                array(	"type"	=>	"pin",
                                                                                        "value"	=>	$data_object->ticket_meta["pin-code"],
                                                                                    ),           
                                                                            )
                                                )
                                        )
        );

        // now to uuser  with form and agent fields
        $ret = $MoodleRest->request('core_user_create_users', $users, MoodleRest::METHOD_POST);

        // let us check to make sure that the user is created
        if ($ret[0]['username'] == $moodle_username && empty($ret["exception"]))
        {
            // Blank any pre-existing error message since we are successful
            if ( !empty($data_object->ticket_meta["error"]) )
            {
                $wpscfunction->change_field($data_object->ticket_id, 'error', "");
            }
            // the returned user has same name as one given to create new user so new user creation was successful
            return $ret[0]['id'];
        }
        else
        {
            $this->verbose ? error_log("Create new user did NOT return expected username: " . $moodle_username) : false;
            $this->verbose ? error_log(print_r($ret, true)) : false;

            // change the ticket status to error
            $this->change_status_error_creating_sritoni_account($data_object->ticket_id, $ret["message"]);

            return null;
        }

    }


    /**
     * The existing user's SriToni account details are updated.
     *  Check that the  headstart-email is already validated to contain headstart.edu.in before coming here
     */
    private function update_sritoni_account()
    {
        // before coming here the create account object should be already created. We jsut use it here.
        $data_object = $this->data_object;

        // run this again since we may be changing API keys. Once in production remove this
        // $this->get_config();

            // read in the Moodle API config array
        $config			= $this->config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        // Existing user, the username needs to be extracted from the headstart-email
        $moodle_email       = $this->data_object->ticket_meta['headstart-email'];

        // get the  WP user object from the hset-payments site using woocommerce API, set error ststus if not successfull
        $wp_user_hset_payments = $this->get_wp_user_hset_payments($moodle_email, $data_object->ticket_id);

        // get the moodle_id which is same as wpuser's login at the hset-payments site
        $moodle_id = $wp_user_hset_payments->username;

            // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.

        
        // We have a valid Moodle user id, form the array to updatethis user
        $users = array("users" => array(
                                        array(	"id" 	        => $moodle_id,
                                        //      "idnumber"      => $data_object->ticket_meta["idnumber"],
                                        //      "auth"          => "oauth2",
                                                "firstname"     => $data_object->ticket_meta["student-first-name"],
                                                "lastname"      => $data_object->ticket_meta["student-last-name"],
                                        //      "email"         => $moodle_email,
                                                "middlename"    => $data_object->ticket_meta["student-middle-name"],
                                        //      "institution"   => $data_object->ticket_meta["institution"],
                                        //      "department"    => $data_object->ticket_meta["department"],
                                                "phone1"        => $data_object->ticket_meta["emergency-contact-number"],
                                                "phone2"        => $data_object->ticket_meta["emergency-alternate-contact"],
                                                "address"       => $data_object->ticket_meta["residential-address"],
                                         //     "maildisplay"   => 0,
                                         //     "createpassword"=> 0,
                                                "city"          => $data_object->ticket_meta["city"],

                                                "customfields" 	=> array(
                                                                            
                                                                            array(	"type"	=>	"bloodgroup",
                                                                                    "value"	=>	$data_object->ticket_meta["blood-group"],
                                                                                ),
                                                                            array(	"type"	=>	"motheremail",
                                                                                    "value"	=>	$data_object->ticket_meta["mothers-email"],
                                                                                ),
                                                                            array(	"type"	=>	"fatheremail",
                                                                                    "value"	=>	$data_object->ticket_meta["fathers-email"],
                                                                                ),
                                                                            array(	"type"	=>	"motherfirstname",
                                                                                    "value"	=>	$data_object->ticket_meta["mothers-first-name"],
                                                                                ),
                                                                            array(	"type"	=>	"motherlastname",
                                                                                    "value"	=>	$data_object->ticket_meta["mothers-last-name"],
                                                                                ),
                                                                            array(	"type"	=>	"fatherfirstname",
                                                                                    "value"	=>	$data_object->ticket_meta["fathers-first-name"],
                                                                                ),
                                                                            array(	"type"	=>	"fatherlastname",
                                                                                    "value"	=>	$data_object->ticket_meta["fathers-last-name"],
                                                                                ),
                                                                            array(	"type"	=>	"mothermobile",
                                                                                    "value"	=>	$data_object->ticket_meta["mothers-contact-number"],
                                                                                ),
                                                                            array(	"type"	=>	"fathermobile",
                                                                                    "value"	=>	$data_object->ticket_meta["fathers-contact-number"],
                                                                                ),
                                                                            array(	"type"	=>	"allergiesillnesses",
                                                                                    "value"	=>	$data_object->ticket_meta["allergies-illnesses"],
                                                                                ),
                                                                            array(	"type"	=>	"birthplace",
                                                                                    "value"	=>	$data_object->ticket_meta["birthplace"],
                                                                                ),
                                                                            array(	"type"	=>	"nationality",
                                                                                    "value"	=>	$data_object->ticket_meta["nationality"],
                                                                                ),
                                                                            array(	"type"	=>	"languages",
                                                                                    "value"	=>	$data_object->ticket_meta["languages-spoken"],
                                                                                ),
                                                                            array(	"type"	=>	"dob",
                                                                                    "value"	=>	$data_object->ticket_meta["date-of-birth"],
                                                                                ),
                                                                            array(	"type"	=>	"pin",
                                                                                    "value"	=>	$data_object->ticket_meta["pin-code"],
                                                                                ),           
                                                                        )
                                            )
                                    )
                            );
        $ret = $MoodleRest->request('core_user_update_users', $users, MoodleRest::METHOD_POST);

        if ($ret["exception"])
        {
            $this->verbose ? error_log(print_r($ret, true)) : false;
        }
        $this->verbose ? error_log(print_r($ret, true)) : false;
    }

    /**
     *  You must have the data pbject ready before coming here
     *  The user could be a new user or a continuing user
     */
    private function add_user_to_cohort()
    {   // adds user to cohort based on settings cohortid array indexed by ticket category
        // before coming here the create account object should be already created. We jsut use it here.
        $data_object = $this->data_object;

        // run this again since we may be changing API keys. Once in production remove this
        // $this->get_config();

        // read in the Moodle API config array
        $config			= $this->config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        
        if (stripos($this->data_object->ticket_meta['headstart-email'], '@headstart.edu.in') !==false)
        {
            // Continuing user, the username needs to be extracted from the headstart-email
            $moodle_email       = $this->data_object->ticket_meta['headstart-email'];

            // get 1st part of the email as the username
            $moodle_username    = explode( "@headstart.edu.in", $moodle_email, 2 )[0];
        }
        else
        {
            // New account just created so get info from ticket fields
            $moodle_username    = $data_object->ticket_meta["username"];
            $moodle_email       = $moodle_username . "@headstart.edu.in";
        }
        

        $category_id = $data_object->ticket_meta['ticket_category'];

        // from category ID get the category name
        $category_slug = get_term_by('id', $category_id, 'wpsc_categories')->slug;

        // get the cohortid for this ticket based on category-cohortid mapping from settings
        $cohortidnumber = $this->category_cohortid_arr[$category_slug];

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
        error_log(print_r($cohort_ret, true));
    }

    /**
     *
     */
    public function add_my_menu()
    {
        add_submenu_page(
            'users.php',	                    // string $parent_slug
            'Admissions',	                    // string $page_title
            'Admissions',                       // string $menu_title
            'manage_options',                   // string $capability
            'admissions',                       // string $menu_slug
            [$this, 'render_admissions_page'] );// callable $function = ''

        // add submenu page for testing various application API needed for SriToni operation
        add_submenu_page(
            'users.php',	                     // parent slug
            'SriToni Tools',                     // page title
            'SriToni Tools',	                 // menu title
            'manage_options',	                 // capability
            'sritoni-tools',	                 // menu slug
            [$this, 'sritoni_tools_render']);    // callback
    }

    /**
     *
     */
    public function render_admissions_page()
    {
        echo "This is the admissions page where stuff about admissions is displayed";
    }

    public function sritoni_tools_render()
    {
        // this is for rendering the API test onto the sritoni_tools page
        ?>
            <h1> Input appropriate ID (moodle/customer/Ticket/Order) and Click on desired button to test</h1>
            <form action="" method="post" id="mytoolsform">
                <input type="text"   id ="id" name="id"/>
                <input type="submit" name="button" 	value="test_sritoni_connection"/>
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

        
        
        switch ($_POST['button'])
        {
            case 'test_sritoni_connection':
                $id = sanitize_text_field( $_POST['id'] );
                $this->test_sritoni_connection($id);
                break;

            case 'test_cashfree_connection':
                $id = sanitize_text_field( $_POST['id'] );
                $this->test_cashfree_connection($id);
                break;

            case 'test_woocommerce_customer':
                $id = sanitize_text_field( $_POST['id'] );
                $this->test_woocommerce_customer($id);
                break;

            case 'test_get_ticket_data':
                $id = sanitize_text_field( $_POST['id'] );
                $this->test_get_ticket_data($id);
                break;

            case 'test_get_wc_order':
                $order_id = sanitize_text_field( $_POST['id'] );
                $this->get_wc_order($order_id);
                break;

            case 'trigger_payment_order_for_error_tickets':
                $this->trigger_payment_order_for_error_tickets();
                break;

            case 'trigger_sritoni_account_creation_for_error_tickets':
                $this->trigger_sritoni_account_creation_for_error_tickets();
                break;

            case 'test_get_data_object_from_ticket':
                $ticket_id = sanitize_text_field( $_POST['id'] );
                $this->test_get_data_object_from_ticket($ticket_id);
                break;

            case 'test_custom_code':
                $id = sanitize_text_field( $_POST['id'] );
                $this->test_custom_code($id);
                break;

            default:
                // do nothing
                break;
        }
        
    }

    /**
     * 
     */
    public function action_on_reply_submission( array $args, int $thread_id ): void
    {
        $reply_text = $args['reply_body'];

        $utr = $this->extract_utr( $reply_text );

        if ( $utr )
        {
            $ticket_id  = $args['ticket_id'];

            $this->update_field_bank_reference( $ticket_id, $utr );
        }
    }

    /**
     *  @param integer:$ticket_id
     *  @param string:$utr
     *  @return void
     */
    public function update_field_bank_reference(int $ticket_id, string $utr):void
    {
        global $wpscfunction;

        $wpscfunction->change_field($ticket_id, 'payment-bank-reference', $utr);
    }

    /**
     *  @param string:$reply
     *  @return string:$utr can also return null and therefore the ? in the decalaration of function
     *  The given string is searched for space, :, -. and _ and EOL. The characters found are replaced with a space
     *  Next the string is broken up into an an array of sub strings when separated by a space
     *  Each of the sub-strings are searched to see if 12, 16, or 22 characters long. If so, the sub-string is returned as UTR
     */
    public function extract_utr(string $reply): ?string
    {   // sub-strings are searched to see if 12, 16, or 22 characters long. If so, the sub-string is returned as UTR
        $utr = null;    // initialize. 

        // array of possible word separators to look for in reply message text
        //$search = array(" ", ":", "-", "_", ",", ")", "(", ";", ".", PHP_EOL);

        // replace string space
        $replace = " ";

        // replace the desirable word separators if found with a space
        //$modified_reply = str_replace($search, $replace, $reply);

        // form an array of words using the space as a separator
        //$words_arr      = explode($replace, $modified_reply);
        $words_arr = explode(" ", preg_replace("/[\W_]+/", $replace, $reply));

        // check each word for length: IMPS has 12, RTGS 16 and NEFT 22. Also word should have at least 1 digit
        foreach ($words_arr as $word)
        {
            $word_length = iconv_strlen($word);
            if ( ( $word_length === 12 || $word_length === 16 || $word_length === 22 ) 
                 && preg_match('/^(?=.*[\d]).+$/', $word) === 1 )
            {
                $utr = $word;
                
                return $utr;
            }
        }

        // return a null value for utr since no value was found
        return $utr;
    }


    /**
     * This function is called by a wp-cron outside this class usually.
     * For all tickets of specified status, it checks if any thread contains a possible UTR and updates ticket field
     * @return void
     */
    public function check_if_payment_utr_input()
    {   // For all tickets of specified status, it checks if any thread contains a possible UTR and updates ticket field
        // get all tickets that have payment status as shown. 

        $tickets = $this->get_all_active_tickets_by_status_slug('admission-payment-order-being-created');

        foreach ($tickets as $ticket)
        {
            // initialize $utr for each ticket so as not to carry over from previous tickets
            $utr = null;

            // get the  ticket history of this ticket
            $threads = $this->get_ticket_threads($ticket->id);

            // process all threads of this ticket
            foreach ($threads as $thread)
            {
                $thread_content = strip_tags($thread->post_content);
                if ($thread_content)
                {
                    // null value returned if utr doesnt exist in this thread
                    $utr_thisthread = $this->extract_utr($thread_content);
                }

                if ($utr_thisthread)
                {
                    $utr = $utr_thisthread;
                }
                // check next thread and update utr if present
            }
            
            // if $utr is not null from above processing of threads update this ticket provided existing value for field is empty
            if ( $utr )
            {   // we have a valid utr in ticket reply, so lets update the field payment-bank-reference only if not empty
                $this->get_data_object_from_ticket($ticket->id);

                // get existing value of bank-reference from ticket using data pbject
                $payment_bank_reference = $this->data_object->ticket_meta['payment-bank-reference'];

                if ( empty($payment_bank_reference) )
                {
                    $this->update_field_bank_reference($ticket->id, $utr);
                }
            }
        }

        
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

            $this->create_update_user_account($ticket_id);
            
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

    public function test_woocommerce_customer($customer_id)
    {
        $wpuserobj = $this->get_wp_user_hset_payments("sritoni2@headstart.edu.in", $customer_id);

        echo "<pre>" . print_r($wpuserobj, true) ."</pre>";
    }



    public function test_custom_code($ticket_id)
    {
        global $wpscfunction;

        echo "<h1>" . "List of ALL Ticket custom fields" . "</h1>";

        $custom_fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'       => 'agentonly',
                    'value'     => ['0', '1'],
                    'compare'   => 'IN'
                ),
            )
        ]);
        echo "<pre>" . print_r($custom_fields, true) ."</pre>";

        $category_ids = array();
        $categories = get_terms([
        'taxonomy'   => 'wpsc_categories',
        'hide_empty' => false,
        'orderby'    => 'meta_value_num',
        'order'    	 => 'ASC',
        'meta_query' => array('order_clause' => array('key' => 'wpsc_category_load_order')),
        ]);
        echo "<pre>" . print_r($categories, true) ."</pre>";

        $term = get_term_by('slug','error-creating-payment-shop-order','wpsc_statuses');
        echo "<pre>" . print_r($term, true) ."</pre>";

        $this->get_data_object_from_ticket($ticket_id);

        // get the category id from the ticket
        $ticket_category_id = $this->data_object->ticket_meta['ticket_category'];
        $fullname           = $this->data_object->ticket_meta['student-first-name']  . " " . 
                                $this->data_object->ticket_meta['student-middle-name'] . " " .
                                $this->data_object->ticket_meta['student-last-name'];
        // get the ticket category name from ID
        $term_category              = get_term_by('id', $ticket_category_id, 'wpsc_categories');

        $admission_fee_payable      = $this->category_fee_arr[$term_category->slug];

        $product_customized_name    = $this->category_paymentdescription_arr[$term_category->slug] . " " . $fullname;

        $cohort = $this->category_cohortid_arr[$term_category->slug];

        // update the agent fields for fee and fee description
        //$wpscfunction->change_field($ticket_id, 'admission-fee-payable', $admission_fee_payable);

        //$wpscfunction->change_field($ticket_id, 'product-customized-name', $product_customized_name);
        
        echo "<pre>" . "Ticket category id: " . $ticket_category_id ."</pre>";
        echo "<pre>" . "Ticket category slug: " . $term_category->slug ."</pre>";
        echo "<pre>" . "fee: " . $admission_fee_payable ."</pre>";
        echo "<pre>" . "description: " . $product_customized_name ."</pre>";
        echo "<pre>" . "Cohort: " . $cohort ."</pre>";
        echo nl2br("/n");
        echo "<h1>" . "List of ALL Tickets having status: admission-payment-process-completed" . "</h1>";
        /*
        // get all tickets that have payment status as shown. 
        $tickets = $this->get_all_active_tickets_by_status_slug('admission-payment-process-completed');
        foreach ($tickets as $ticket):
            echo nl2br("Ticket id: " . $ticket->id . " \n");
        endforeach;
        */
        // get the the  ticket history of a given ticket
        $threads = $this->get_ticket_threads($ticket_id);

        // process all threads of this ticket
        foreach ($threads as $thread)
        {
            $thread_content = strip_tags($thread->post_content);
            if ($thread_content)
            {
                // null value returned if utr doesnt exist in this thread
                $utr_thisthread = $this->extract_utr($thread_content);
            }

            if ($utr_thisthread)
            {
                $utr = $utr_thisthread;
            }
            // check next thread and update utr if present
        }
        echo "<pre>" . "UTR of ticket ID: " . $ticket_id . " is:" . $utr. "</pre>";
        echo "<pre>" . print_r($threads, true) ."</pre>";

    }

    public function test_sritoni_connection($moodle_id)
    {
        $this->get_config();
        // read in the Moodle API config array
        $config			= $this->config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.
        // $MoodleRest->setDebug();
        // get moodle user details associated with this completed order from SriToni
        $parameters   = array("criteria" => array(array("key" => "id", "value" => $moodle_id)));

        // get moodle user satisfying above criteria
        $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);
        if ( !( $moodle_users["users"][0] ) )
        {
            // failed to communicate effectively to moodle server so exit
            echo nl2br("couldn't communicate to moodle server. \n");
            return;
        }
        echo "<h3>Connection to moodle server was successfull: Here are the details of Moodle user object for id:" . $moodle_id ."</h3>";
        $moodle_user   = $moodle_users["users"][0];
	    echo "<pre>" . print_r($moodle_user, true) ."</pre>";
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
    private function change_status_error_creating_payment_shop_order($ticket_id, $error_message)
    {
        global $wpscfunction;

        $status_id = 94;    // corresponds to status error creating payment order

        $wpscfunction->change_status($ticket_id, $status_id);

				$ticket_slug = "error";

        // update agent field error message with the passed in error message
        if (!empty($error_message))
        {
            $wpscfunction->change_field($ticket_id, $ticket_slug, $error_message);
        }
    }

    /**
     *
     */
    private function change_status_error_creating_sritoni_account($ticket_id, $error_message)
    {
        global $wpscfunction;

        $status_id = 95;    // corresponds to status error creating sritoni account

        $wpscfunction->change_status($ticket_id, $status_id);

				$ticket_slug = "error";

        // update agent field error message with the passed in error message
        if (!empty($error_message))
        {
            $wpscfunction->change_field($ticket_id, $ticket_slug, $error_message);
        }

    }

    /**
     *
     */
    private function get_status_id_by_slug($slug)
    {
        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',

            'meta_query' => array(
                                    array(
                                        'key'       => 'agentonly',
                                        'value'     => ["0", "1"],  // get all ticket meta fields
                                        'compare'   => 'IN',
                                        ),
                                ),

        ]);
        foreach ($fields as $field)
        {
            if ($field->slug == $slug)
            {
                return $field->term_id;
            }
        }
    }

    /**
     *
     */
    private function get_ticket_meta_key_by_slug($slug)
    {
        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',

            'meta_query' => array(
                                    array(
                                        'key'       => 'agentonly',
                                        'value'     => [0, 1],  // get all ticket meta fields
                                        'compare'   => 'IN',
                                        ),
                                ),

        ]);
        foreach ($fields as $field)
        {
            if ($field->slug == $slug)
            {
                return $field->term_id;
            }
        }
    }

    public function test_get_ticket_data($ticket_id)
    {
        global $wpscfunction;

        // $ticket_id =8;

        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',

            'meta_query' => array(
                                    array(
                                        'key'       => 'agentonly',
                                        'value'     => ["0", "1"],  // get all ticket meta fields
                                        'compare'   => 'IN',
                                        ),
                                ),

        ]);

        echo "<pre>" . print_r($fields, true) . "for Ticket:" . $ticket_id. "</pre>";

        $status_id      = get_term_by('slug','admission-payment-order-being-created','wpsc_statuses')->term_id;
        echo "Status id and name corresponding to Status slug - admission-payment-order-being-created: " . $status_id . ":" . $wpscfunction->get_status_name($status_id);

    }

    /**
     *  @param string $status_slug is the slug of desired status that all tickets are filtered by
     *  @return array $tickets
     */
    public function get_all_active_tickets_by_status_slug($status_slug)
    {
        // get a list of all tickets having desired status
        global $wpscfunction, $wpdb;

        $term = get_term_by('slug', $status_slug, 'wpsc_statuses');

        // get all tickets with this status_id and active
        $meta_query[] = array(
            'key'     => 'ticket_status',
            'value'   => $term->term_id,
            'compare' => '='
        );
        
        $meta_query[] = array(
            'key'     => 'active',
            'value'   => 1,
            'compare' => '='
        );
        
        $select_str   = 'SQL_CALC_FOUND_ROWS DISTINCT t.*';
        $sql          = $wpscfunction->get_sql_query( $select_str, $meta_query);
        $tickets      = $wpdb->get_results($sql);

        return $tickets;
    }



}   // end of class bracket
