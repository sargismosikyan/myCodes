<?php

class OrganisationAdminModel extends BaseModel
{
    public $id;
    public $organisation_id;
    public $email;
    public $name;

    public function __construct() {
        parent::__construct();
        $this->table = 'organisation_admins';
    }

    /**
     * Get All Schools
     * @return mixed
     * @param  $where array
     * @param  $columnsStr string
     * @return array or object
     */
    public function getAllOrganisations($where = array(), $columnsStr = null){
        if(empty($columnsStr)) {
            $this->db->select($this->table.'.*, organisations.short_code, organisations.name');
        }else{
            $this->db->select($columnsStr.', organisations.short_code, organisations.name');
        }
        $this->db->from($this->table);
        $this->db->join('organisations', $this->table.'.organisation_id = organisations.id', 'left');
        if(!empty($where)) {
            $this->db->where($where);
        }
        $query = $this->db->get();
        return !empty($where) ?  $query->row() : $query->result();
    }


    /**
     * Insert School
     * @param array $data
     * @return bool
     */
    public function insertAdmins($data){
        $this->db->insert_batch($this->table, $data);
        return $this->db->affected_rows() ? true : false;
    }

    /**
     * Insert School
     * @param array $data
     * @return bool
     */
    public function updateItem($data, $id){
        $this->db->where($this->table.'.id', $id);
        $this->db->update($this->table, $data);
        return $this->db->affected_rows() ? true : false;
    }

    /**
     * Delete Item
     * @param int id
     * @return bool
     */
    public function deleteItem($id){
        $this->db->where($this->table.'.id', $id);
        $this->db->delete($this->table);
        return $this->db->affected_rows() ? true : false;
    }

    /**
     * Get By Id
     * @param int id
     * @return bool
     */
    public function getById($id){
        $this->db->select($this->table.'.*, organisations.short_code, organisations.name as organisation_name');
        $this->db->from($this->table);
        $this->db->join('organisations', $this->table.'.organisation_id = organisations.id');
        $this->db->where($this->table.'.id', $id);
        return $this->db->get()->row();
    }

    /**
     * Get By organisationId
     * @param int $organisationId
     * @return mixed
     */
    public function getAllByOrganisationId($organisationId){
        $this->db->select($this->table.'.*, organisations.short_code, organisations.name as organisation_name');
        $this->db->from($this->table);
        $this->db->join('organisations', $this->table.'.organisation_id = organisations.id');
        $this->db->where($this->table.'.organisation_id', $organisationId);
        return $this->db->get()->result();
    }
}