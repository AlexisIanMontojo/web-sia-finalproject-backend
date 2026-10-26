<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RoomCategoriesModel extends Model
{
    protected $table = 'room_categories';
    protected $primary_key = 'id';

    protected $fillable = ['name', 'description', 'max_occupancy', 'amenities', 'is_active'];
    protected $guarded = ['id'];

    public function allOrdered()
    {
        return $this->db->table($this->table)->order_by('name', 'ASC')->get_all();
    }

    public function allActive()
    {
        return $this->db->table($this->table)
            ->where('is_active', 1)
            ->order_by('name', 'ASC')
            ->get_all();
    }

    public function countActive()
    {
        return (int) $this->db->table($this->table)->where('is_active', 1)->count();
    }

    public function nameTaken($name, $ignoreId = 0)
    {
        foreach ($this->db->table($this->table)->where('name', $name)->get_all() as $row) {
            if ((int) $row['id'] !== (int) $ignoreId) {
                return true;
            }
        }
        return false;
    }
}