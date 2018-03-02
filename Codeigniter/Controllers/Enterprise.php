<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Class Enterprise
 */
class Enterprise extends Private_Controller
{
    public $access_token;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('form_validation');
        $this->load->model('SchoolsModel');
        $this->load->model('OrganisationModel');
        $this->load->model('OrganisationAdminModel');
    }

    public function index()
    {
        $this->data['activeOrganisations'] = $this->OrganisationModel->getAllActiveOrganisations();
        $this->data['inactiveOrganisations'] = $this->OrganisationModel->getAllInactiveOrganisations();

        $this->layouts->add_includes('js', 'public/app/controllers/enterprise/index.js');
        $this->layouts->view('enterprise/index', $this->data, $this->layout);
    }

    public function getAllSchools()
    {
        $schools = [];
        if($this->input->is_ajax_request()){
            $schools = $this->SchoolsModel->getAllSchools();
        }

        print_r(json_encode($schools));
        exit;
    }

    public function getAllUnLinkedSchools()
    {
        $schools = [];
        if($this->input->is_ajax_request()){
            $schools = $this->SchoolsModel->getAllUnLinkedSchools();
        }

        print_r(json_encode($schools));
        exit;
    }

    public function getOrganisationDetails()
    {
        $response['organisation'] = NULL;
        $response['schools'] = [];
        if($this->input->is_ajax_request()){
            $organisation_id = $this->input->post('organisation_id', true);
            $response['organisation'] = $this->OrganisationModel->getOrganisationDetailsById($organisation_id);
            $response['schools'] = $this->SchoolsModel->getAllUnLinkedSchools();
        }
        print_r(json_encode($response));
        exit;
    }

    /**
     * Save Organisation data by ajax
     *
     * @access public
     * @return mixed
     */

    public function addNewOrganisation()
    {
        $response['errors'] = array();
        $response['warnings'] = array();
        $response['result']['status'] = false;
        if ($this->input->is_ajax_request()) {
            $this->config->set_item('language', $this->data['lang']);

            $this->setAdminsValidationForNewOrganisation(); // School Details
            $this->form_validation->set_rules('short_code', 'Organisation Short Code', 'trim|required|is_unique[organisations.short_code]');
            $this->form_validation->set_rules('name', 'Organisation Name', 'trim|required');

            if ($this->form_validation->run() == FALSE) {
                $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::INFO,
                    "message" => 'Form validation errors',
                    "status_code" => $status_code
                ));
                $response['errors'][] = $this->form_validation->error_array();
            }else{
                $mustRollback = false;
                try {
                    $this->db->trans_begin();
                    $short_code = $this->input->post('short_code', true);
                    $name = $this->input->post('name', true);
                    $admins = $this->input->post('admins', true);
                    $linkedSchoolIds = $this->input->post('linked_school_ids', true);
                    //Creating organisation
                    $insertOrganisationData = [
                        'short_code' => $short_code,
                        'name' => $name,
                        'status' => 1,
                        'db_base' => 'LLO_org_'.$short_code,
                        'db_server' => $this->db->hostname,
                        'db_user' => $this->db->username,
                        'db_password' => $this->db->password
                    ];
                    $insertedOrganisationId = $this->OrganisationModel->insertItem($insertOrganisationData);
                    if($insertedOrganisationId){ //TODO need to create enterprise database too
                        $organisationDetails = [
                            'short_code' => $short_code,
                            'organisation_name' => $name
                        ];

                        $this->_createDatabaseForOrganisation($insertOrganisationData['db_base'], $insertedOrganisationId);
                        $insertOrganisationData['organisation_id'] = $insertedOrganisationId;

                        // get organisation database access token
                        $this->getAccessToken($insertOrganisationData);

                        $response['_saveOrganisationSettings'] = $_saveOrganisationSettings = $this->_saveOrganisationSettings($insertOrganisationData);
                        if(!isset($_saveOrganisationSettings->status) || !$_saveOrganisationSettings->status){
                            $mustRollback = true;
                            return $response;
                        }

                        $this->SchoolsModel->linkOrganisationToSchools($insertedOrganisationId, $linkedSchoolIds);
                        //getting all linked schools
                        $linkedSchools = $this->SchoolsModel->getAllLinkedSchoolsByOrganisationId($insertedOrganisationId);
                        if(!empty($linkedSchools)){
                            $insertToOrganisationSchools = [];
                            foreach ($linkedSchools as $linkedSchool){
                                $insertToOrganisationSchools[] = [
                                    'school_id' => $linkedSchool->id,
                                    'school_code' => $linkedSchool->school_code,
                                    'school_name' => $linkedSchool->school_name,
                                    'time_zone' => $linkedSchool->time_zone,
                                ];
                            }

                            if(!empty($insertToOrganisationSchools)){
                                //Adding schools data into enterprise database
                                $response['_saveLinkedSchools'] =  $_saveLinkedSchools = $this->_saveLinkedSchools($organisationDetails, $insertToOrganisationSchools);
                                if(!isset($_saveLinkedSchools->status) || !$_saveLinkedSchools->status){
                                    $mustRollback = true;
                                    return $response;
                                }

                            }
                        }

                        //Adding admins
                        $insertOrganisationAdminData = [];
                        $adminEmails = [];
                        foreach ($admins as $admin){
                            if(!in_array($admin['email'], $adminEmails)){ //Cannot be duplicate email for organisation
                                $adminEmails[] = $admin['email'];
                                $insertOrganisationAdminData[] = [
                                    'organisation_id' => $insertedOrganisationId,
                                    'email' => $admin['email'],
                                    'name' => $admin['name'],
                                ];
                            }
                        }
                        if(!empty($insertOrganisationAdminData)){
                            $this->OrganisationAdminModel->insertAdmins($insertOrganisationAdminData);
                            //Adding admins into enterprise database
                            $admins = $this->OrganisationAdminModel->getAllByOrganisationId($insertedOrganisationId);
                            if(!empty($admins)){
                                $adminsData = [];
                                foreach ($admins as $admin){
                                    $adminsData[] = [
                                        'email' => $admin->email,
                                        'name' => $admin->name,
                                    ];
                                }
                                if(!empty($adminsData)){
                                    $response['_addAdmins'] = $_addAdmins = $this->_addAdmins($organisationDetails, $adminsData);
                                    if(!isset($_addAdmins->status) || !$_addAdmins->status){
                                        $mustRollback = true;
                                        return $response;
                                    }
                                }
                            }
                        }
                    }
                    $response['result']['status'] = true;
                    $response['result']['activeOrganisations'] = $this->OrganisationModel->getAllActiveOrganisations();
                    $this->db->trans_commit();

                } catch (Exception $e) {
                    $mustRollback = true;
//                    $this->db->trans_rollback();
                    $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                    $this->addLog(array(
                        "log_type_id" => LogTypesModel::ERROR,
                        "message" => $e->getMessage(),
                        "status_code" => $status_code
                    ));

                } finally {
                    if ($mustRollback) {
                        $this->db->trans_rollback();
                    }
                }
            }

        }
        print_r(json_encode($response));
        exit;
    }


    public function is_unique_for_organisation($email)
    {
        $organisation_id = $this->input->post('organisation_id');

        $this->db->select('id');
        $this->db->from('organisation_admins');
        $this->db->where('organisation_id', $organisation_id);
        $this->db->where('email', trim($email));
        $num = $this->db->get()->num_rows();
        if ($num > 0) {
            $this->form_validation->set_message('is_unique_for_organisation', 'Admin Email already exist in current organisation');
            return FALSE;
        } else {
            return TRUE;
        }
    }


    /**
     * Save Organisation data by ajax
     *
     * @access public
     * @return mixed
     */
    public function addNewAdminForOrganisation()
    {
        $response['errors'] = array();
        $response['warnings'] = array();
        $response['result']['status'] = false;
        if ($this->input->is_ajax_request()) {
            $this->config->set_item('language', $this->data['lang']);

            $this->form_validation->set_rules('organisation_id', 'Organisation', 'trim|required|callback_is_organisation_exist');
            $this->form_validation->set_rules('name', 'Admin Name', 'trim|required');
            $this->form_validation->set_rules('email', 'Admin Email', 'trim|required|valid_email|callback_is_unique_for_organisation');

            if ($this->form_validation->run() == FALSE) {
                $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::INFO,
                    "message" => 'Form validation errors',
                    "status_code" => $status_code
                ));
                $response['errors'][] = $this->form_validation->error_array();
            }else{
                $organisation_id = $this->input->post('organisation_id', true);
                $name = $this->input->post('name', true);
                $email = $this->input->post('email', true);
                $insertOrganisationAdminData[0] = [
                    'organisation_id' => $organisation_id,
                    'name' => $name,
                    'email' => $email,
                ];
                $result = $this->OrganisationAdminModel->insertAdmins($insertOrganisationAdminData);

                if($result){
                    $oldOrganisation = $this->OrganisationModel->getById($organisation_id);
                    $organisationDetails = [];
                    if(!empty($oldOrganisation)){
                        //getting access token
                        $organisationDetails['short_code'] = $oldOrganisation->short_code;
                        $this->getAccessToken(['short_code' => $oldOrganisation->short_code]); //need to get access token by old short_code
                    }

                    if(!empty($organisationDetails)){
                        $response['_addAdmins'] = $this->_addAdmins($organisationDetails, $insertOrganisationAdminData);
                    }

                    $response['result']['status'] = true;
                    $response['result']['admins'] = $this->OrganisationModel->getOrganisationAdminsById($organisation_id);
                }else{
                    $response['errors'][] = "Something went wrong when adding new admin for Organisation";
                }
                //Checking admin email and organisationID
            }

        }
        print_r(json_encode($response));
        exit;
    }

    /**
     * Save Organisation data by ajax
     *
     * @access public
     * @return mixed
     */
    public function editAdminForEditOrganisation()
    {
        $response['errors'] = array();
        $response['warnings'] = array();
        $response['result']['status'] = false;
        if ($this->input->is_ajax_request()) {
            $this->config->set_item('language', $this->data['lang']);
            $id = $this->input->post('id', true);
            $oldAdmin = $this->OrganisationAdminModel->getById($id);
            if(empty($oldAdmin)){
                $response['errors'][] = "Invalid Admin details";
                print_r(json_encode($response));
                exit;
            }
            $email = $this->input->post('email', true);
            $isUniqueEmail = '';
            if($oldAdmin->email != trim($email)){
                $isUniqueEmail = '|callback_is_unique_for_organisation';
            }

            $this->form_validation->set_rules('id', 'Admin Id', 'trim|required');
            $this->form_validation->set_rules('name', 'Admin Name', 'trim|required');
            $this->form_validation->set_rules('email', 'Admin Email', 'trim|required|valid_email'.$isUniqueEmail);

            if ($this->form_validation->run() == FALSE) {
                $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::INFO,
                    "message" => 'Form validation errors',
                    "status_code" => $status_code
                ));
                $response['errors'][] = $this->form_validation->error_array();
            }else{
                $name = $this->input->post('name', true);
                if($oldAdmin->email == trim($email) && $oldAdmin->name == trim($name)){
                    $response['warnings'][] = "No changes made to Administrator details";
                    print_r(json_encode($response));
                    exit;
                }
                $organisationDetails = [];
                $oldOrganisation = $this->OrganisationModel->getById($oldAdmin->organisation_id);
                if(!empty($oldOrganisation)){
                    //getting access token
                    $organisationDetails['short_code'] = $oldOrganisation->short_code;
                    $this->getAccessToken(['short_code' => $oldOrganisation->short_code]); //need to get access token by old short_code
                }
//                $organisation_id = $this->input->post('organisation_id', true);
                $updateOrganisationAdminData = [
                    'name' => trim($name),
                    'email' => trim($email)
                ];
                $result = $this->OrganisationAdminModel->updateItem($updateOrganisationAdminData, $id);
                //TODO need to update admin into enterprise database too
                if($result){
                    if(!empty($organisationDetails)){
                        $updateOrganisationAdminData['old_email'] = $oldAdmin->email;
                        $response['_editAdmin'] = $this->_editAdmin($organisationDetails, $updateOrganisationAdminData);
                    }
                    $response['result']['status'] = true;
//                    $response['result']['admins'] = $this->OrganisationModel->getOrganisationAdminsById($organisation_id);
                }else{
                    $response['errors'][] = "Something went wrong when adding new admin for Organisation";
                }
                //Checking admin email and organisationID
            }

        }
        print_r(json_encode($response));
        exit;
    }

    /**
     * Save Organisation data by ajax
     *
     * @access public
     * @return mixed
     */
    public function editOrganisation()
    {
        $response['errors'] = array();
        $response['warnings'] = array();
        $response['result']['status'] = false;
        if ($this->input->is_ajax_request()) {
            $this->config->set_item('language', $this->data['lang']);
            $id = $this->input->post('id', true);
            $short_code = $this->input->post('short_code', true);
            $name = $this->input->post('name', true);
            $oldOrganisation = $this->OrganisationModel->getById($id);
            $isUniqueShortCode = '';
            if(strtolower($oldOrganisation->short_code) != strtolower(trim($short_code))){
                $isUniqueShortCode = '|is_unique[organisations.short_code]';
            }

            $this->form_validation->set_rules('id', 'Organisation id', 'trim|required');
            $this->form_validation->set_rules('name', 'Organisation name', 'trim|required');
            $this->form_validation->set_rules('short_code', 'Organisation short code', 'trim|required'.$isUniqueShortCode);

            if ($this->form_validation->run() == FALSE) {
                $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::INFO,
                    "message" => 'Form validation errors',
                    "status_code" => $status_code
                ));
                $response['errors'][] = $this->form_validation->error_array();
            }else{

                if($oldOrganisation->short_code == trim($short_code) && $oldOrganisation->name == trim($name)){
                    $response['warnings'][] = "No any changes for ShortCode or Name of Organization";
                    print_r(json_encode($response));
                    exit;
                }

                //getting access token
                $this->getAccessToken(['short_code' => $oldOrganisation->short_code]); //need to get access token by old short_code

//                $organisation_id = $this->input->post('organisation_id', true);
                $updateOrganisationData = [
                    'name' => trim($name),
                    'short_code' => trim($short_code)
                ];
                $result = $this->OrganisationModel->updateItem($updateOrganisationData, $id);

                if($result){
                    //Updating Organisation database too
//                    $updateOrganisationData['organisation_id'] = $id;
//                    $updateOrganisationData['status'] = $oldOrganisation->status;
                    $this->_saveOrganisationSettings($updateOrganisationData);

                    $response['result']['status'] = true;
                    $response['result']['activeOrganisations'] = $this->OrganisationModel->getAllActiveOrganisations();
                    $response['result']['inactiveOrganisations'] =  $this->OrganisationModel->getAllInactiveOrganisations();
                }else{
                    $response['errors'][] = "Something went wrong when editing Organisation";
                }
                //Checking admin email and organisationID
            }

        }
        print_r(json_encode($response));
        exit;
    }

    /**
     * Save Organisation data by ajax
     *
     * @access public
     * @return mixed
     */
    public function deleteAdminForOrganisation()
    {
        $response['errors'] = array();
        $response['warnings'] = array();
        $response['result']['status'] = false;
        if ($this->input->is_ajax_request()) {
            $this->config->set_item('language', $this->data['lang']);

            $this->form_validation->set_rules('id', 'Admin ID', 'trim|required|is_natural_no_zero');

            if ($this->form_validation->run() == FALSE) {
                $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::INFO,
                    "message" => 'Form validation errors',
                    "status_code" => $status_code
                ));
                $response['errors'][] = $this->form_validation->error_array();
            }else{
                $id = $this->input->post('id', true);
                $admin = $this->OrganisationAdminModel->getById($id);
                $organisationDetails = [];
                if(!empty($admin)){
                    $oldOrganisation = $this->OrganisationModel->getById($admin->organisation_id);
                    if(!empty($oldOrganisation)){
                        //getting access token
                        $organisationDetails['short_code'] = $oldOrganisation->short_code;
                        $this->getAccessToken(['short_code' => $oldOrganisation->short_code]); //need to get access token by old short_code
                    }
                }
                $result = $this->OrganisationAdminModel->deleteItem($id);
                if($result){
                    if(!empty($organisationDetails)){
                        $response['_deleteAdmin'] = $this->_deleteAdmin($organisationDetails, $admin->email);
                    }
                    $response['result']['status'] = true;
                }else{
                    $response['errors'][] = "Something went wrong when deleting admin from Organisation";
                }
            }

        }
        print_r(json_encode($response));
        exit;
    }

    public function is_organisation_exist($id)
    {

        $this->db->select('id');
        $this->db->from('organisations');
        $this->db->where('organisations.id', $id);
        $result = $this->db->get()->row();
        if(empty($result)){
            $this->form_validation->set_message('is_organisation_exist', 'Organisation does not exists');
            return FALSE;
        }
        return TRUE;
    }

    public function is_status_right($status)
    {
        if($status == "0" || $status == "1"){
            return TRUE;
        }else{
            $this->form_validation->set_message('is_status_right', 'Organisation status is incorrect');
            return FALSE;
        }

    }

    /**
     * Save Organisation data by ajax
     *
     * @access public
     * @return mixed
     */

    public function enableDisableOrganisation()
    {
        $response['errors'] = array();
        $response['warnings'] = array();
        $response['result']['status'] = false;
        if ($this->input->is_ajax_request()) {
            $this->config->set_item('language', $this->data['lang']);

            $this->form_validation->set_rules('organisation_id', 'Organisation Id', 'trim|required|callback_is_organisation_exist');
            $this->form_validation->set_rules('status', 'Organisation Status', 'trim|required|is_natural|callback_is_status_right');

            if ($this->form_validation->run() == FALSE) {
                $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::INFO,
                    "message" => 'Form validation errors',
                    "status_code" => $status_code
                ));
                $response['errors'][] = $this->form_validation->error_array();
            }else{
                $organisation_id = $this->input->post('organisation_id', true);
                $oldOrganisation = $this->OrganisationModel->getById($organisation_id);

                //getting access token
                $this->getAccessToken(['short_code' => $oldOrganisation->short_code]); //need to get access token by old short_code

                $updateOrganisationData = [
                    'status' => $this->input->post('status', true)
                ];
                $result = $this->OrganisationModel->updateItem($updateOrganisationData, $organisation_id);

                if($result){
                    $updateOrganisationData['short_code'] = $oldOrganisation->short_code;
                    $this->_saveOrganisationSettings($updateOrganisationData);

                    $response['result']['status'] = true;
                    $response['result']['activeOrganisations'] = $this->OrganisationModel->getAllActiveOrganisations();
                    $response['result']['inactiveOrganisations'] = $this->OrganisationModel->getAllInactiveOrganisations();
                    $send_mail = $this->sendLLOAdminEmail($organisation_id, $updateOrganisationData['status']);
                    if($send_mail['errors']) {
                        $response['errors'][] = $send_mail['errors'];
                    } else {
                        $response['result']['send_mail'] = $send_mail['result']['message'];
                    }
                }else{
                    $response['errors'][] = "Something went wrong when updating status of Organisation";
                }
            }

        }
        print_r(json_encode($response));
        exit;
    }

    private function sendLLOAdminEmail($organisation_id = null, $activated)
    {
        $response['result']['status'] = false;
        $response['errors'] = [];

        if($organisation_id){
            $this->data['activated'] = $activated;
            $this->data['organisation'] = $this->OrganisationModel->getById($organisation_id);
            $this->data['schools'] = $this->SchoolsModel->getSchoolsByOrganisationId($organisation_id);
            if(!empty($this->data['organisation'])){
                $email_body = $this->load->view('enterprise/send_LLO_admin_email', $this->data, TRUE);
                $email_config = $this->config->item('email_config');

                $mail = new PHPMailer;
                //$mail->SMTPDebug = 3;                               // Enable verbose debug output
                $mail->isSMTP();                                      // Set mailer to use SMTP
                $mail->Host = $email_config['smtp_host'];             // Specify main and backup SMTP servers
                $mail->SMTPAuth = true;                               // Enable SMTP authentication
                $mail->Username = $email_config['smtp_user'];         // SMTP username
                $mail->Password = $email_config['smtp_pass'];         // SMTP password
                $mail->SMTPSecure = 'tls';                            // Enable TLS encryption, `ssl` also accepted
                $mail->Port = $email_config['smtp_port'];             // TCP port to connect to

                $email_to = $this->config->item('school_email_info')['LLO_countrynet_email'];
                $name_to =  $this->config->item('school_email_info')['LLO_countrynet_name'];

                $email_from_name = $this->config->item('school_email_info')['from_name'];
                $email_from_email = $this->config->item('school_email_info')['from_email'];
                $bcc_email = $this->config->item('school_email_info')['LLO_countrynet_email'];

                $mail->setFrom($email_from_email, $email_from_name);
                $mail->addAddress($email_to, $name_to);     // Add a recipient
                $mail->addBCC($bcc_email);// BCC: LLO@countrynet.net.au
                $mail->isHTML(true); // Set email format to HTML

                $mail->Subject = $activated ? 'LLO Organisation Activation' : 'LLO Organisation Deactivation';
                $mail->Body    = $email_body;
                $mail->AltBody = $email_body;

                if(!$mail->send()) {
                    $response['errors'][] = 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo;
                } else {
                    $response['result']['status'] = true;
                    $response['result']['message'] = 'Message to LLO Admin has been sent';
                }
            }else{
                $response['errors'][] = 'Invalid Organisation Details';
            }
            return $response;
        }
    }

    /**
     * De link school for Organisation
     *
     * @access public
     * @return mixed
     */

    public function deLinkSchoolForOrganisation()
    {
        $response['errors'] = array();
        $response['warnings'] = array();
        $response['result']['status'] = false;
        if ($this->input->is_ajax_request()) {
            $this->config->set_item('language', $this->data['lang']);

            $this->form_validation->set_rules('school_id', 'School ID', 'trim|required|is_natural_no_zero');

            if ($this->form_validation->run() == FALSE) {
                $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::INFO,
                    "message" => 'Form validation errors',
                    "status_code" => $status_code
                ));
                $response['errors'][] = $this->form_validation->error_array();
            }else{
                $school_id = $this->input->post('school_id', true);
                $school = $this->SchoolsModel->getById($school_id);
                $organisationDetails = [];
                if(!empty($school)){
                    $oldOrganisation = $this->OrganisationModel->getById($school->organisation_id);
                    if(!empty($oldOrganisation)){
                        //getting access token
                        $organisationDetails['short_code'] = $oldOrganisation->short_code;
                        $this->getAccessToken(['short_code' => $oldOrganisation->short_code]); //need to get access token by old short_code
                    }
                }


                $school_id = $this->input->post('school_id', true);
                $result = $this->SchoolsModel->deLinkSchoolForOrganisation($school_id);

                if($result){
                    if(!empty($organisationDetails)){
                        $this->_deLinkSchoolForOrganisation($school_id, $organisationDetails);
                    }

                    $response['result']['status'] = true;
                    $response['result']['unLinkedSchools'] = $this->SchoolsModel->getAllUnLinkedSchools();
                }else{
                    $response['errors'][] = "Something went wrong when de-linked school for organisation";
                }
            }

        }
        print_r(json_encode($response));
        exit;
    }

    /**
     * De link school for Organisation
     *
     * @access public
     * @return mixed
     */

    public function linkSchoolForOrganisation()
    {
        $response['errors'] = array();
        $response['warnings'] = array();
        $response['result']['status'] = false;
        if ($this->input->is_ajax_request()) {
            $this->config->set_item('language', $this->data['lang']);

            $this->form_validation->set_rules('school_id', 'School ID', 'trim|required|is_natural_no_zero');
            $this->form_validation->set_rules('organisation_id', 'Organisation ID', 'trim|required|is_natural_no_zero');

            if ($this->form_validation->run() == FALSE) {
                $status_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::INFO,
                    "message" => 'Form validation errors',
                    "status_code" => $status_code
                ));
                $response['errors'][] = $this->form_validation->error_array();
            }else{
                $school_id = $this->input->post('school_id', true);
                $organisation_id = $this->input->post('organisation_id', true);
                $organisationDetails = [];
                $oldOrganisation = $this->OrganisationModel->getById($organisation_id);
                if(!empty($oldOrganisation)){
                    //getting access token
                    $organisationDetails['short_code'] = $oldOrganisation->short_code;
                    $this->getAccessToken(['short_code' => $oldOrganisation->short_code]); //need to get access token by old short_code
                }
                $schoolInfo = [];
                $school = $this->SchoolsModel->getById($school_id);
                if(!empty($school)){
                    //getting access token
                    $schoolInfo = [
                        'school_id' => $school->id,
                        'school_name' => $school->school_name,
                        'school_code' => $school->school_code,
                        'time_zone' => $school->time_zone,
                    ];
                }
                $result = $this->SchoolsModel->linkOrganisationToSchools($organisation_id, $school_id);

                if($result){
                    if(!empty($schoolInfo) && !empty($organisationDetails)){
                        $this->_linkSchoolForOrganisation($schoolInfo, $organisationDetails);
                    }
                    $response['result']['status'] = true;
                    $response['result']['unLinkedSchools'] = $this->SchoolsModel->getAllUnLinkedSchools();
                }else{
                    $response['errors'][] = "Something went wrong when de-linked school for organisation";
                }
            }

        }
        print_r(json_encode($response));
        exit;
    }

    /**
     * Login as super admin
     *
     * @param - $short_code (string)
     */

    public function loginAsSuperAdmin($short_code = null)
    {
        if ($short_code) {
            $this->load->model('SuperAdminOrgAccessModel');
            $access_code = $this->generateRandomString(32);
            $insert_data = array(
                'user_id' => $this->userData->id,
                'short_code' => $short_code,
                'access_code' => $access_code,
                'expiry_time' => time() + (60 * 60 * 5), // expiry time after 5 hours
            );
            try {
                $result = $this->SuperAdminOrgAccessModel->insertItme($insert_data);
            } catch (Exception $e) {
                $staus_code = StatusCodesModel::HTTP_BAD_REQUEST;
                $this->addLog(array(
                    "log_type_id" => LogTypesModel::ERROR,
                    "message" => $e->getMessage(),
                    "status_code" => $staus_code
                ));
                $result = false;
            }

            if ($result) {
                redirect(LLO_ENTERPRISE_CLIENT_URL . 'auth/loginAsSuperAdmin/' . $short_code . '/' . $access_code);
                exit;
            } else {
                redirect('schools');
            }
        } else {
            redirect('schools');
        }
    }

    public function sendLoginDetailsEmail()
    {
        $response['result']['status'] = false;
        $response['errors'] = [];
        if($this->input->is_ajax_request()){
            $id = $this->input->post('id', true);
            $this->data['admin'] = $this->OrganisationAdminModel->getById($id);
            if(!empty($this->data['admin'])){
                $this->data['code'] = time().preg_replace('/[^a-zA-Z0-9]/', '', base64_encode(openssl_random_pseudo_bytes(24)));
                //TODO need to save this code into enterprise data for reset password
                $this->getAccessToken(['short_code' => $this->data['admin']->short_code]); //need to get access token by old short_code

                $access_data['email'] = $this->data['admin']->email;
                $access_data['code'] = $this->data['code'];
                $access_data['X_SHORT_CODE'] = $this->data['admin']->short_code;
                $this->rest->add_custom_data('X_CLIENT_IP_ADDRESS', $this->data['X_CLIENT_IP_ADDRESS']);
                $this->rest->initialize(array('server'=> LLO_ENTERPRISE_BASE_API_URL));
                $resetPassword = $this->rest->post('ajax/resetPassword', $access_data);

                if(isset($resetPassword->status) && $resetPassword->status){

                    $email_to = $this->data['admin']->email;
                    $name_to = $this->data['admin']->name;
                    $email_to_LLO_admin = $this->config->item('school_email_info')['LLO_countrynet_email'];
                    $name_to_LLO_admin = $this->config->item('school_email_info')['LLO_countrynet_name'];
                    $adminResult = $this->sendMail($email_to, $name_to);
                    $LLOAdminResult = $this->sendMail($email_to_LLO_admin, $name_to_LLO_admin);
                    if($adminResult) {
                        $response['errors'][] = $adminResult;
                    } else if($LLOAdminResult) {
                        $response['errors'][] = $LLOAdminResult;
                    } else {
                        $response['result']['status'] = true;
                        $response['result']['message'] = 'The login details message has been sent';
                    }
                }else{
                    $response['errors'][] = !empty($resetPassword->errors) ? $resetPassword->errors : "Oops... Something went wrong.";
                }
            }else{
                $response['errors'][] = 'Invalid Admin Details';
            }
        }
        print_r(json_encode($response));
        exit;
    }

    private function sendMail ($email_to = null, $name_to = null) {
        if($email_to && $name_to) {
            $email_body = $this->load->view('enterprise/send_login_details_email', $this->data, TRUE);

            $email_config = $this->config->item('email_config');

            $mail = new PHPMailer;
            //$mail->SMTPDebug = 3;                               // Enable verbose debug output
            $mail->isSMTP();                                      // Set mailer to use SMTP
            $mail->Host = $email_config['smtp_host'];             // Specify main and backup SMTP servers
            $mail->SMTPAuth = true;                               // Enable SMTP authentication
            $mail->Username = $email_config['smtp_user'];         // SMTP username
            $mail->Password = $email_config['smtp_pass'];         // SMTP password
            $mail->SMTPSecure = 'tls';                            // Enable TLS encryption, `ssl` also accepted
            $mail->Port = $email_config['smtp_port'];             // TCP port to connect to



            $email_from_name = $this->config->item('school_email_info')['from_name'];
            $email_from_email = $this->config->item('school_email_info')['from_email'];
            $bcc_email = $this->config->item('school_email_info')['LLO_countrynet_email'];

            $mail->setFrom($email_from_email, $email_from_name);
            $mail->addAddress($email_to, $name_to);     // Add a recipient
//            $mail->addBCC($bcc_email);// BCC: LLO@countrynet.net.au
            $mail->isHTML(true); // Set email format to HTML

            $mail->Subject = 'LLO Enterprise Details';
            $mail->Body    = $email_body;
            $mail->AltBody = $email_body;

            if(!$mail->send()) {
                return 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo;
            }
        }
    }

    /*
     * get access token for LLOAdmin
    * @access private
    * @param  array $organisationDetails
    */
    private function getAccessToken($organisationDetails){
        $params = $this->config->item('client_info');

        $LLO_admin = $this->config->item('LLO_admin');

        $params['username'] = $LLO_admin['username'];
        $params['password'] = $LLO_admin['password'];
        $params['X_SHORT_CODE'] = $organisationDetails['short_code'];
        $this->rest->initialize(array('server' => LLO_ENTERPRISE_BASE_API_URL));
        $result = $this->rest->post('oauth/access_token', $params);
        $this->access_token = isset($result->access_token) ? $result->access_token : null;
        return $result;
    }

    /**
     * Set form validation for School details
     *
     * @access private
     * @return mixed
     */

    private function setAdminsValidationForNewOrganisation()
    {
        $admins = $this->input->post('admins', true);
        $this->form_validation->set_rules('admins[0]', 'Administrators', 'required');
        if(!empty($admins)){
            foreach ($admins as $k => $admin){
                $this->form_validation->set_rules('admins[' . $k . '][name]', 'Admin Name', 'trim|required');
                $this->form_validation->set_rules('admins[' . $k . '][email]', 'Admin Email', 'trim|required|valid_email');
            }
        }
    }

    /**
     * Create database for organisation
     *
     * @access private
     * @param  string $db_name,
     * @param  int $organisationId
     * @return bool
     */

    private function _createDatabaseForOrganisation($db_name, $organisationId){
        $return = -1;
        $output = '';
        $DB_SRC_HOST = $this->db->hostname;
        $DB_SRC_USER = $this->db->username;
        $DB_SRC_PASS = $this->db->password;

        if($this->OrganisationModel->is_db_exists($db_name)){
            $db_name = $db_name.'_'.$organisationId;
            $this->OrganisationModel->updateItem(array('db_base' => $db_name), $organisationId);
        }
        $sqlCreateDb = "CREATE DATABASE ".$db_name." CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;";
        if ($this->db->query($sqlCreateDb))
        {
            $command = 'mysqldump -h '.$DB_SRC_HOST.' -u '.$DB_SRC_USER.' -p'.$DB_SRC_PASS.'  LLO_org_default | mysql -h '.$DB_SRC_HOST.' -u '.$DB_SRC_USER.' -p'.$DB_SRC_PASS.' '.$db_name;
            //$command = 'mysqldump -u user -ppass -olddb | mysql -u user -ppass -Dnewdb';
            exec($command, $output, $return);
        }
        return $return;
    }

    /**
     * Add admins
     *
     * @access private
     * @param  array $organisationDetails
     * @param  array $insert_data
     * @return object
     */

    private function _addAdmins($organisationDetails, $insert_data)
    {
        $params = [];
        $params['access_token'] = (isset($this->access_token) && $this->access_token) ? $this->access_token : '';
        $params['admins_data'] = $insert_data;
        $params['X_SHORT_CODE'] = $organisationDetails['short_code'];
        $this->rest->initialize(array('server' => LLO_ENTERPRISE_API_URL));
        $result = $this->rest->post('settings/add_admins', $params);
        return $result;
    }

    /**
     * Edit admins
     *
     * @access private
     * @param  array $organisationDetails
     * @param  array $updateData
     * @return mixed
     */

    private function _editAdmin($organisationDetails, $updateData)
    {
        $params = [];
        $params['access_token'] = (isset($this->access_token) && $this->access_token) ? $this->access_token : '';
        if(!isset($updateData['old_email'])){
            return ['status'=>false];
        }
        if(isset($updateData['email'])){
            $params['email'] = $updateData['email'];
        }
        if(isset($updateData['name'])){
            $params['name'] = $updateData['name'];
        }
        $params['old_email'] = $updateData['old_email'];
        $params['X_SHORT_CODE'] = $organisationDetails['short_code'];
        $this->rest->initialize(array('server' => LLO_ENTERPRISE_API_URL));
        $result = $this->rest->put('settings/edit_admin', $params);
        return $result;
    }
    /**
     * delete admin
     *
     * @access private
     * @param  array $organisationDetails
     * @param  string $adminEmail
     * @return object
     */

    private function _deleteAdmin($organisationDetails, $adminEmail)
    {
        $params = [];
        $params['access_token'] = (isset($this->access_token) && $this->access_token) ? $this->access_token : '';
        $params['email'] = $adminEmail;
        $params['X_SHORT_CODE'] = $organisationDetails['short_code'];
        $this->rest->initialize(array('server' => LLO_ENTERPRISE_API_URL));
        $result = $this->rest->delete('settings/delete_admin', $params);
        return $result;
    }

    /**
     * save school settings and Send invitation email
     *
     * @access private
     * @param  array $organisationDetails
     * @return mixed
     */

    private function _saveOrganisationSettings($organisationDetails)
    {
        $params = [];
        $params['access_token'] = (isset($this->access_token) && $this->access_token) ? $this->access_token : '';
        if(isset($organisationDetails['organisation_id'])){
            $params['organisation_id'] = $organisationDetails['organisation_id'];
        }
        if(isset($organisationDetails['status'])){
            $params['organisation_status'] = $organisationDetails['status'];
        }
        if(isset($organisationDetails['name'])){
            $params['organisation_name'] = $organisationDetails['name'];
        }
        $params['short_code'] = isset($organisationDetails['short_code']) ? $organisationDetails['short_code']: NULL;
        $params['X_SHORT_CODE'] = isset($organisationDetails['short_code']) ? $organisationDetails['short_code']: NULL;
        if(!$params['X_SHORT_CODE']){
            return ['status' => false];
        }

        $this->rest->initialize(array('server' => LLO_ENTERPRISE_API_URL));
        $result = $this->rest->post('settings/update_organisation_settings', $params);
        //removing cache from login page
        $this->rest->initialize(array('server' => LLO_ENTERPRISE_CLIENT_URL));
        $this->rest->post('auth/removeLoginPageSettingsCache', ['short_code' => $params['X_SHORT_CODE']]);
        return $result;
    }
    /**
     * save school settings and Send invitation email
     *
     * @access private
     * @param  int $schoolId
     * @param  array $organisationDetails
     * @return object
     */

    private function _deLinkSchoolForOrganisation($schoolId, $organisationDetails)
    {
        $params = [];
        $params['access_token'] = (isset($this->access_token) && $this->access_token) ? $this->access_token : '';
        $params['school_id'] = $schoolId;
        $params['X_SHORT_CODE'] = $organisationDetails['short_code'];
        $this->rest->initialize(array('server' => LLO_ENTERPRISE_API_URL));
        $result = $this->rest->post('settings/deLinkSchoolForOrganisation', $params);
        return $result;
    }
    /**
     * save school settings and Send invitation email
     *
     * @access private
     * @param  array $schoolInfo
     * @param  array $organisationDetails
     * @return object
     */

    private function _linkSchoolForOrganisation($schoolInfo, $organisationDetails)
    {
        $params = [];
        $params['access_token'] = (isset($this->access_token) && $this->access_token) ? $this->access_token : '';

        $params['school_id'] = !empty($schoolInfo['school_id']) ? $schoolInfo['school_id'] : NULL;
        $params['school_name'] = !empty($schoolInfo['school_name']) ? $schoolInfo['school_name'] : NULL;
        $params['school_code'] = !empty($schoolInfo['school_code']) ? $schoolInfo['school_code'] : NULL;

        $params['X_SHORT_CODE'] = $organisationDetails['short_code'];
        $this->rest->initialize(array('server' => LLO_ENTERPRISE_API_URL));
        $result = $this->rest->post('settings/linkSchoolForOrganisation', $params);
        return $result;
    }
    /**
     * save school settings and Send invitation email
     *
     * @access private
     * @param  array $organisationDetails
     * @param  array $insert_data
     * @return object
     */

    private function _saveLinkedSchools($organisationDetails, $insert_data)
    {
        $params = [];
        $params['access_token'] = (isset($this->access_token) && $this->access_token) ? $this->access_token : '';
        $params['schools_data'] = $insert_data;
        $params['X_SHORT_CODE'] = $organisationDetails['short_code'];
        $this->rest->initialize(array('server' => LLO_ENTERPRISE_API_URL));
        $result = $this->rest->post('settings/add_schools', $params);
        return $result;
    }

}
