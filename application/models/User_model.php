<?php

if (!defined('BASEPATH')) exit('No direct script access allowed');

class User_model extends CI_Model {

    /**
     * variabel library 
     * @var menyimpan pesan status
     * @var menyimpan pesan error 
     * @var menyimpan konfigurasi dari library
     */
    private $status;
    private $errors;
    private $settings;

    public function __construct() {
        parent::__construct();

        // membuka database driver
        $this->load->database();
        // membuka session library
        $this->load->library('session');
        // membuka helper
        $this->load->helper('language');

        // inisialisasi variabel
        $this->status = array();
        $this->errors = array();

        // membuka language file
        $this->lang->load('auth');

        // konfigurasi default
        $this->settings = array(
            //Website Title. This is used when sending emails            
            "website_title" => 'E-Kiosk Perpustakaan',
            //Automatically sign user in after registration
            "auto_sign_in" => FALSE,
            //send verification email upon registration
            "verify_email" => FALSE,
            //Remember me cookies names
            "remember_me_cookie_name" => 'hhp_remember_me_tk',
            //Remember me cookie expiration time in SECONDS
            "remember_me_cookie_expiration" => 60 * 60 * 24 * 30, #Default 30 days
            //ip login attempts limit
            "ip_login_limit" => 5,
            //Identity login attempts limit
            "identity_login_limit" => 5,
            //Ban time in SECONDS if login attempts excedded.
            "ban_time" => 10, #Default = 10 Sec
            //When should the password reset email expire? in seconds
            "password_reset_date_limit" => 60 * 3, #Default = 2 hours
            //This is the email used to send emails through the website
            "webmaster_email" => 'no_reply@eperpus.yu.dev',
            //Email templates. NOTE: templates must be in the application/views folder
            //and must end with .php extension
            "email_templates" => array(
                //Template for activating account
                "activation" => "Emails/email_verification",
                //Template for a forgotten password retrieval
                "password_reset" => "Emails/password_reset",
            ),
            //Email type. Either text or html
            "email_type" => 'html',
            //Links to be included in the emails
            "links" => array(
                //controler/method
                //The method should accept paramaters
                //Do not change the vars in the links below unless you know 
                //what you are doing.
                'activation' => 'users/activate', //1st param user_id. 2nd param token
                'password_reset' => 'users/reset_password' //1st param user_id. 2nd param token
            )
        );
    }

    /**
     * Create new user account
     *
     * $custom_data example
     *
     * $custom_data = array("column" => "value", "column2" => "value2");
     *
     * To activate a user immedietly add this to your custom data array
     *
     * $custom_data = array("is_active" => 1);
     * @param string $email
     * @param string $username
     * @param string $password
     * @param int $group_id
     * @param mixed $custom_data
     * @return mixed user_id on success
     */
    public function create_account($email, $username, $password, $role_id = NULL, $custom_data = NULL) {
        //Load PHPass library to encrypt password
        $this->load->library('phpass');

        //Encrypt pass
        $encrypted_pass = $this->phpass->hash($password);

        //Insert data and return;
        $this->db->set('user_email', $email);
        $this->db->set('user_login', $username);
        $this->db->set('user_pass', $encrypted_pass);
        
        //Add group id
        if (!empty($role_id) && $role_id > 0) {
            $this->db->set('role_id', $role_id);
        }
        
        //Add custom data
        if (!empty($custom_data) && is_array($custom_data)) {
            foreach ($custom_data as $key => $value) {
                //The key would be the column name
                //The value should be the value to add
                $this->db->set($key, $value);
            }
        }

        //Run query
        $this->db->insert('tb_user');

        //Insert ID || user id
        $user_id = $this->db->insert_id();

        //Send verification email if config is set to true
        if ($this->settings['verify_email']) {
            $this->send_verification_email($user_id);
        }

        //Return user id
        return $user_id;
    }

    /**
     * Activate user account
     * @param int $user_id
     * @return int number of affected rows
     */
    public function activate($user_id) {
        //Validate user id
        if (!$user_id || $user_id < 1) {
            return FALSE;
        }
        //Activate user
        $this->db->set('user_status', 1);
        $this->db->where('user_id', $user_id);
        $this->db->update('tb_user');
        //return 1 or 0
        return $this->db->affected_rows();
    }

    /**
     * Deactivate user account
     * @param int $user_id
     * @return int affected rows
     */
    public function deactivate_account($user_id) {

        $this->db->set('user_status', 9);
        $this->db->where('user_id', $user_id);
        $this->db->update('tb_user');
        return $this->db->affected_rows();
    }

    /**
     * Edit user custom data
     * $data example:
     * $data = array('email' => 'value@example.com', 'column' => 'value');
     *
     * @param int $user_id
     * @param array $data
     */
    public function edit_user($user_id, $data = array()) {

        if (!$user_id || !is_array($data)) {
            //Error: Invalid arguments
            $this->set_error_message('auth.invalid_args', 'Auth::edit_user');
        }

        $this->db->where($user_id);
    }

    /**
     * Perform login operation
     * @param string $email
     * @param string $password
     * @param bool $remember_me
     * @return boolean
     */
    public function login($username, $password, $remember_me = FALSE) {

        //Check database records to match identity
        $this->db->where('user_login', $username);
        $this->db->limit(1);
        $query = $this->db->get('tb_user');

        //If username found in db, check password
        if ($query->num_rows() < 1) {
            //Set error message and return false
            $this->set_error_message('auth.invalid_credentials');
            return FALSE;
        }

        //fetch user account row
        $row = $query->row();

        //Check if user has a ban
        $now = new DateTime('now');
        $login_ban = new DateTime($row->user_loginban);
        if ($login_ban >= $now) {
            $this->set_error_message('auth.user_banned');
            return FALSE;
        }

        //Check if the number of failed logins exceeds the limit
        if ((int) $row->user_loginattempts >= $this->settings['identity_login_limit']) {
            //Add ban to user identity
            $now->modify("+{$this->settings['ban_time']} SECONDS");
            $login_ban_until = $now->format('Y-m-d H:i:s');
            $this->db->set('user_loginban', $login_ban_until);
            $this->db->where('user_id', $row->user_id);
            $this->db->update('tb_user');

            //Reset faild login attempts
            $this->_reset_attempts($row->user_id);
            $this->set_error_message('auth.user_banned');
            return FALSE;
        }

        //Check ip ban
        if ($this->ip_ban()) {
            //Set error message and return false
            $this->set_error_message('auth.user_banned');
            return FALSE;
        }

        //Load encryption library phpass
        $this->load->library('phpass');

        //Check password
        if (!$this->phpass->check($password, $row->user_pass)) {
            //Password not found
            $this->set_error_message('auth.invalid_credentials');

            //Increment failed login attempt
            $this->_increment_login_attempts($row->user_id, $row->user_loginattempts);
            return FALSE;
        }

        //User has valid credentials
        if ($row->user_loginattempts > 0) {
            //Reset faild login attempts
            $this->_reset_attempts($row->user_id);
        }

        //Set session
        $this->_set_session($row, TRUE);

        //Set remember me cookie if set to true
        if ($remember_me) {
            $this->set_remember_me_cookie($row->user_id);
        }

        // update user status
        $this->db->set('user_status', 3);
        $this->db->where('user_id', $row->user_id);
        $this->db->update('tb_user');

        return TRUE;
    }

    /**
     * check if user has ban by ip address
     * @return boolean
     */
    private function ip_ban() {
        //Check database records to match IP address
        //We only need the ip that has a ban on it
        $this->db->where('ip', $this->input->ip_address());
        $this->db->where('number_of_attempts >=', (int) $this->settings['ip_login_limit']);
        $this->db->where("DATE_ADD(last_failed_attempt, INTERVAL {$this->settings['ban_time']} SECOND) <= ", 'NOW()', FALSE);
        $ip_query = $this->db->get('tb_ip_attempts');
        //If record exists return true
        if ($ip_query->num_rows() > 0) {
            return TRUE;
        }

        return FALSE;
    }

    /**
     *
     * @param object $user
     * @param bool $via_password
     * @return boolean
     */
    private function _set_session($user, $via_password = FALSE) {

        if (!$user->user_id) {
            $this->set_error_message('');
            return FALSE;
        }

        if ($user->role_id) {
            $query = $this->db->select("role_admin, role_nama")
                    ->where('role_id', $user->role_id)
                    ->get('tb_role');
        }

        $role = $query->row();

        $sess_data = array(
            "is_logged_in" => TRUE,
            "is_admin" => (bool) $role->role_admin,
            "user_role" => (string) $role->role_nama,
            "role_id" => (int) $user->role_id,
            "via_password" => (bool) $via_password,
            "user_id" => (int) $user->user_id,
            "user_login" => (string) $user->user_login,
            "user_email" => (string) $user->user_email
        );

        $this->session->set_userdata($sess_data);
    }

    /**
     * Check if user is logged in. The function will check if the user is remembered
     * only if the user is not logged in.
     *
     * @return bool
     */
    public function is_logged_in() {
        //Check if session is available
        if ($this->session->userdata('is_logged_in'))
            return TRUE;

        //Check if user is remembered
        if ($this->is_remembered())
            return TRUE;

        //User has not logged in
        return FALSE;
    }

    /**
     * Checks for remember me cookie and match against database
     * @return boolean
     */
    public function is_remembered() {
        //Get cookies
        $cookie = $this->fetch_remember_me_cookie();

        if (!$cookie) {
            return FALSE;
        }

        $user_id = $cookie['user_id'];
        $token = $cookie['token'];

        //Match token against database
        //Load token from database
        $where = array('user_id' => $user_id);
        $this->db->where($where);
        $query = $this->db->get('tb_remembered_users');
        //Check to see if user_id->token association exists
        if ($query->num_rows() < 1) {
            return FALSE;
        }

        //Encrypted token match control
        $token_found = FALSE;

        $this->load->library('phpass');

        //Check if the user_id->token match our encrypted records in the database
        foreach ($query->result() as $row) {
            if ($this->phpass->check($token, $row->token)) {
                //Token found
                $token_found = TRUE;
                break;
            }
        }

        //Token has not been matched
        if (!$token_found) {
            //Remove the invalid cookie
            $this->unset_remember_me_cookie($user_id);
            return FALSE;
        }

        //Get user account data
        $user = $this->db->get_where('tb_user', $where)->row();

        //Set login session
        //User has not logged in via password
        $this->_set_session($user, FALSE);

        //Regenerate remember me token
        //Delete old cookie
        $this->unset_remember_me_cookie($user->user_id);
        //Generate new cookie. The new cookie will have the
        //life of the settings' expiration date
        $this->set_remember_me_cookie($user->user_id);
        return TRUE;
    }

    /**
     * Check if user has logged in via password
     * @return mixed
     */
    public function is_logged_in_via_password() {
        return $this->session->userdata('via_password');
    }

    /**
     * Returns the user id of the logged in user
     * @return False if user is not logged in | (int) ID otherwise
     */
    public function user_id() {
        return $this->session->userdata('user_id');
    }

    /**
     * Returns the role id of the logged in user
     * @return False if user is not logged in | (int) ID otherwise
     */
    public function get_roleid() {
        return $this->session->userdata('role_id');
    }

    /**
     * Returns the user role of the logged in user
     * @return False if user is not logged in | (string) role otherwise
     */
    public function get_role() {
        return $this->session->userdata('user_role');
    }

    /**
     * Returns the username of the logged in user
     * @return False if user is not logged in | (int) ID otherwise
     */
    public function get_username() {
        return $this->session->userdata('user_login');
    }

    /**
     * Returns the user email of the logged in user
     * @return False if user is not logged in | (int) ID otherwise
     */
    public function get_email() {
        return $this->session->userdata('user_email');
    }

    /**
     * check if user is logged in and is admin
     * @return bool
     */
    public function is_admin() {
        return $this->session->userdata('is_admin');
    }

    /**
     * Get all session data
     * @return mixed
     */
    public function session_data() {
        return $this->session->all_userdata();
    }

    /**
     * Perform logout operation
     * @return void
     */
    public function logout() {
        // update user status
        $this->db->set('user_status', 1);
        $this->db->where('user_id', $this->user_id());
        $this->db->update('tb_user');
        // unset remember cookie
        $this->unset_remember_me_cookie($this->user_id());
        // hapus session
        $sess_data = array(
            "is_logged_in","is_admin","user_role","role_id","via_password","user_id",
            "user_login","user_email"
        );
        // $this->session->sess_destroy(); 
        $this->session->unset_userdata($sess_data);
    }

    /**
     * Get failed login attempts login attempts
     *
     * @param int $user_id
     * @return int
     */
    public function login_attempts($user_id) {
        $this->db->select('user_loginattempts');
        $this->db->where('user_id', $user_id);
        $query = $this->db->get('tb_user');
        //If user exists, return login attempts
        if ($query->num_rows() > 0) {
            return $query->row()->user_loginattempts;
        }

        return 0;
    }

    /**
     * Adds 1 to the failed login attempts
     *
     * @param int $user_id
     * @param int $attempts
     * @return int affected rows
     */
    private function _increment_login_attempts($user_id, $attempts = 0) {
        //Increment identity login attempts
        $this->db->set('user_loginattempts', $attempts + 1);
        $this->db->where('user_id', $user_id);
        $this->db->update('tb_user');
        $update_affected_rows = $this->db->affected_rows();

        //Check if ip exists
        $this->db->select('number_of_attempts');
        $this->db->where('ip', $this->input->ip_address());
        $query = $this->db->get('tb_ip_attempts');
        if ($query->num_rows() > 0) {
            $row = $query->row();
            //Increment ip login attempts
            $this->db->set('number_of_attempts', $row->number_of_attempts + 1);
            $this->db->set('last_failed_attempt', 'NOW()', FALSE);
            $this->db->update('tb_ip_attempts');
        } else {
            //Insert first attempt
            $this->db->set('ip', $this->input->ip_address());
            $this->db->set('number_of_attempts', 1);
            $this->db->set('last_failed_attempt', 'NOW()', FALSE);
            $this->db->insert('tb_ip_attempts');
        }

        return $this->db->affected_rows() + $update_affected_rows;
    }

    /**
     * Reset login attempts
     *
     * @param type $user_id
     * @return (int) affected rows
     */
    private function _reset_attempts($user_id) {
        //reset Identity failed login attempts
        $this->db->set('user_loginattempts', 0);
        $this->db->where('user_id', $user_id);
        $this->db->update('tb_user');
        $u = $this->db->affected_rows();

        //reset ip failed login attempts
        $this->db->set('number_of_attempts', 0);
        $this->db->where('ip', $this->input->ip_address());
        $this->db->update('tb_ip_attempts');

        return $this->db->affected_rows() + $u;
    }

    /**
     * Create remember me cookie
     *
     * @param (int) $user_id
     * @param (int) $expiration
     * @return boolean
     */
    public function set_remember_me_cookie($user_id = NULL, $expiration = NULL) {
        //Check user id availability
        if (empty($user_id)) {
            $user_id = $this->user_id();
            if (!$user_id) {
                $this->set_error_message('auth.user_id_unavailable');
                return FALSE;
            }
        }

        //Validate expiration time value
        if (empty($expiration) || !is_numeric($expiration)) {
            $expiration = $this->settings['remember_me_cookie_expiration'];
        }

        //Generate value token
        $token = $this->generate_remember_me_token($user_id);

        //Set cookie data array
        $cookie = array(
            'name' => $this->settings['remember_me_cookie_name'],
            'value' => $user_id . ':' . $token,
            'expire' => $expiration
        );
        $this->input->set_cookie($cookie);

        return TRUE;
    }

    /**
     * generate remember me token
     * @param int user_id
     */
    private function generate_remember_me_token($user_id) {
        //Load PHPass library to encrypt password
        $this->load->library('phpass');

        //Generate a random token and encrypt it
        $random = $this->_rand_str(32);
        $token = $this->phpass->hash($random);

        //Store tocken in the database
        $this->db->set('token', $token);
        $this->db->set('user_id', $user_id);
        $this->db->set('date', 'NOW()', FALSE);
        $this->db->insert('tb_remembered_users');

        return $random;
    }

    /**
     * Delete remember me cookie
     * @param int $user_id
     * @return void
     */
    public function unset_remember_me_cookie($user_id) {
        $cookie_data = $this->fetch_remember_me_cookie();

        //Set cookie data array
        $cookie = array(
            'name' => $this->settings['remember_me_cookie_name'],
            'value' => '',
            'expire' => 0
        );
        $this->input->set_cookie($cookie);

        //Check cookie data validity
        if (!$cookie_data)
            return;

        //Remove record of token from database
        $this->db->where('user_id', $user_id);
        $query = $this->db->get('tb_remembered_users');
        if ($query->num_rows() > 0) {
            //Load Phpass Library
            $connection_id = FALSE;
            $this->load->library('phpass');
            foreach ($query->result() as $row) {
                if ($this->phpass->check($cookie_data['token'], $row->token)) {
                    $connection_id = $row->connection_id;
                    break;
                }
            }
            if ($connection_id) {
                $this->db->where('connection_id', $connection_id);
                $this->db->delete('tb_remembered_users');
            }
        }

        return;
    }

    /**
     * Gets the data of the remember me cookie
     * @return mixed array ("user_id" => 'id', "token" => 'hashed token') | FALSE if cookie does not exits
     */
    private function fetch_remember_me_cookie() {
        //Get cookies
        $cookie = $this->input->cookie($this->config->item('cookie_prefix') . $this->settings['remember_me_cookie_name'], TRUE);
        if (!$cookie) {
            //Cookie does not exist
            return FALSE;
        }

        //Cookie value must contain the : char and must be larger than 32 chars
        if (strlen($cookie) < 32) {
            //Invalid cookie
            return FALSE;
        }

        $data = explode(':', $cookie);

        if (!is_array($data) && count($data) == 2) {
            return FALSE;
        }

        //Make sure value exist
        if (empty($data[0]) || empty($data[1])) {
            return FALSE;
        }

        return array("user_id" => $data[0], "token" => $data[1]);
    }

    /**
     * Send activtion email
     * @param int $user_id The user id
     * 
     * @return boolean
     * 
     */
    public function send_verification_email($user_id) {
        //Get user from db   
        $this->db->select('user_email');
        $this->db->where('user_id', $user_id);
        $query = $this->db->get('tb_user');
        if ($query->num_rows() != 1) {
            //User does not exist
            return FALSE;
        }

        //Fetch data
        $row = $query->row();

        //Generate token
        $token = $this->_generate_email_verfication_token($user_id);

        //URL Helper
        $this->load->helper('url');

        //create link
        $link = $this->settings['links']['email_verification']
                . "/{$user_id}/{$token}";
        $data['link'] = site_url($link);

        //email configration        
        $this->load->library('email');
        $from = $this->settings['webmaster_email'];
        $website_title = $this->settings['website_title'];
        $to = $row->email;
        $subject = lang('auth.email_verification_email_subject');
        $message = $this->load->view($this->settings['email_templates']['activation'], $data, TRUE);

        //Email settings
        $config['mailtype'] = $this->settings['email_type'];
        $this->email->initialize($config);

        //Set email
        $this->email->from($from, $website_title);
        $this->email->to($to);
        $this->email->subject($subject);
        $this->email->message($message);
        //Send
        $this->email->send();

        return TRUE;
    }

    /**
     * Generate email verification token
     * @param int $user_id The user id
     * @return string
     */
    private function _generate_email_verfication_token($user_id) {
        $token = $this->_rand_str(32);

        $this->load->library('phpass');
        $encrypted_token = $this->phpass->hash($token);
        $this->db->set('user_emailverf', $encrypted_token);
        $this->db->set('user_emailverf_date', 'NOW()');
        $this->db->where('user_id', $user_id);
        $this->db->update('tb_user');

        return $token;
    }

    /**
     * Verify that the user clicked a valid verification email link.     
     * @param int $user_id
     * @param string $token
     * 
     * @return boolean If the user has a valid token and expiration date, TRUE is
     * returned. Otherwise, FALSE.
     */
    public function verify_email_verification_token($user_id, $token) {
        //Get hashed token and token generation date
        $this->db->select('user_emailverf AS token, user_emailverf_date AS date');
        $this->db->where('user_id', $user_id);
        $query = $this->db->get('tb_user');

        if ($query->num_rows() != 1) {
            //Invalid user id
            return FALSE;
        }

        //Fetch user data
        $row = $query->row();

        //Check token expiration date
        $now = new DateTime('now');
        $tk_date = new DateTime($row->date);
        $tk_date->modify("+{$this->settings['password_reset_date_limit']} SECONDS");

        if ($tk_date >= $now) {
            //Token has expired
            return FALSE;
        }

        //Load phpass
        $this->load->library('phpass');
        //Check token
        if (!$this->phpass->check($token, $row->token)) {
            //Invalid token
        }
    }

    /**
     * Reset user password
     * @param type $user_id
     * @param type $new_password
     * 
     * @return boolean
     */
    public function reset_password($user_id, $new_password) {
        //Hash password and store it in DB
        $this->load->library('phpass');
        $encrypted = $this->phpass->hash($new_password);
        $this->db->set('password', $encrypted);
        //Reset token
        $this->db->set('user_resetpass', '');
        $this->db->where('user_id', $user_id);
        $this->db->update('tb_user');

        return $this->db->affected_rows();
    }

    /**
     * Verify that the provided password reset token is correct.
     * Use this function if you are using the send_reset_password_email() fumction
     * before calling the reset_password() function.
     * 
     * @param string $email
     * @param string $token
     * 
     * @return boolean
     */
    public function verify_password_reset_tk($email, $token) {
        $this->db->select('user_resetpass, user_resetpass_date');
        $this->db->where('user_email', $email);
        $query = $this->db->get('tb_user');

        if ($query->num_rows() != 1) {
            //email does not exist
            return FALSE;
        }

        //fetch row
        $row = $query->row();

        //chack if email expired
        if (empty($row->user_resetpass)) {
            //Token does not exist
            return FALSE;
        }

        $now = new DateTime('now');
        $tk_date = new DateTime($row->user_resetpass_date);
        $tk_date->modify("+{$this->settings['password_reset_date_limit']} SECONDS");
        ;

        if ($tk_date >= $now) {
            //Token has expired
            return FALSE;
        }

        $this->load->library('phpass');
        if (!$this->phpass->check($token, $row->user_resetpass)) {
            //Token does not match
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Send reset password email
     * @param string $email
     * @return boolean True if sending was successful | False otherwise
     */
    public function send_reset_password_email($email) {
        $this->load->helper('url');
        $email = trim($email);
        //Check if email exists
        $this->db->select('user_id');
        $this->db->where('user_email', $email);
        $query = $this->db->get('tb_user');

        if ($query->num_rows() != 1) {
            //Email does not exist
            $this->set_error_message('auth.email_unavailable');
            return FALSE;
        }

        //fetch
        $row = $query->row();

        //generate token and hash it
        $token = $this->_rand_str();

        //load phpass
        $this->load->library('phpass');
        $encrypted_token = $this->phpass->hash($token);

        //Store token in DB
        $this->db->set('user_resetpass', $encrypted_token);
        $this->db->set('user_resetpass_date', 'NOW()', FALSE);
        $this->db->where('user_id', $row->user_id);
        $this->db->update('tb_user');

        //Edit email to make it ok to pass through the url
        $email_address = explode('@', $email);
        //Generate link
        $link = $this->settings['links']['password_reset']
                . "/{$row->user_id}/{$token}";

        //email configration        
        $this->load->library('email');
        $from = $this->settings['webmaster_email'];
        $website_title = $this->settings['website_title'];
        $to = $email;
        $subject = lang('auth.password_reset_email_subject');
        $data['link'] = site_url($link);
        $message = $this->load->view($this->settings['email_templates']['password_reset'], $data, TRUE);

        //Email settings
        $config['mailtype'] = $this->settings['email_type'];
        $this->email->initialize($config);

        //Set email
        $this->email->from($from, $website_title);
        $this->email->to($to);
        $this->email->subject($subject);
        $this->email->message($message);
        //Send
        $this->email->send();

        return TRUE;
    }

    /**
     * Check if email exists in database
     * @param string $email
     * @return boolean
     */
    public function email_exists($email) {
        //Check database
        $this->db->select('user_id');
        $this->db->where('user_email', $email);
        $num = $this->db
                ->get('tb_user')
                ->num_rows();
        //if num > 0 then emails exists so return true, false otherwise
        return ($num > 0);
    }

    /**
     * Check if user exists in database
     * @param string $user
     * @return boolean
     */
    public function user_exists($user) {
        //Check database
        $this->db->select('user_id');
        $this->db->where('user_login', $user);
        $num = $this->db
                ->get('tb_user')
                ->num_rows();
        //if num > 0 then users exists so return true, false otherwise
        return ($num > 0);
    }

    /**
     * Make user a member of a group
     *
     * @param type $user_id
     * @param type $group_id
     * @return int affected rows
     */
    public function set_user_group($user_id, $group_id) {
        $this->db->set('role_id', $group_id);
        $this->db->where("user_id", $user_id);
        $this->db->update('tb_user');

        return $this->db->affected_rows();
    }

    /**
     * gak pake ini dulu
     * @param int $user_id
     * @param int $privilege_id
     * @return mixed number of affected rows | FALSE if connection exisits previously
     */
    public function add_privilege_to_user($user_id, $privilege_id) {
        //Make sure user hasn't the privilege already
        $this->db->where('user_id', $user_id);
        $this->db->where('privilege_id', $privilege_id);
        $query = $this->db->get('account_privilege');
        if ($query->num_rows() > 0) {
            $this->set_error_message('auth.privilege_connection_exists');
            return FALSE;
        }

        //Connect new privilege to user
        $this->db->set('user_id', $user_id);
        $this->db->set('privilege_id', $privilege_id);
        $this->db->insert('account_privilege');

        return $this->db->affected_rows();
    }

    /**
     * Create group
     *
     * @param string $name
     * @param string $description
     * @param bool $is_admin
     * @return boolean
     */
    public function create_group($name, $description, $is_admin = FALSE) {

        //Name has to be unique
        $this->db->select('role_id');
        $this->db->where('role_nama', $name);
        $num = $this->db->get('tb_role')->num_rows();
        if ($num > 0) {
            $this->set_error_message('auth.group_name_exists');
            return FALSE;
        }

        $this->db->set('role_nama', $name);
        $this->db->set('role_deskripsi', $description);
        $this->db->set('role_admin', $is_admin);
        $this->db->insert('tb_role');

        return $this->db->insert_id();
    }

    /** 
     * Membuat hak akses
     * @param string $name
     * @param string $description
     * @return int privilege id
     */
    public function create_priviledge($name, $description) {
        //Privilege name must be unique
        $this->db->select('akses_id');
        $this->db->where('akses_nama', $name);
        $num = $this->db->get('tb_hakakses')->num_rows();
        if ($num > 0) {
            $this->set_error_message('auth.privilege_name_exists');
            return FALSE;
        }

        $this->db->set('akses_nama', $name);
        $this->db->set('akses_deskripsi', $description);
        $this->db->insert('tb_hakakses');

        return $this->db->insert_id();
    }

    /**
     * memberikan hak akses ke role/grup
     * parameter $unique untuk mengecek apakah hak akses sudah diberikan atau belum
     *
     * @param int $priviledge_id
     * @param int $group_id
     * @param bool $unique
     * @return boolean
     */
    public function connect_privilede_to_group($priviledge_id, $group_id, $unique = TRUE) {
        $insert = TRUE;
        if ($unique) {
            //Make sure the connection is unique
            $this->db->where('role_id', $group_id);
            $this->db->where('akses_id', $priviledge_id);
            $query = $this->db->get('tb_roleakses');

            $num = $query->num_rows();

            if ($num > 0)
                $insert = FALSE;
        }

        if ($insert) {
            $this->db->set('akses_id', $priviledge_id);
            $this->db->set('role_id', $group_id);
            $this->db->insert('tb_roleakses');

            return $this->db->insert_id();
        }

        $this->set_error_message('auth.priv_group_connection_exists');
        return FALSE;
    }

    /**
     * untuk mengecek apakah user mempunyai hak akses
     * @param int $privilege_id
     * @param int $user_id
     *
     * @return (mixed) False jika hak akses sudah ada | int total baris ketika sukses
     */
    public function has_privilege($privilege_id, $user_id = NULL) {
        $this->db->select('conn_id');
        $this->db->where(array('user_id' => $user_id, 'akses_id' => $privilege_id));
        $num = $this->db->get('tb_userakses')->num_rows();
        if ($num > 0) {
            $this->set_error_message('auth.privilege_connection_exists');
            return FALSE;
        }

        $this->db->set('akses_id', $privilege_id);
        $this->db->set('user_id', $user_id);
        $this->db->insert('tb_userakses');

        return $this->db->affected_rows();
    }

    /**
     * mengecek apakah user ada di role/grup tertentu
     * @param int $group_id Must provide the group id
     * @param int $user_id Leave null if checking current logged in user
     */
    public function in_group($group_id, $user_id = NULL) {
        if (empty($user_id)) {
            $user_id = $this->user_id();
            if (!$user_id) {
                $this->set_error_message('auth.user_id_param');
                return FALSE;
            }
        }

        $this->db->select('role_id');
        $this->db->where('user_id', $user_id);
        $this->db->where('role_id', $group_id);
        $query = $this->db->get('tb_user');

        if ($query->num_rows() < 1)
            return FALSE;
    }

    /**
     * list all groups
     *
     * @return object If no groups found the function will return null.
     * If groups were found the object structure will be as follows:
     *
     * $obj->group_id
     *
     * $obj->name
     *
     * $obj->description
     *
     */
    public function list_groups() {
        $query = $this->db->get('tb_role');

        if ($query->num_rows() > 0)
            return $query->result();

        return NULL;
    }

    /**
     * List all privileges
     *
     * @return object If no privileges found the function will return null.
     * If privileges were found the object structure will be as follows:
     *
     * $obj->privilege_id
     *
     * $obj->name
     *
     * $obj->description
     */
    public function list_privileges() {
        $query = $this->db->get('tb_hakakses');

        if ($query->num_rows() > 0)
            return $query->result();

        return NULL;
    }

    /**
     * list groups and their connected priviliges
     * @return (object) If no connections found the function will return null.
     * If connections were found the object structure will be as follows:
     *
     * $obj->group_id
     *
     * $obj->privilege_id
     *
     * $obj->group_name
     *
     * $obj->privilege_name
     *
     * $obj->group_description
     *
     * $obj->privilege_description
     */
    public function list_groups_privileges() {
        $select = 'groups.group_id AS group_id, privileges.privilege_id AS privilege_id, '
                . 'privileges.name AS privilege_name, '
                . 'groups.name AS group_name, groups.description AS group_description, '
                . 'privileges.description AS privilege_description';

        $this->db->select($select);
        $this->db->join('groups', 'group_privilege.group_id = groups.group_id');
        $this->db->join('privileges', 'group_privilege.privilege_id = privileges.privilege_id');
        $query = $this->db->get('group_privilege');
        if ($query->num_rows() < 1) {
            return NULL;
        }

        return $query->result();
    }

    /**
     * Get the group of a user
     * @param int $user_id The user id
     * 
     * @return object If group found, an object of the following structure is returned.
     * 
     * $obj->group_id
     * 
     * $obj->name
     * 
     * $obj->description
     * 
     * Otherwise, (bool) FALSE is returned
     * 
     */
    public function user_group($user_id) {
        $this->db->select('role_id');
        $this->db->where('user_id', $user_id);
        $query = $this->db->get('tb_user');
        if ($query->num_rows() != 1) {
            //User doesn't exists
            return false;
        }
        $user = $query->row();
        if ($user->role_id < 1) {
            //user is not in a group
            return FALSE;
        }

        $this->db->where('role_id', $user->role_id);
        $group_query = $this->db->get('tb_role');
        if ($group_query->num_rows() != 1) {
            //couldn't find group
            return false;
        }

        return $group_query->row();
    }

    /**
     * List the privileges a user is connected to
     * @param int $user_id
     * 
     * @return object If the user has privileges an object of the following structure is returned.
     * 
     * $obj->privilege_id
     * 
     * $obj->privilage_name
     * 
     * $obj->privilage_description
     * 
     * Otherwise, (bool) FALSE is returned
     */
    public function list_user_privilege($user_id) {
        $select = 'privileges.name AS privilege_name, privileges.description AS privilege_description, '
                . 'privileges.privilege_id AS privilege_id';
        $this->db->select($select);
        $this->db->join('privileges', 'privileges.privilege_id = account_privilege.privilege_id');
        $this->db->where('user_id', $user_id);
        $query = $this->db->get('account_privilege');

        return $query->result();
    }

    /**
     * Get status messages
     * @return string holding the status messages
     *
     * @param string $prefix HTML opening tag
     * @param string $suffix HTML closing tag
     * @return string Status message(s)
     */
    public function status_messages($prefix = "<p>", $suffix = "</p>") {
        $str = '';
        foreach ($this->status as $value) {
            $str .= $prefix;
            $str .= $value;
            $str .= $suffix;
        }

        return $str;
    }

    /**
     * Get error messages
     * @return string holding the error messages
     *
     * @param string $prefix HTML opening tag
     * @param string $suffix HTML closing tag
     * @return string Error message(s)
     */
    public function error_messages($prefix = "<p>", $suffix = "</p>") {
        $str = '';
        foreach ($this->errors as $value) {
            $str .= $prefix;
            $str .= $value;
            $str .= $suffix;
        }

        return $str;
    }

    /**
     * Adds an error message
     *
     * @param string $message Has to be a key in the language file
     * @param mixed $sprintf If the message requires the sprintf function, pass
     *  an array of what parameters to give the sprintf or vsprintd
     * @return void
     */
    public function set_error_message($message, $sprintf = NULL) {
        if (!empty($sprintf)) {
            if (is_array($sprintf)) {
                $this->errors[] = vsprintf(lang($message), $sprintf);
            } else {
                $this->errors[] = sprintf(lang($message), $sprintf);
            }
            return;
        }
        $this->errors[] = lang($message);
        return;
    }

    /**
     * Adds an status message
     * @param string $message Has to be a key in the language file
     * @param mixed $sprintf If the message requires the sprintf function, pass
     *  an array of what parameters to give the sprintf or vsprintd
     * @return void
     */
    public function set_status_message($message, $sprintf = NULL) {
        if (!empty($sprintf)) {
            if (is_array($sprintf)) {
                $this->status[] = vsprintf(lang($message), $sprintf);
            } else {
                $this->status[] = sprintf(lang($message), $sprintf);
            }
            return;
        }
        $this->status[] = lang($message);
        return;
    }

    /**
     * Reset error and status messages
     * @return void
     */
    public function reset_messages() {
        $this->errors = array();
        $this->status = array();
        return;
    }

    /**
     * generates random string of length $length
     * @author haseydesign http://haseydesign.com/
     * @param int $length Length of generated string
     * @return (string)
     */
    private function _rand_str($length = 32) {
        $characters = '23456789BbCcDdFfGgHhJjKkMmNnPpQqRrSsTtVvWwXxYyZz';
        $count = mb_strlen($characters);

        for ($i = 0, $token = ''; $i < $length; $i++) {
            $index = rand(0, $count - 1);
            $token .= mb_substr($characters, $index, 1);
        }
        return $token;
    }

    function list_menu($role) {
            $this->db->select('tb_menu.menu_akses, tb_menu.menu_id');
            $this->db->join('tb_hakakses', 'tb_hakakses.akses_id = tb_roleakses.akses_id');
            $this->db->join('tb_menu', 'tb_menu.menu_akses = tb_hakakses.akses_nama');
            $this->db->where('tb_roleakses.role_id', $role);
            $this->db->where('tb_menu.menu_aktif', 1);
            $this->db->order_by('tb_menu.menu_urutan');
            $result = $this->db->get('tb_roleakses');

            return ( $result->num_rows() > 0 ? $result : NULL );

    }

    function get_menu($role) {
            //$select = 'tb_menu.*, , privileges.description AS privilege_description, '
            //        . 'privileges.privilege_id AS privilege_id';
            $this->db->select('tb_menu.*');
            $this->db->join('tb_hakakses', 'tb_hakakses.akses_id = tb_roleakses.akses_id');
            $this->db->join('tb_menu', 'tb_menu.menu_akses = tb_hakakses.akses_nama');
            $this->db->where('tb_roleakses.role_id', $role);
            $this->db->where('tb_menu.menu_tipe', 0);
            $this->db->where('tb_menu.menu_aktif', 1);
            $this->db->order_by('tb_menu.menu_urutan');
            $result = $this->db->get('tb_roleakses');

    		/*$sql = 'SELECT 49_tc_usermenu.* FROM 49_tc_userakses INNER JOIN 49_tc_usermenu
    				ON (49_tc_userakses.akses_menu = 49_tc_usermenu.akses_menu) WHERE
    				49_tc_userakses.role_id="'.$role.'" AND 49_tc_usermenu.menu_tipe="0" AND 49_tc_usermenu.menu_aktif="1"
    				ORDER BY 49_tc_usermenu.menu_urutan';
    		$result = $this->db->query($sql);*/

    		$menu = '';
    		$menu_child = '';

    		if($result->num_rows()>0){
    			foreach ($result->result() as $parent){
    				$li_parent = '';
                    $menu_child = '';

    				/*$sql = 'SELECT 49_tc_usermenu.* FROM 49_tc_userakses INNER JOIN 49_tc_usermenu
    						ON (49_tc_userakses.akses_menu = 49_tc_usermenu.akses_menu) WHERE
    						49_tc_userakses.role_id="'.$role.'" AND 49_tc_usermenu.menu_tipe="1" AND 49_tc_usermenu.menu_aktif="1" AND 49_tc_usermenu.menu_parent="'.$parent->akses_menu.' "
    						ORDER BY 49_tc_usermenu.menu_urutan';

                    $result_child=$this->db->query($sql);*/

                    $this->db->select('tb_menu.*');
                    $this->db->join('tb_hakakses', 'tb_hakakses.akses_id = tb_roleakses.akses_id');
                    $this->db->join('tb_menu', 'tb_menu.menu_akses = tb_hakakses.akses_nama');
                    $this->db->where('tb_roleakses.role_id', $role);
                    $this->db->where('tb_menu.menu_tipe', 1);
                    $this->db->where('tb_menu.menu_aktif', 1);
                    $this->db->where('tb_menu.menu_parent', $parent->menu_akses);
                    $this->db->order_by('tb_menu.menu_urutan');
                    $result_child = $this->db->get('tb_roleakses');

    				if($result_child->num_rows()>0){
    				    $li_parent = 'class="treeview"';
    					$menu_child = '<i class="fa fa-angle-left pull-right"></i><ul class="treeview-menu">';
    					foreach ($result_child->result() as $child){
                            $urlchild = "dashboard".$child->menu_url;
    						$menu_child = $menu_child.'<li id="child-'.$child->menu_akses.'"><a href="'.site_url($urlchild).'"><i class="'.$child->menu_icon.'"></i> '.$child->menu_nama.'</a></li>';
    					}
    					$menu_child = $menu_child.'</ul>';
    				}

                    $urlparent = "dashboard".$parent->menu_url;
    				$menu = $menu.'
                                <li '.$li_parent.' id="parent-'.$parent->menu_akses.'">
                                    <a href="'.site_url($urlparent).'">
                                        <i class="'.$parent->menu_icon.'"></i> <span>'.$parent->menu_nama.'</span>
                                        '.$menu_child.'
                                    </a>
                                </li>';
    			}
    		}

    		return $menu;
    }

    function get_total($parameter) {
        if(!empty($parameter)){
            $this->db->select('count(*) AS Total');
            $this->db->from('49_tc_user');
            $this->db->where($parameter);
            $query = $this->db->get();
            return (count($query->row_array()) > 0 ? $query->row()->Total : 0);
        }else{
            $this->db->select('count(*) AS Total');
            $this->db->from('49_tc_user');
            $query = $this->db->get();
            return (count($query->row_array()) > 0 ? $query->row()->Total : 0);
        }
    }

    function insert($data) {
        $this->db->insert('49_tc_user', $data);
    }

    function delete($id) {
        $this->db->where('user_id', $id);
        $this->db->delete('49_tc_user');
    }

    function reset($data) {
        $this->db->select('*');
        $this->db->from('49_tc_user');
        $this->db->where($data);
        $query = $this->db->get();
        return (count($query->row_array()) > 0 ? $query : NULL);;
    }

    function update($id, $data) {
        $this->db->where('user_id', $id);
        $this->db->update('49_tc_user', $data);
    }

    function select($data, $no) {
        $this->db->select('49_tc_user.*, 49_tc_role.role_name AS Role');
        $this->db->from('49_tc_user');
        $this->db->join('49_tc_role', '49_tc_user.user_role = 49_tc_role.role_id');
        $this->db->where($data);
        $this->db->limit($no);
        $query = $this->db->get();
        return (count($query->row_array()) > 0 ? $query : NULL);
    }
    
    function get_user($parameter) {
        $this->db->select('49_tc_user.*, 49_tc_role.role_name AS Role');
        $this->db->from('49_tc_user');
        $this->db->join('49_tc_role', '49_tc_user.user_role = 49_tc_role.role_id');
        $this->db->where($parameter);
        $query = $this->db->get();
        return (count($query->num_rows()) > 0 ? $query : NULL);
    }

    function get_userakses($parameter) {
        $this->db->select('49_tc_userakses.*, 49_tc_role.role_name AS Role');
        $this->db->from('49_tc_user_akses');
        $this->db->join('role', '49_tc_userakses.role_id = 49_tc_role.role_id');
        $this->db->where($parameter);
        $query = $this->db->get();
        return (count($query->num_rows()) > 0 ? $query : NULL);
    }

    function get_daftaruser($start, $rows, $search) {

        $sql = "SELECT
            `user`.`user_id` AS ID,
            `user`.`user_name` AS Username,
            `ukm`.`ukm_name` AS UKM,
            `user`.`user_created` AS Dibuat,
            `user`.`user_mail` AS Mail,
            `role`.`role_name` AS Role,
            REPLACE(REPLACE(`user`.`user_status`,'0','Nonaktif'),'1','Aktif') AS Status
        FROM `user`
        INNER JOIN `role` ON (`user`.`user_role` = `role`.`role_id`)
        INNER JOIN `ukm` ON (`user`.`ukm_id` = `ukm`.`ukm_id`)
        WHERE `user`.`user_id` LIKE '%".$search."%'
                OR `user`.`user_name` LIKE '%".$search."%'
                OR `ukm`.`ukm_name` LIKE '%".$search."%'
                OR REPLACE(REPLACE(`user`.`user_status`,'0','Nonaktif'),'1','Aktif') LIKE '%".$search."%'
                OR `user`.`user_created` LIKE '%".$search."%'
                OR `user`.`user_mail` LIKE '%".$search."%'
                OR `role`.`role_name` LIKE '%".$search."%'
        ORDER BY `user`.`user_created` DESC LIMIT ".$start.",".$rows."";

        return $this->db->query($sql);
    }

    function get_count_daftaruser($search) {

        $sql = "SELECT
            COUNT(*) AS Total
        FROM `user`
        INNER JOIN `role` ON (`user`.`user_role` = `role`.`role_id`)
        INNER JOIN `ukm` ON (`user`.`ukm_id` = `ukm`.`ukm_id`)
        WHERE `user`.`user_id` LIKE '%".$search."%'
                OR `user`.`user_name` LIKE '%".$search."%'
                OR `ukm`.`ukm_name` LIKE '%".$search."%'
                OR REPLACE(REPLACE(`user`.`user_status`,'0','Nonaktif'),'1','Aktif') LIKE '%".$search."%'
                OR `user`.`user_created` LIKE '%".$search."%'
                OR `user`.`user_mail` LIKE '%".$search."%'
                OR `role`.`role_name` LIKE '%".$search."%'";

        return $this->db->query($sql);
    }

}

?>
